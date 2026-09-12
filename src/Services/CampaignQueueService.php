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

    public function createCampaign(string $nom, string $description = '', string $type = 'generique', ?string $createdBy = null, int $batchSize = 50): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO campagne (nom, description, type, statut, batch_size, created_by, date_debut, date_fin)
             VALUES (:nom, :description, :type, 'DRAFT', :batch_size, :created_by, NOW(), NOW() + INTERVAL '1 month')
             RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom,
            ':description' => $description,
            ':type' => $type,
            ':batch_size' => $batchSize,
            ':created_by' => $createdBy,
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

        $insert = $this->pdo->prepare(
            "INSERT INTO messages (campagne_id, contenu, destinataire, nom, prenom, statut, unique_key)
             VALUES (:campagne_id, :contenu, :destinataire, :nom, :prenom, 'en_attente', :unique_key)
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
            "SELECT id, destinataire, contenu, tentative_count
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
