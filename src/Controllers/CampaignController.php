<?php

namespace App\Controllers;

use App\Core\Controller;
use DateTime;
use DateTimeZone;
use Exception;

class CampaignController extends Controller
{
    public function index(): void
    {
        $this->view('campaigns/index', [
            'campagnes' => getCampagne(auth()->organizationId()),
        ]);
    }

    public function show(): void
    {
        $campagneId = (int) ($_GET['id'] ?? 0);
        $campagne = $campagneId > 0 ? getSingleCampagne($campagneId) : null;
        // §59 : une campagne_id valide mais appartenant à une autre organisation
        // doit être traitée comme "introuvable", pas affichée (IDOR sinon).
        if (!$campagne || (int) $campagne['organization_id'] !== auth()->organizationId()) {
            $this->view('campaigns/show', ['campagne' => null]);
            return;
        }

        $messages = getMessageCampagne($campagne['id']);
        $isActive = in_array($campagne['statut'], ['QUEUED', 'RUNNING'], true);
        $isDraft = $campagne['statut'] === 'DRAFT';
        $isScheduled = $campagne['statut'] === 'SCHEDULED';
        $isFinished = in_array($campagne['statut'], ['COMPLETED', 'PARTIAL', 'FAILED', 'CANCELLED'], true);
        $badgeClass = [
            'DRAFT' => 'bg-secondary', 'SCHEDULED' => 'bg-primary', 'QUEUED' => 'bg-info', 'RUNNING' => 'bg-primary',
            'PAUSED' => 'bg-warning', 'COMPLETED' => 'bg-success', 'PARTIAL' => 'bg-warning',
            'FAILED' => 'bg-danger', 'CANCELLED' => 'bg-dark',
        ][$campagne['statut']] ?? 'bg-secondary';

        $isRecurring = !empty($campagne['recurrence']);
        $recurrenceLabels = ['daily' => 'tous les jours', 'weekly' => 'toutes les semaines', 'monthly' => 'tous les mois'];

        $hasRecipients = (int) $campagne['total_destinataires'] > 0;

        $smsNeeded = null;
        $balanceInfo = null;
        $balanceError = null;
        if (($isDraft && $hasRecipients) || $isScheduled) {
            $smsNeeded = estimateSmsNeeded($campagne['id']);
            try {
                $balanceInfo = orangeSms()->getBalance();
            } catch (Exception $e) {
                $balanceError = $e->getMessage();
            }
        }
        $availableUnits = $balanceInfo['availableUnits'] ?? null;
        $orgCreditsBalance = $smsNeeded !== null ? credits()->balance() : null;
        $balanceSufficient = $availableUnits !== null && $smsNeeded !== null
            ? ((int) $availableUnits >= $smsNeeded && $orgCreditsBalance >= $smsNeeded)
            : null;

        $groupesDisponibles = [];
        $templatesDisponibles = [];
        $totalContactsOrg = 0;
        $segmentsDisponibles = [];
        if ($isDraft && !$hasRecipients) {
            $groupesDisponibles = contacts()->allGroups();
            $templatesDisponibles = smsTemplates()->all();
            $totalContactsOrg = contacts()->countContacts();
            $segmentsDisponibles = segments()->all();
        }

        $orgFuseauHoraire = 'Africa/Conakry';
        if ($isDraft && $hasRecipients) {
            $orgFuseauHoraire = organizations()->find(auth()->organizationId())['fuseau_horaire'] ?? $orgFuseauHoraire;
        }

        $this->view('campaigns/show', compact(
            'campagne', 'messages', 'isActive', 'isDraft', 'isScheduled', 'isFinished', 'badgeClass',
            'isRecurring', 'recurrenceLabels', 'hasRecipients', 'smsNeeded', 'balanceError', 'availableUnits',
            'orgCreditsBalance', 'balanceSufficient', 'groupesDisponibles', 'templatesDisponibles',
            'totalContactsOrg', 'segmentsDisponibles', 'orgFuseauHoraire'
        ));
    }

