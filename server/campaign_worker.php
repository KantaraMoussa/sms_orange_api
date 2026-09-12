<?php

/**
 * Batch worker endpoint, called repeatedly (AJAX polling) by the campaign
 * progress screen. Each call claims and processes ONE batch, then returns —
 * it never loops over the whole campaign inside a single HTTP request
 * (cahier des charges §5, §34). This is the "no real queue system available"
 * fallback described in §54; bin/process-campaign.php is the equivalent
 * long-running worker for a real cron/Task Scheduler deployment.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!auth()->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$campaignId = filter_input(INPUT_GET, 'campagne_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'campagne_id', FILTER_VALIDATE_INT);

if (!$campaignId) {
    http_response_code(400);
    echo json_encode(['error' => 'campagne_id manquant ou invalide']);
    exit;
}

// §59 : ce endpoint est appelé depuis le navigateur (polling AJAX), avec un
// campagne_id visible/modifiable côté client — sans cette vérification,
// n'importe quel utilisateur connecté (de n'importe quelle organisation)
// pouvait faire avancer l'envoi ET lire la progression de la campagne d'une
// autre organisation en devinant/énumérant son id. Trouvé en auditant ce
// fichier après coup, absent de la vérification anti-IDOR faite ailleurs
// dans server/app.php lors de l'introduction du multi-tenant.
$campagne = getSingleCampagne($campaignId);
if (!$campagne || (int) $campagne['organization_id'] !== auth()->organizationId()) {
    http_response_code(404);
    echo json_encode(['error' => 'Campagne introuvable']);
    exit;
}

if (!in_array($campagne['statut'], ['QUEUED', 'RUNNING'], true)) {
    echo json_encode(['error' => null] + campaignQueue()->getProgress($campaignId) + ['statut' => $campagne['statut']]);
    exit;
}

$batchSize = max(1, min(200, (int) ($campagne['batch_size'] ?? 50)));
$dryRun = ($campagne['dry_run'] ?? false) === true || $campagne['dry_run'] === 't';

$queue = campaignQueue();
$batch = $queue->claimBatch($campaignId, $batchSize);

foreach ($batch as $recipient) {
    $queue->processRecipient($recipient, $campaignId, $dryRun);
    // Respect Orange's rate limits instead of firing the whole batch at once (§56).
    usleep(150000);
}

$progress = $queue->getProgress($campaignId);
$finalCampagne = getSingleCampagne($campaignId);
$progress['statut'] = $finalCampagne['statut'];
$progress['processed_this_batch'] = count($batch);

// Centre de notifications (§73) : signale la fin de campagne. Dédupliqué sur
// une fenêtre très large (1 an) pour ne jamais notifier deux fois la même
// campagne, même si plusieurs requêtes concurrentes observent la même transition.
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

echo json_encode($progress);
