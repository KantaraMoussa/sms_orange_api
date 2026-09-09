<?php

/**
 * Long-running worker for a real deployment (cron / Windows Task Scheduler,
 * cahier des charges §79) — the production-grade alternative to the
 * browser-polling fallback in server/campaign_worker.php.
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

    echo "[campagne $campaignId] terminé.\n";
}

$arg = $argv[1] ?? null;

if ($arg === '--daemon') {
    echo "Worker en mode démon — Ctrl+C pour arrêter.\n";
    $pdo = db();
    while (true) {
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
