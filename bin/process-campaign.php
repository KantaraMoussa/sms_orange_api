<?php

/**
 * Long-running worker for a real deployment (cron / Windows Task Scheduler,
 * cahier des charges §79) — the production-grade alternative to the
 * browser-polling fallback (route campaigns.poll, CampaignController::poll()).
 *
 * Usage:
 *   php bin/process-campaign.php <campagne_id>   # drains one campaign then exits
 *   php bin/process-campaign.php --daemon        # continuously drains any QUEUED/RUNNING campaign
 *
 * Stop: Ctrl+C, or (daemon mode) delete/rename this process via your process
 * manager — there is no separate stop signal file by design, keep it simple.
 */

require_once __DIR__ . '/../server/config.php';

function drainCampaign(int $campaignId): void
{
    $queue = campaignQueue();

    do {
        $campagne = getSingleCampagne($campaignId);
        if (!$campagne || !in_array($campagne['statut'], ['QUEUED', 'RUNNING'], true)) {
            echo "[campagne $campaignId] statut = " . ($campagne['statut'] ?? 'INTROUVABLE') . " -> arrêt\n";
            return;
        }

        $batchSize = max(1, min(200, (int) ($campagne['batch_size'] ?? 50)));
        $dryRun = $campagne['dry_run'] === true || $campagne['dry_run'] === 't';

        $batch = $queue->claimBatch($campaignId, $batchSize);
        foreach ($batch as $recipient) {
            $queue->processRecipient($recipient, $campaignId, $dryRun);
            usleep(150000);
        }

        $progress = $queue->getProgress($campaignId);
        echo sprintf(
            "[campagne %d] envoyés=%d échecs=%d en attente=%d total=%d\n",
            $campaignId,
            $progress['sent'],
            $progress['failed'],
            $progress['pending'] + $progress['processing'],
            $progress['total']
        );

        if (empty($batch)) {
            usleep(500000);
        }
    } while (!$progress['done']);

    $finalStatut = getSingleCampagne($campaignId)['statut'];
    if (in_array($finalStatut, ['COMPLETED', 'PARTIAL'], true)) {
        $isPartial = $finalStatut === 'PARTIAL';
        notifications()->createUnlessRecentDuplicate(
            $isPartial ? 'campagne_partielle' : 'campagne_terminee',
            $isPartial ? 'Campagne partiellement échouée' : 'Campagne terminée',
            "« " . getSingleCampagne($campaignId)['nom'] . " » : {$progress['sent']} réussi(s), {$progress['failed']} échec(s).",
            $campaignId,
            525600
        );
    }

    echo "[campagne $campaignId] terminé.\n";
}

$arg = $argv[1] ?? null;

if ($arg === '--daemon') {
    echo "Worker en mode démon — Ctrl+C pour arrêter.\n";
    $pdo = db();
    while (true) {
        // §30 : promeut les campagnes SCHEDULED dont l'heure est arrivée
        // avant de chercher du travail — un seul démon suffit à la fois pour
        // la planification et l'envoi, pas besoin d'un second processus cron.
        $promoted = campaignQueue()->promoteDueCampaigns();
        if ($promoted > 0) {
            echo "$promoted campagne(s) planifiée(s) promue(s) en QUEUED.\n";
        }

        // Automatisations : génère et lance la prochaine occurrence de
        // chaque campagne récurrente due (même démon, même raison que
        // ci-dessus — ne doit dépendre de personne ayant l'app ouverte).
        $spawned = campaignQueue()->processRecurringCampaigns();
        if (!empty($spawned)) {
            echo count($spawned) . " occurrence(s) automatique(s) générée(s) : " . implode(', ', $spawned) . "\n";
        }

        $stmt = $pdo->query("SELECT id FROM campagne WHERE statut IN ('QUEUED','RUNNING') ORDER BY id");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            sleep(3);
            continue;
        }

        foreach ($ids as $id) {
            drainCampaign((int) $id);
        }
    }
} elseif (ctype_digit((string) $arg)) {
    drainCampaign((int) $arg);
} else {
    fwrite(STDERR, "Usage: php bin/process-campaign.php <campagne_id> | --daemon\n");
    exit(1);
}
