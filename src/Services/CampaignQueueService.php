<?php

namespace App\Services;

use PDO;
use Exception;

/**
 * Campaign -> recipients -> queue -> Orange API -> logs (cahier des charges §5, §83).
 * Replaces the previous "loop over CSV rows inside one HTTP request" approach
 * (server/send_sms.php) with a claim-a-batch / process-a-batch model so a
 * campaign of thousands of recipients never depends on a single long-lived
 * HTTP request, and can be resumed after an interruption (§6).
 */
class CampaignQueueService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private PDO $pdo,
        private OrangeSmsService $orange
    ) {
    }

    public function createCampaign(int $organizationId, string $nom, string $description = '', string $type = 'generique', ?string $createdBy = null, int $batchSize = 50): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO campagne (nom, description, type, statut, batch_size, created_by, date_debut, date_fin, organization_id)
             VALUES (:nom, :description, :type, 'DRAFT', :batch_size, :created_by, NOW(), NOW() + INTERVAL '1 month', :organization_id)
             RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom,
            ':description' => $description,
            ':type' => $type,
            ':batch_size' => $batchSize,
            ':created_by' => $createdBy,
            ':organization_id' => $organizationId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Adds recipients to a DRAFT campaign. Invalid phone numbers are rejected
     * up front (§10); duplicates (same campaign+phone) are silently skipped
     * via the unique_key index (§7 idempotence) — a given phone number can
     * only appear once per campaign.
     *
     * @param array<array{destinataire:string, contenu:string, nom?:string, prenom?:string}> $rows
     * @return array{added:int, duplicates:int, invalid:int}
     */
    public function addRecipients(int $campaignId, array $rows): array
    {
        $added = 0;
        $duplicates = 0;
        $invalid = 0;

        // organization_id est repris de la campagne elle-même (sous-requête) plutôt
        // que passé en paramètre : addRecipients() est aussi appelé par des flux
        // internes (server/app.php::send_to_group) qui connaissent déjà l'id de
        // campagne mais n'ont pas à re-résoudre l'organisation courante.
        $insert = $this->pdo->prepare(
            "INSERT INTO messages (campagne_id, contenu, destinataire, nom, prenom, statut, unique_key, organization_id)
             VALUES (:campagne_id, :contenu, :destinataire, :nom, :prenom, 'en_attente', :unique_key,
                     (SELECT organization_id FROM campagne WHERE id = :campagne_id))
             ON CONFLICT (unique_key) WHERE unique_key IS NOT NULL DO NOTHING"
        );

        foreach ($rows as $row) {
            $phone = PhoneNumberService::normalize((string) ($row['destinataire'] ?? ''));
            $contenu = trim((string) ($row['contenu'] ?? ''));

            if ($phone === null || $contenu === '') {
                $invalid++;
                continue;
            }

            $uniqueKey = hash('sha256', $campaignId . '|' . $phone);

            $insert->execute([
                ':campagne_id' => $campaignId,
                ':contenu' => $contenu,
                ':destinataire' => $phone,
                ':nom' => $row['nom'] ?? null,
                ':prenom' => $row['prenom'] ?? null,
                ':unique_key' => $uniqueKey,
            ]);

            if ($insert->rowCount() > 0) {
                $added++;
            } else {
                $duplicates++;
            }
        }

        $this->pdo->prepare("UPDATE campagne SET total_destinataires = (SELECT COUNT(*) FROM messages WHERE campagne_id = :id) WHERE id = :id")
            ->execute([':id' => $campaignId]);

        return ['added' => $added, 'duplicates' => $duplicates, 'invalid' => $invalid];
    }

    public function queueCampaign(int $campaignId, bool $dryRun = false): void
    {
        $this->pdo->prepare("UPDATE campagne SET statut = 'QUEUED', dry_run = :dry_run WHERE id = :id")
            ->execute([':id' => $campaignId, ':dry_run' => $dryRun ? 't' : 'f']);
    }

    /**
     * Planifie un envoi différé (§30) : la campagne passe en SCHEDULED plutôt
     * que QUEUED, et n'est reprise par le worker qu'une fois promue en
     * QUEUED par promoteDueCampaigns() (appelée depuis bin/process-campaign.php
     * en mode démon et bin/promote-scheduled-campaigns.php pour un
     * déploiement cron classique) — jamais depuis le simple polling
     * navigateur de server/campaign_worker.php, qui ne s'exécute que si
     * quelqu'un a la page de détail ouverte.
     *
     * $when est converti en UTC avant stockage, quel que soit son fuseau
     * d'origine : la colonne `scheduled_at` (timestamp without time zone) est
     * comparée à `NOW()` dans promoteDueCampaigns(), et cette base
     * PostgreSQL a sa session en UTC (`SHOW timezone` = GMT) alors que PHP
     * tourne par défaut sur un autre fuseau (Europe/Berlin dans cet
     * environnement) — même piège déjà documenté et corrigé pour
     * `locked_until` dans AuthService::attempt(). Sans cette conversion,
     * une campagne programmée "dans 1 minute" avec l'heure murale PHP
     * pouvait apparaître jusqu'à plusieurs heures dans le futur pour
     * PostgreSQL et ne jamais être promue à l'heure prévue (bug trouvé en
     * testant promoteDueCampaigns() : une campagne programmée -1 minute
     * n'était pas promue).
     */
    public function schedule(int $campaignId, \DateTimeInterface $when, bool $dryRun = false): void
    {
        $utc = (clone $when)->setTimezone(new \DateTimeZone('UTC'));

        $this->pdo->prepare("UPDATE campagne SET statut = 'SCHEDULED', scheduled_at = :at, dry_run = :dry_run WHERE id = :id")
            ->execute([':id' => $campaignId, ':at' => $utc->format('Y-m-d H:i:s'), ':dry_run' => $dryRun ? 't' : 'f']);
    }

    /**
     * Annule la planification d'une campagne SCHEDULED, la ramenant en DRAFT
     * (pas de suppression des destinataires déjà ajoutés).
     */
    public function unschedule(int $campaignId): void
    {
        $this->pdo->prepare("UPDATE campagne SET statut = 'DRAFT', scheduled_at = NULL WHERE id = :id AND statut = 'SCHEDULED'")
            ->execute([':id' => $campaignId]);
    }

    /**
     * Fait passer en QUEUED toute campagne SCHEDULED dont l'heure est
     * arrivée — à appeler périodiquement par un worker/cron, jamais par une
     * requête HTTP utilisateur (§30 : le déclenchement doit être fiable même
     * si personne n'a l'application ouverte à l'heure prévue).
     *
     * @return int nombre de campagnes promues
     */
    public function promoteDueCampaigns(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE campagne SET statut = 'QUEUED' WHERE statut = 'SCHEDULED' AND scheduled_at <= NOW() RETURNING id"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    // ------------------------------------------------------------------
    // Automatisations : campagnes récurrentes (Phase 2)
    // ------------------------------------------------------------------

    private const RECURRENCE_FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /**
     * Attache une règle de récurrence à une campagne existante — celle-ci
     * garde son propre cycle de vie normal (DRAFT -> lancée/planifiée
     * manuellement une première fois comme d'habitude) ; la récurrence ne
     * gouverne que les occurrences FUTURES, générées par
     * processRecurringCampaigns(). $messageTemplate est le texte brut
     * ({{variables}} incluses) — nécessaire pour re-personnaliser le message
     * à chaque cycle, puisque messages.contenu ne stocke que la version déjà
     * rendue pour un contact donné.
     *
     * @throws Exception si la fréquence n'est pas daily/weekly/monthly.
     */
    public function configureRecurrence(int $campaignId, string $frequency, string $messageTemplate, string $audienceType, ?int $groupeId, ?int $segmentId): void
    {
        if (!in_array($frequency, self::RECURRENCE_FREQUENCIES, true)) {
            throw new Exception('Fréquence de récurrence invalide.');
        }

        $next = $this->computeNextOccurrence($frequency, new \DateTime('now', new \DateTimeZone('UTC')));

        $this->pdo->prepare(
            "UPDATE campagne SET recurrence = :freq, recurrence_audience_type = :audience_type,
             recurrence_groupe_id = :groupe_id, recurrence_segment_id = :segment_id,
             message_template = :template, next_occurrence_at = :next
             WHERE id = :id"
        )->execute([
            ':freq' => $frequency,
            ':audience_type' => $audienceType,
            ':groupe_id' => $groupeId,
            ':segment_id' => $segmentId,
            ':template' => $messageTemplate,
            ':next' => $next->format('Y-m-d H:i:s'),
            ':id' => $campaignId,
        ]);
    }

    public function stopRecurrence(int $campaignId): void
    {
        $this->pdo->prepare(
            "UPDATE campagne SET recurrence = NULL, next_occurrence_at = NULL WHERE id = :id"
        )->execute([':id' => $campaignId]);
    }

    private function computeNextOccurrence(string $frequency, \DateTimeInterface $from): \DateTime
    {
        $next = \DateTime::createFromInterface($from);
        switch ($frequency) {
            case 'daily':
                $next->modify('+1 day');
                break;
            case 'weekly':
                $next->modify('+7 days');
                break;
            case 'monthly':
                $next->modify('+1 month');
                break;
        }

        return $next;
    }

    /**
     * Génère et lance (si le solde le permet) la prochaine occurrence de
     * chaque campagne récurrente dont l'échéance est arrivée — à appeler
     * périodiquement par un worker/cron, comme promoteDueCampaigns(), et
     * pour la même raison : ça ne doit dépendre de personne ayant
     * l'application ouverte. Opère à travers toutes les organisations (pas
     * de session HTTP dans ce contexte), d'où l'instanciation directe de
     * ContactService/SegmentService avec l'organization_id de chaque
     * campagne trouvée plutôt que via les factories globales de
     * config/services.php (qui dépendent de auth()->organizationId()).
     *
     * @return list<int> ids des nouvelles campagnes générées (échecs — audience
     * vide, groupe/segment supprimé — silencieusement ignorés pour ce cycle,
     * l'échéance est quand même avancée pour ne pas boucler indéfiniment).
     */
    public function processRecurringCampaigns(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM campagne WHERE recurrence IS NOT NULL AND next_occurrence_at <= NOW()");
        $stmt->execute();
        $due = $stmt->fetchAll();

        $spawned = [];
        foreach ($due as $parent) {
            $newId = $this->spawnOccurrence($parent);
            if ($newId !== null) {
                $spawned[] = $newId;
            }

            $next = $this->computeNextOccurrence($parent['recurrence'], new \DateTime($parent['next_occurrence_at'], new \DateTimeZone('UTC')));
            $this->pdo->prepare("UPDATE campagne SET next_occurrence_at = :next WHERE id = :id")
                ->execute([':next' => $next->format('Y-m-d H:i:s'), ':id' => $parent['id']]);
        }

        return $spawned;
    }

    private function spawnOccurrence(array $parent): ?int
    {
        $orgId = (int) $parent['organization_id'];
        $contacts = new ContactService($this->pdo, $orgId);
        $segments = new SegmentService($this->pdo, $orgId);

        if ($parent['recurrence_audience_type'] === 'segment' && $parent['recurrence_segment_id']) {
            $audience = $segments->resolveContacts((int) $parent['recurrence_segment_id']);
        } else {
            $groupeId = ($parent['recurrence_audience_type'] === 'group' && $parent['recurrence_groupe_id'])
                ? (int) $parent['recurrence_groupe_id']
                : null;
            $audience = $contacts->allContacts($groupeId);
        }

        if (empty($audience)) {
            return null;
        }

        $rows = [];
        foreach ($audience as $c) {
            $rendered = MessageTemplateService::render((string) $parent['message_template'], [
                'nom' => $c['nom'], 'prenom' => $c['prenom'], 'telephone' => $c['telephone'], 'email' => $c['email'],
            ]);
            $rows[] = ['destinataire' => $c['telephone'], 'contenu' => $rendered['message'], 'nom' => $c['nom'], 'prenom' => $c['prenom']];
        }

        $newId = $this->createCampaign($orgId, $parent['nom'] . ' (auto)', (string) ($parent['description'] ?? ''), 'automatisation', $parent['created_by']);
        $this->addRecipients($newId, $rows);

        // §20 : une occurrence automatique ne s'auto-lance jamais si le solde
        // ne suit pas — elle reste en DRAFT (visible dans la liste des
        // campagnes) plutôt que d'échouer destinataire par destinataire.
        $needed = 0;
        foreach ($rows as $row) {
            $needed += SmsCounterService::analyze($row['contenu'])['segments'];
        }
        $available = (int) ($this->orange->getBalance()['availableUnits'] ?? 0);
        if ($needed <= $available) {
            $this->queueCampaign($newId, false);
        }

        return $newId;
    }

    public function pause(int $campaignId): void
    {
        $this->pdo->prepare("UPDATE campagne SET statut = 'PAUSED' WHERE id = :id AND statut = 'RUNNING'")
            ->execute([':id' => $campaignId]);
    }

    public function resume(int $campaignId): void
    {
        $this->pdo->prepare("UPDATE campagne SET statut = 'QUEUED' WHERE id = :id AND statut = 'PAUSED'")
            ->execute([':id' => $campaignId]);
    }

    public function cancel(int $campaignId): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->prepare("UPDATE campagne SET statut = 'CANCELLED', date_completion = NOW() WHERE id = :id")
            ->execute([':id' => $campaignId]);
        // Recipients already processed keep their status (history is preserved, §20).
        $this->pdo->prepare("UPDATE messages SET statut = 'annule' WHERE campagne_id = :id AND statut IN ('en_attente','en_cours')")
            ->execute([':id' => $campaignId]);
        $this->pdo->commit();
    }

    /**
     * Marks only the retryable failures of a campaign back to 'en_attente' (§21).
     */
    public function retryFailed(int $campaignId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE messages SET statut = 'en_attente', locked_at = NULL
             WHERE campagne_id = :id AND statut = 'echec' AND error_code = ANY(:codes) AND tentative_count < :max
             RETURNING id"
        );
        $stmt->execute([
            ':id' => $campaignId,
            ':codes' => '{' . implode(',', SmsErrorClassifier::RETRYABLE_CODES) . '}',
            ':max' => self::MAX_ATTEMPTS,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Claims up to $batchSize pending recipients using SELECT ... FOR UPDATE
     * SKIP LOCKED (§55) so two workers can never process the same recipient,
     * then immediately releases the transaction — the actual HTTP calls to
     * Orange happen outside any open DB transaction (§53).
     */
    public function claimBatch(int $campaignId, int $batchSize): array
    {
        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare(
            "SELECT id, destinataire, contenu, tentative_count, organization_id
             FROM messages
             WHERE campagne_id = :id AND statut = 'en_attente'
             ORDER BY id
             LIMIT :limit
             FOR UPDATE SKIP LOCKED"
        );
        $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->pdo->prepare("UPDATE messages SET statut = 'en_cours', locked_at = NOW() WHERE id IN ($placeholders)");
            $update->execute($ids);
        }

        $this->pdo->commit();

        return $rows;
    }

    /**
     * Sends one claimed recipient and records the outcome. Never throws —
     * failures are classified and stored (§8) so the caller can just move on.
     */
    public function processRecipient(array $recipient, int $campaignId, bool $dryRun): void
    {
        $id = $recipient['id'];
        $phone = $recipient['destinataire'];
        $message = $recipient['contenu'];

        try {
            if ($dryRun) {
                $providerMessageId = 'DRY-RUN';
            } else {
                $response = $this->orange->sendSms($phone, $message);
                $providerMessageId = $response['outboundSMSMessageRequest']['resourceReference']['resourceURL'] ?? null;
            }

            $this->pdo->prepare(
                "UPDATE messages SET statut = 'envoye', date_traitement = NOW(), provider_message_id = :pmid,
                 tentative_count = tentative_count + 1 WHERE id = :id"
            )->execute([':pmid' => $providerMessageId, ':id' => $id]);

            $this->pdo->prepare("UPDATE campagne SET nombre_envoyes = nombre_envoyes + 1 WHERE id = :id")
                ->execute([':id' => $campaignId]);

            // Crédits (Phase 2) : débité seulement pour un envoi réel réussi,
            // jamais en dry_run (aucun SMS réel, aucun coût réel) et jamais
            // pour un échec (voir la branche catch). Le solde interne par
            // organisation existe pour un motif distinct du solde Orange
            // partagé : plusieurs organisations envoient via le même compte
            // Orange (§59), rien d'autre n'empêcherait l'une d'épuiser le
            // solde partagé au détriment des autres.
            if (!$dryRun && isset($recipient['organization_id'])) {
                $credits = new CreditService($this->pdo, (int) $recipient['organization_id']);
                $credits->recordConsumption(
                    SmsCounterService::analyze($message)['segments'],
                    "Campagne #$campaignId",
                    $campaignId
                );
            }
        } catch (Exception $e) {
            [$code, $retryable] = SmsErrorClassifier::classify($e);
            $attempts = (int) $recipient['tentative_count'] + 1;
            $finalStatus = ($retryable && $attempts < self::MAX_ATTEMPTS) ? 'en_attente' : 'echec';

            $this->pdo->prepare(
                "UPDATE messages SET statut = :statut, error_code = :code, error_message = :msg,
                 tentative_count = :attempts, locked_at = NULL,
                 date_traitement = CASE WHEN :statut2 = 'echec' THEN NOW() ELSE date_traitement END
                 WHERE id = :id"
            )->execute([
                ':statut' => $finalStatus,
                ':statut2' => $finalStatus,
                ':code' => $code,
                ':msg' => substr($e->getMessage(), 0, 2000),
                ':attempts' => $attempts,
                ':id' => $id,
            ]);

            if ($finalStatus === 'echec') {
                $this->pdo->prepare("UPDATE campagne SET nombre_echecs = nombre_echecs + 1 WHERE id = :id")
                    ->execute([':id' => $campaignId]);
            }
        }
    }

    /**
     * Aggregate progress for the UI (§6, §19). Also flips the campaign to
     * COMPLETED/PARTIAL once nothing is left pending/processing.
     */
    public function getProgress(int $campaignId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT statut, COUNT(*) AS n FROM messages WHERE campagne_id = :id GROUP BY statut"
        );
        $stmt->execute([':id' => $campaignId]);
        $counts = array_fill_keys(['en_attente', 'en_cours', 'envoye', 'echec', 'annule'], 0);
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['statut']] = (int) $row['n'];
        }

        $total = array_sum($counts);
        $done = $counts['envoye'] + $counts['echec'] + $counts['annule'];
        $remaining = $counts['en_attente'] + $counts['en_cours'];

        if ($remaining === 0 && $total > 0) {
            $finalStatus = $counts['echec'] > 0 ? 'PARTIAL' : 'COMPLETED';
            $this->pdo->prepare(
                "UPDATE campagne SET statut = :s, date_lancement = COALESCE(date_lancement, NOW()), date_completion = COALESCE(date_completion, NOW())
                 WHERE id = :id AND statut IN ('QUEUED', 'RUNNING')"
            )->execute([':s' => $finalStatus, ':id' => $campaignId]);
        } elseif ($remaining > 0) {
            $this->pdo->prepare("UPDATE campagne SET statut = 'RUNNING', date_lancement = COALESCE(date_lancement, NOW()) WHERE id = :id AND statut IN ('QUEUED','RUNNING')")
                ->execute([':id' => $campaignId]);
        }

        return [
            'total' => $total,
            'pending' => $counts['en_attente'],
            'processing' => $counts['en_cours'],
            'sent' => $counts['envoye'],
            'failed' => $counts['echec'],
            'cancelled' => $counts['annule'],
            'done' => $remaining === 0,
        ];
    }
}