    public function create(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $nom = trim($_POST['campagne_name']);
        $description = trim($_POST['campagne_description']);

        if (empty($nom)) {
            $this->flash('alert alert-warning', 'Le nom de la campagne est obligatoire ');
            $this->redirectBack();
        }

        $campagneId = campaignQueue()->createCampaign(auth()->organizationId(), $nom, $description, 'generique', 'admin', 50);
        activityLog()->log('creation_campagne', $campagneId, $this->actor(), $nom);
        $this->flash('alert alert-success', '✅ Campagne créée avec succès. Importez maintenant vos destinataires.');
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function importExcel(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        if (!isset($_FILES['excelFile']) || !isset($_POST['campagne_id'])) {
            http_response_code(400);
            exit('Requête invalide.');
        }

        $campagneId = (int) $_POST['campagne_id'];
        $campagne = assertOwnsCampagne($campagneId);

        if ($campagne['statut'] !== 'DRAFT') {
            $this->flash('alert alert-danger', '❌ Impossible d\'importer : campagne introuvable ou déjà lancée.');
            $this->redirectBack();
        }

        $file = $_FILES['excelFile'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $maxSize = 10 * 1024 * 1024;

        if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['xlsx', 'xls'], true) || $file['size'] > $maxSize) {
            $this->flash('alert alert-danger', '❌ Fichier invalide : un .xlsx ou .xls de moins de 10 Mo est attendu.');
            $this->redirectBack();
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, false);
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ Impossible de lire le fichier Excel : ' . $e->getMessage());
            $this->redirectBack();
        }

        if (empty($data)) {
            $this->flash('alert alert-warning', 'Le fichier est vide.');
            $this->redirectBack();
        }

        $headers = array_map(fn($h) => strtolower(trim((string) $h)), array_shift($data));
        $colIndex = array_flip($headers);
        $required = ['nom', 'prenom', 'telephone', 'message'];
        $missingCols = array_diff($required, array_keys($colIndex));

        if (!empty($missingCols)) {
            $this->flash('alert alert-danger', '❌ Colonnes manquantes dans le fichier : ' . implode(', ', $missingCols) . '. Attendu : nom, prenom, telephone, message.');
            $this->redirectBack();
        }

        $rows = [];
        foreach ($data as $line) {
            $rows[] = [
                'nom' => trim((string) ($line[$colIndex['nom']] ?? '')),
                'prenom' => trim((string) ($line[$colIndex['prenom']] ?? '')),
                'destinataire' => trim((string) ($line[$colIndex['telephone']] ?? '')),
                'contenu' => trim((string) ($line[$colIndex['message']] ?? '')),
            ];
        }

        $result = campaignQueue()->addRecipients($campagneId, $rows);

        $this->flash('alert alert-success', "✅ Import terminé : {$result['added']} destinataire(s) ajouté(s), {$result['duplicates']} doublon(s) ignoré(s), {$result['invalid']} ligne(s) invalide(s) (numéro ou message manquant).");
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function addRecipientsFromAudience(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) ($_POST['campagne_id'] ?? 0);
        $campagne = assertOwnsCampagne($campagneId);

        if ($campagne['statut'] !== 'DRAFT') {
            $this->flash('alert alert-danger', '❌ Impossible d\'ajouter des destinataires : campagne déjà lancée.');
            $this->redirect('campaigns.show', ['id' => $campagneId]);
        }

        $audienceType = $_POST['audience_type'] ?? 'all';
        $groupeId = ($audienceType === 'group' && !empty($_POST['groupe_id'])) ? (int) $_POST['groupe_id'] : null;
        $segmentId = ($audienceType === 'segment' && !empty($_POST['segment_id'])) ? (int) $_POST['segment_id'] : null;
        $message = trim($_POST['campaign_message'] ?? '');

        if ($message === '') {
            $this->flash('alert alert-warning', 'Le message est obligatoire.');
            $this->redirect('campaigns.show', ['id' => $campagneId]);
        }

        $audience = $segmentId !== null ? segments()->resolveContacts($segmentId) : contacts()->allContacts($groupeId);
        if (empty($audience)) {
            $this->flash('alert alert-warning', 'Aucun contact dans cette audience.');
            $this->redirect('campaigns.show', ['id' => $campagneId]);
        }

        $rows = [];
        foreach ($audience as $c) {
            $rendered = \App\Services\MessageTemplateService::render($message, [
                'nom' => $c['nom'],
                'prenom' => $c['prenom'],
                'telephone' => $c['telephone'],
                'email' => $c['email'],
            ]);
            $rows[] = [
                'destinataire' => $c['telephone'],
                'contenu' => $rendered['message'],
                'nom' => $c['nom'],
                'prenom' => $c['prenom'],
            ];
        }

        $audienceLabel = $segmentId !== null ? "segment #$segmentId" : ($groupeId !== null ? "groupe #$groupeId" : 'tous les contacts');
        $result = campaignQueue()->addRecipients($campagneId, $rows);
        activityLog()->log('ajout_destinataires_campagne', $campagneId, $this->actor(), "{$result['added']} ajouté(s) depuis $audienceLabel");

