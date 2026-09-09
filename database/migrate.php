<?php

/**
 * Minimal, framework-free migration runner (cahier des charges §48 : pas de
 * framework imposé). Applies every .sql file in database/migrations/ that is
 * not yet recorded in schema_migrations, in filename order, once each.
 *
 * Usage: php database/migrate.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);

$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Applying $name ...\n";
    $sql = file_get_contents($file);

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (:f)");
        $stmt->execute([':f' => $name]);
        $pdo->commit();
        echo "  OK\n";
        $ran++;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo $ran > 0 ? "$ran migration(s) applied.\n" : "Nothing to migrate.\n";
