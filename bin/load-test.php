<?php

/**
 * Load test for cahier des charges §60 : simulate a campaign with 10,000+
 * recipients end to end (import -> queue -> batch processing) against the
 * real database, in dry_run mode (no real SMS, no Orange credentials spent),
 * and report timing/memory/throughput. All rows are tagged type='loadtest'
 * and removed at the end, success or failure.
 *
 * Usage: php bin/load-test.php [count] [batch_size]
 *   php bin/load-test.php            # 10000 recipients, batch 200
 *   php bin/load-test.php 15000 500
 */

require_once __DIR__ . '/../server/config.php';

$count = (int) ($argv[1] ?? 10000);
$batchSize = (int) ($argv[2] ?? 200);

$pdo = db();
$queue = campaignQueue();

function fmtBytes(int $bytes): string
{
    return round($bytes / 1024 / 1024, 1) . ' MB';
}

function fmtSecs(float $secs): string
{
    return round($secs, 2) . 's';
}

echo "=== Test de charge : $count destinataires, lots de $batchSize (dry_run) ===\n\n";

$campaignId = null;

try {
    // 1) Création + import
    $t0 = microtime(true);
    $campaignId = $queue->createCampaign("Load test $count", 'Test de charge automatisé', 'loadtest', 'load-test.php', $batchSize);

    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        // Valid-looking Guinean numbers spread across a wide range, unique per row.
        $suffix = str_pad((string) $i, 8, '0', STR_PAD_LEFT);
        $rows[] = [
            'destinataire' => '6' . substr($suffix, 0, 8),
            'contenu' => "Bonjour, ceci est un message de test #$i.",
        ];
    }
    $importResult = $queue->addRecipients($campaignId, $rows);
    $tImport = microtime(true) - $t0;

    echo "Import : " . fmtSecs($tImport) . " pour {$importResult['added']} ajoutés"
        . " ({$importResult['duplicates']} doublons, {$importResult['invalid']} invalides)\n";
    echo "  -> " . round($importResult['added'] / max($tImport, 0.001), 1) . " insertions/s\n\n";

    // 2) EXPLAIN ANALYZE on the exact claim query (§32) with the full load-test
    //    dataset present, so the plan reflects a realistic table size.
    $queue->queueCampaign($campaignId, dryRun: true);

    $explain = $pdo->query(
        "EXPLAIN ANALYZE SELECT id, destinataire, contenu, tentative_count
         FROM messages WHERE campagne_id = $campaignId AND statut = 'en_attente'
         ORDER BY id LIMIT $batchSize FOR UPDATE SKIP LOCKED"
    )->fetchAll(PDO::FETCH_COLUMN);
    echo "EXPLAIN ANALYZE du claim de lot (idx_messages_campagne_statut) :\n";
    foreach ($explain as $line) {
        echo "  $line\n";
    }
    echo "\n";

    // 3) Process every batch, exactly as campaign_worker.php / process-campaign.php would.
    $t0 = microtime(true);
    $batches = 0;
    $batchTimes = [];
    do {
        $tb = microtime(true);
        $batch = $queue->claimBatch($campaignId, $batchSize);
        foreach ($batch as $recipient) {
            $queue->processRecipient($recipient, $campaignId, dryRun: true);
        }
        $batchTimes[] = microtime(true) - $tb;
        $batches++;
        $progress = $queue->getProgress($campaignId);
    } while (!$progress['done']);
    $tProcess = microtime(true) - $t0;

    echo "Traitement : " . fmtSecs($tProcess) . " en $batches lot(s)\n";
    echo "  -> " . round($progress['sent'] / max($tProcess, 0.001), 1) . " SMS(simulés)/s\n";
    echo "  -> Premier lot : " . fmtSecs($batchTimes[0]) . ", dernier lot : " . fmtSecs(end($batchTimes)) . " (doit rester stable, pas de dégradation avec la taille de la table)\n";
    echo "  -> Réussis : {$progress['sent']}, échecs : {$progress['failed']}, statut final : " . getSingleCampagne($campaignId)['statut'] . "\n\n";

    echo "Mémoire PHP : pic = " . fmtBytes(memory_get_peak_usage(true)) . "\n\n";

    echo "=== Résultat : " . ($progress['done'] && $progress['sent'] === $importResult['added'] ? "OK" : "ANOMALIE") . " ===\n";
} finally {
    if ($campaignId !== null) {
        $pdo->exec("DELETE FROM messages WHERE campagne_id = $campaignId");
        $pdo->exec("DELETE FROM campagne WHERE id = $campaignId");
        echo "\n(données de test supprimées)\n";
    }
}