        $recurrenceMessage = '';
        if (!empty($_POST['recurrence_enabled']) && !empty($_POST['recurrence'])) {
            try {
                campaignQueue()->configureRecurrence($campagneId, $_POST['recurrence'], $message, $audienceType, $groupeId, $segmentId);
                $recurrenceMessage = ' Renouvellement automatique activé.';
            } catch (Exception $e) {
                $recurrenceMessage = ' ⚠️ Récurrence non activée : ' . $e->getMessage();
            }
        }

        $this->flash('alert alert-success', "✅ {$result['added']} destinataire(s) ajouté(s), {$result['duplicates']} doublon(s) ignoré(s)." . $recurrenceMessage);
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function stopRecurrence(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) ($_POST['campagne_id'] ?? 0);
        assertOwnsCampagne($campagneId);
        campaignQueue()->stopRecurrence($campagneId);
        activityLog()->log('arret_recurrence_campagne', $campagneId, $this->actor());
        $this->flash('alert alert-success', '✅ Renouvellement automatique arrêté.');
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function sendTestSms(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) ($_POST['campagne_id'] ?? 0);
        assertOwnsCampagne($campagneId);

        $numero = trim($_POST['test_numero'] ?? '');
        $pattern = "/^(\+224|00224)6\d{8}$/";

        if (!preg_match($pattern, $numero)) {
            $this->flash('alert alert-danger', '❌ Numéro de test invalide (format attendu : +224XXXXXXXXX).');
            $this->redirect('campaigns.show', ['id' => $campagneId]);
        }

        $stmt = db()->prepare("SELECT contenu FROM messages WHERE campagne_id = :id ORDER BY id LIMIT 1");
        $stmt->execute([':id' => $campagneId]);
        $contenu = $stmt->fetchColumn();

        if (!$contenu) {
            $this->flash('alert alert-warning', 'Ajoutez d\'abord des destinataires avant d\'envoyer un SMS de test.');
            $this->redirect('campaigns.show', ['id' => $campagneId]);
        }

        try {
            orangeSms()->sendSms($numero, $contenu);
            activityLog()->log('test_sms_campagne', $campagneId, $this->actor(), "test envoyé à $numero");
            $this->flash('alert alert-success', "✅ SMS de test envoyé à $numero.");
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ Échec de l\'envoi du test : ' . $e->getMessage());
        }
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function launch(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) $_POST['campagne_id'];
        assertOwnsCampagne($campagneId);
        $dryRun = isset($_POST['dry_run']);

        if (!$dryRun) {
            $balanceError = insufficientBalanceMessage($campagneId);
            if ($balanceError !== null) {
                $this->flash('alert alert-danger', $balanceError);
                $this->redirect('campaigns.show', ['id' => $campagneId]);
            }
        }

        campaignQueue()->queueCampaign($campagneId, $dryRun);
        activityLog()->log('lancement_campagne', $campagneId, $this->actor(), $dryRun ? 'dry_run' : null);
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function schedule(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) $_POST['campagne_id'];
        assertOwnsCampagne($campagneId);
        $dryRun = isset($_POST['dry_run']);

        $orgTimezone = organizations()->find(auth()->organizationId())['fuseau_horaire'] ?? 'Africa/Conakry';
        $scheduledAt = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($_POST['scheduled_at'] ?? ''), new DateTimeZone($orgTimezone));
        if (!$scheduledAt || $scheduledAt <= new DateTime()) {
            $this->flash('alert alert-warning', 'Choisissez une date et une heure dans le futur.');
            $this->redirect('campaigns.show', ['id' => $campagneId]);
        }

        if (!$dryRun) {
            $balanceError = insufficientBalanceMessage($campagneId);
            if ($balanceError !== null) {
                $this->flash('alert alert-danger', $balanceError);
                $this->redirect('campaigns.show', ['id' => $campagneId]);
            }
        }

        campaignQueue()->schedule($campagneId, $scheduledAt, $dryRun);
        activityLog()->log('planification_campagne', $campagneId, $this->actor(), $scheduledAt->format('d/m/Y H:i'));
        $this->flash('alert alert-success', '📅 Campagne programmée pour le ' . $scheduledAt->format('d/m/Y à H:i') . '.');
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function unschedule(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) $_POST['campagne_id'];
        assertOwnsCampagne($campagneId);
        campaignQueue()->unschedule($campagneId);
        activityLog()->log('annulation_planification_campagne', $campagneId, $this->actor());
        $this->flash('alert alert-success', '✅ Planification annulée, la campagne est repassée en brouillon.');
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function pause(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) $_POST['campagne_id'];
        assertOwnsCampagne($campagneId);
        campaignQueue()->pause($campagneId);
        activityLog()->log('pause_campagne', $campagneId, $this->actor());
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function resume(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) $_POST['campagne_id'];
        assertOwnsCampagne($campagneId);
        campaignQueue()->resume($campagneId);
        activityLog()->log('reprise_campagne', $campagneId, $this->actor());
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function cancel(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) $_POST['campagne_id'];
        assertOwnsCampagne($campagneId);
        campaignQueue()->cancel($campagneId);
        activityLog()->log('annulation_campagne', $campagneId, $this->actor());
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    public function retryFailures(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $campagneId = (int) $_POST['campagne_id'];
        assertOwnsCampagne($campagneId);
        $n = campaignQueue()->retryFailed($campagneId);
        campaignQueue()->queueCampaign($campagneId);
        activityLog()->log('retry_campagne', $campagneId, $this->actor(), "$n message(s) remis en file");
        $this->flash('alert alert-success', "🔁 $n échec(s) remis en file d'attente.");
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }

    /**
     * Batch worker endpoint, polled (AJAX) by the campaign progress screen.
     * Each call claims and processes ONE batch, then returns — never loops
     * over the whole campaign inside a single HTTP request (§5, §34).
     * Replaces server/campaign_worker.php.
     */
    public function poll(): void
    {
        $campaignId = filter_input(INPUT_GET, 'campagne_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'campagne_id', FILTER_VALIDATE_INT);

        if (!$campaignId) {
            $this->json(['error' => 'campagne_id manquant ou invalide'], 400);
        }

        $campagne = getSingleCampagne($campaignId);
        if (!$campagne || (int) $campagne['organization_id'] !== auth()->organizationId()) {
            $this->json(['error' => 'Campagne introuvable'], 404);
        }

        if (!in_array($campagne['statut'], ['QUEUED', 'RUNNING'], true)) {
            $this->json(['error' => null] + campaignQueue()->getProgress($campaignId) + ['statut' => $campagne['statut']]);
        }

        $batchSize = max(1, min(200, (int) ($campagne['batch_size'] ?? 50)));
        $dryRun = ($campagne['dry_run'] ?? false) === true || $campagne['dry_run'] === 't';

        $queue = campaignQueue();
        $batch = $queue->claimBatch($campaignId, $batchSize);

        foreach ($batch as $recipient) {
            $queue->processRecipient($recipient, $campaignId, $dryRun);
            usleep(150000);
        }

        $progress = $queue->getProgress($campaignId);
        $finalCampagne = getSingleCampagne($campaignId);
        $progress['statut'] = $finalCampagne['statut'];
        $progress['processed_this_batch'] = count($batch);

        if (in_array($progress['statut'], ['COMPLETED', 'PARTIAL'], true)) {
            $isPartial = $progress['statut'] === 'PARTIAL';
            notifications()->createUnlessRecentDuplicate(
                $isPartial ? 'campagne_partielle' : 'campagne_terminee',
                $isPartial ? 'Campagne partiellement échouée' : 'Campagne terminée',
                "« {$finalCampagne['nom']} » : {$progress['sent']} réussi(s), {$progress['failed']} échec(s).",
                $campaignId,
                525600
            );
        }

        $this->json($progress);
    }

    /**
     * SMS counter + real preview tools for the campaign composer (§18/§17).
     * Replaces server/campaign_tools.php.
     */
    public function previewTools(): void
    {
        $message = (string) ($_GET['message'] ?? $_POST['message'] ?? '');
        $groupeIdRaw = $_GET['groupe_id'] ?? $_POST['groupe_id'] ?? '';
        $groupeId = $groupeIdRaw !== '' ? (int) $groupeIdRaw : null;
        $segmentIdRaw = $_GET['segment_id'] ?? $_POST['segment_id'] ?? '';
        $segmentId = $segmentIdRaw !== '' ? (int) $segmentIdRaw : null;

        $analysis = \App\Services\SmsCounterService::analyze($message);

        $contact = $segmentId !== null ? segments()->sampleContact($segmentId) : contacts()->sampleContact($groupeId);
        $preview = null;
        $missing = [];

        if ($contact !== null) {
            $rendered = \App\Services\MessageTemplateService::render($message, [
                'nom' => $contact['nom'],
                'prenom' => $contact['prenom'],
                'telephone' => $contact['telephone'],
                'email' => $contact['email'],
            ]);
            $preview = $rendered['message'];
            $missing = $rendered['missing'];
        }

        $this->json([
            'length' => $analysis['length'],
            'encoding' => $analysis['encoding'],
            'segments' => $analysis['segments'],
            'per_segment' => $analysis['per_segment'],
            'has_contact' => $contact !== null,
            'preview' => $preview,
            'missing' => $missing,
        ]);
    }
}
