<?php
require_once __DIR__ . '/../config/services.php';
auth()->requireLogin('login.php');

/**
 * Health check (cahier des charges §47) : Système, Base de données, API
 * Orange, Filesystem/Permissions, Configuration — chaque check renvoie
 * OPERATIONAL / WARNING / ERROR plutôt qu'un simple booléen, pour distinguer
 * "ça marche mais il faut surveiller" (ex: contrat Orange expiré) de "cassé".
 */
function check(string $label, callable $fn, string $icon = '🔧'): array
{
    $start = microtime(true);
    try {
        [$status, $detail] = $fn();
    } catch (Throwable $e) {
        $status = 'ERROR';
        $detail = $e->getMessage();
    }
    return [
        'label' => $label,
        'icon' => $icon,
        'status' => $status,
        'detail' => $detail,
        'duration_ms' => round((microtime(true) - $start) * 1000, 1),
    ];
}

$checks = [];

$checks[] = check('Système (PHP)', function () {
    $required = ['pdo_pgsql', 'pgsql', 'curl', 'json'];
    $missing = array_filter($required, fn($ext) => !extension_loaded($ext));
    if (!empty($missing)) {
        return ['ERROR', 'Extensions manquantes : ' . implode(', ', $missing)];
    }
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        return ['WARNING', 'PHP ' . PHP_VERSION . ' — version plus ancienne que celle testée (8.0+)'];
    }
    $memory = ini_get('memory_limit');
    $maxExec = ini_get('max_execution_time');
    return ['OPERATIONAL', "PHP " . PHP_VERSION . " | mémoire: {$memory} | max_exec: {$maxExec}s"];
}, '🐘');

$checks[] = check('Base de données', function () {
    $pdo = db();
    $pdo->query('SELECT 1');
    $version = $pdo->query('SHOW server_version')->fetchColumn();
    $size = $pdo->query("SELECT pg_database_size(current_database())")->fetchColumn();
    $sizeHuman = $size > 1073741824 ? round($size / 1073741824, 2) . ' GB' : round($size / 1048576, 1) . ' MB';
    return ['OPERATIONAL', "PostgreSQL {$version} | taille: {$sizeHuman}"];
}, '🗄️');

$checks[] = check('API Orange', function () {
    $balance = orangeSms()->getBalance();
    if (empty($balance)) {
        return ['ERROR', 'Aucune réponse de balance() — vérifier les identifiants API'];
    }
    $status = $balance['status'] ?? 'INCONNU';
    $available = $balance['availableUnits'] ?? 0;
    $expiration = $balance['expirationDate'] ?? 'inconnue';
    $unhealthy = ['EXPIRED', 'SUSPENDED', 'TERMINATED', 'BLOCKED'];
    if (in_array($status, $unhealthy, true)) {
        return ['WARNING', "Contrat {$status} — authentification OK mais envois risquent d'échouer | solde: {$available} SMS | expiration: {$expiration}"];
    }
    if ($available < 50) {
        return ['WARNING', "Solde faible : {$available} SMS restants (expiration: {$expiration}) — recharger bientôt"];
    }
    return ['OPERATIONAL', "Authentification OK | statut: {$status} | solde: {$available} SMS | expiration: {$expiration}"];
}, '📱');

$checks[] = check('Fichiers (storage/)', function () {
    $paths = [
        'cache' => __DIR__ . '/../storage/cache',
        'logs' => __DIR__ . '/../storage/logs',
        'uploads' => __DIR__ . '/../storage/uploads',
    ];
    $errors = [];
    $warnings = [];
    foreach ($paths as $name => $path) {
        if (!is_dir($path)) {
            $errors[] = "{$name} : dossier inexistant";
            continue;
        }
        if (!is_writable($path)) {
            $errors[] = "{$name} : non accessible en écriture";
            continue;
        }
        // Vérifier l'espace disque
        $free = disk_free_space($path);
        if ($free !== false && $free < 50 * 1024 * 1024) {
            $warnings[] = "{$name} : espace disque faible (< 50 MB)";
        }
    }
    if (!empty($errors)) {
        return ['ERROR', implode('; ', $errors)];
    }
    if (!empty($warnings)) {
        return ['WARNING', implode('; ', $warnings) . ' — les dossiers sont accessibles'];
    }
    return ['OPERATIONAL', 'Tous les dossiers storage/ sont accessibles en écriture'];
}, '📁');

$checks[] = check('Configuration (.env)', function () {
    $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'ORANGE_CLIENT_ID', 'ORANGE_CLIENT_SECRET'];
    $missing = array_filter($required, fn($k) => env($k) === null || env($k) === '');
    if (!empty($missing)) {
        return ['ERROR', 'Variables manquantes : ' . implode(', ', $missing)];
    }
    $mode = env('APP_ENV', 'development');
    $debug = env('APP_DEBUG', 'true');
    $url = env('APP_URL', 'non définie');
    if ($mode !== 'production' && $debug !== 'false') {
        return ['WARNING', "APP_ENV={$mode}, APP_DEBUG={$debug} — passer en production/false avant mise en ligne réelle"];
    }
    return ['OPERATIONAL', "Toutes les variables requises sont définies | APP_ENV={$mode} | APP_URL={$url}"];
}, '⚙️');

$checks[] = check('File d\'attente (campagnes)', function () {
    $stuck = db()->query(
        "SELECT COUNT(*) FROM campagne WHERE statut IN ('RUNNING','QUEUED') AND date_lancement < NOW() - INTERVAL '1 hour'"
    )->fetchColumn();
    if ($stuck > 0) {
        return ['WARNING', "{$stuck} campagne(s) en QUEUED/RUNNING depuis plus d'1h — vérifier qu'un worker tourne"];
    }
    // Bug réel trouvé le 2026-09-12 : comptait statut = 'PENDING', une valeur
    // qui n'existe pas dans ce projet (les statuts réels sont DRAFT/QUEUED/
    // RUNNING/PAUSED/COMPLETED/PARTIAL/FAILED/CANCELLED) — "En attente"
    // affichait donc toujours 0, quel que soit le nombre réel de brouillons.
    $draft = db()->query(
        "SELECT COUNT(*) FROM campagne WHERE statut = 'DRAFT'"
    )->fetchColumn();
    $processing = db()->query(
        "SELECT COUNT(*) FROM campagne WHERE statut IN ('QUEUED', 'RUNNING')"
    )->fetchColumn();
    return ['OPERATIONAL', "Brouillons: {$draft} | En cours: {$processing} | Aucune campagne bloquée"];
}, '⏳');

$checks[] = check('Session utilisateur', function () {
    // Bug réel trouvé le 2026-09-12 : appelait auth()->getCurrentUser(), une
    // méthode qui n'existe pas (la vraie méthode est user()) — ce check
    // renvoyait donc systématiquement ERROR, quel que soit l'état réel de la
    // session, ce qui masquait les vraies alertes de cette page.
    $user = auth()->user();
    if (!$user) {
        return ['WARNING', 'Aucun utilisateur connecté (mais page accessible)'];
    }
    $role = $user['role'] ?? 'inconnu';
    return ['OPERATIONAL', "Utilisateur: {$user['email']} | Rôle: {$role}"];
}, '👤');

$checks[] = check('Cache (jetons Orange)', function () {
    // Bug réel trouvé le 2026-09-12 : appelait cache(), une fonction qui n'a
    // jamais existé dans ce projet (pas de couche de cache générique) — le
    // check tombait systématiquement dans le catch et affichait un WARNING
    // trompeur ("cache non configuré"). L'application a bien deux caches
    // réels et ciblés (jeton/solde Orange, OrangeSmsService) : ce check
    // rapporte désormais leur état effectif au lieu d'un concept fictif.
    $files = [
        'jeton d\'accès' => __DIR__ . '/../storage/cache/orange_token.json',
        'solde' => __DIR__ . '/../storage/cache/orange_balance.json',
    ];
    $details = [];
    foreach ($files as $label => $path) {
        if (!is_file($path)) {
            $details[] = "$label : pas encore mis en cache";
            continue;
        }
        $ageSeconds = time() - filemtime($path);
        $details[] = "$label : mis à jour il y a " . $ageSeconds . 's';
    }
    return ['OPERATIONAL', implode(' | ', $details)];
}, '💾');

$checks[] = check('Dernière synchronisation Orange', function () {
    // Bug réel trouvé le 2026-09-12 : interrogeait une table `sync_log` qui
    // n'a jamais existé dans ce projet — ce check échouait systématiquement
    // en ERROR (jamais attrapé par un cas normal, juste par le catch(Throwable)
    // générique de check()), ce qui faisait passer le statut global de la
    // page à ERROR en permanence, y compris en JSON (utilisé pour la
    // supervision externe, §46 — un moniteur externe aurait vu l'application
    // "down" 24h/24). Le seul indicateur réel d'une synchronisation Orange
    // réussie est le cache de solde, mis à jour uniquement après un
    // getBalance() qui a effectivement abouti (voir OrangeSmsService).
    $path = __DIR__ . '/../storage/cache/orange_balance.json';
    if (!is_file($path)) {
        return ['WARNING', 'Aucune synchronisation Orange réussie pour le moment (le cache se remplira au premier appel réel)'];
    }
    $diff = time() - filemtime($path);
    $when = date('d/m/Y H:i', filemtime($path));
    if ($diff > 86400 * 7) {
        return ['WARNING', "Dernière sync: $when (il y a " . round($diff / 86400) . " jours)"];
    }
    return ['OPERATIONAL', "Dernière sync: $when (il y a " . round($diff / 3600) . "h)"];
}, '🔄');

$overall = 'OPERATIONAL';
foreach ($checks as $c) {
    if ($c['status'] === 'ERROR') { $overall = 'ERROR'; break; }
    if ($c['status'] === 'WARNING') { $overall = 'WARNING'; }
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    http_response_code($overall === 'ERROR' ? 503 : ($overall === 'WARNING' ? 200 : 200));
    echo json_encode([
        'status' => $overall,
        'timestamp' => date('c'),
        'checks' => $checks
    ], JSON_PRETTY_PRINT);
    exit;
}

// Statistiques globales
$stats = [
    'total' => count($checks),
    'operational' => count(array_filter($checks, fn($c) => $c['status'] === 'OPERATIONAL')),
    'warning' => count(array_filter($checks, fn($c) => $c['status'] === 'WARNING')),
    'error' => count(array_filter($checks, fn($c) => $c['status'] === 'ERROR')),
    'avg_duration' => round(array_sum(array_column($checks, 'duration_ms')) / count($checks), 1),
];

$badge = ['OPERATIONAL' => 'success', 'WARNING' => 'warning', 'ERROR' => 'danger'];
$bg = ['OPERATIONAL' => '#dcfce7', 'WARNING' => '#fef3c7', 'ERROR' => '#fee2e2'];
$text = ['OPERATIONAL' => '#166534', 'WARNING' => '#92400e', 'ERROR' => '#991b1b'];
$icon = ['OPERATIONAL' => '✅', 'WARNING' => '⚠️', 'ERROR' => '❌'];
$label = ['OPERATIONAL' => 'Opérationnel', 'WARNING' => 'À surveiller', 'ERROR' => 'Erreur'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Health Check | SMS_ORANGE</title>
    <link rel="icon" href="../assets/images/favicon.svg" type="image/svg+xml" />
    <link rel="stylesheet" href="../assets/css/plugins/bootstrap.min.css" />
    <link rel="stylesheet" href="../assets/fonts/fontawesome.css" />
    <style>
        /* ============================================================
           STYLES PERSONNALISÉS
           ============================================================ */
        :root {
            --success: #22c55e;
            --success-bg: #dcfce7;
            --warning: #f59e0b;
            --warning-bg: #fef3c7;
            --danger: #ef4444;
            --danger-bg: #fee2e2;
            --gray: #64748b;
            --gray-bg: #f1f5f9;
        }

        body {
            background: #f1f5f9;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .health-header {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            border: 1px solid rgba(226, 232, 240, 0.6);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        .health-status-badge {
            font-size: 1.1rem;
            font-weight: 700;
            padding: 8px 24px;
            border-radius: 30px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .health-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .health-stat {
            background: #fff;
            border-radius: 12px;
            padding: 12px 16px;
            text-align: center;
            border: 1px solid rgba(226, 232, 240, 0.4);
        }
        .health-stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .health-stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #94a3b8;
            font-weight: 600;
        }

        .health-card {
            background: #fff;
            border-radius: 14px;
            padding: 0;
            border: 1px solid rgba(226, 232, 240, 0.6);
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            overflow: hidden;
            transition: box-shadow 0.15s;
            margin-bottom: 12px;
        }
        .health-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        }

        .health-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid transparent;
        }
        .health-card-header:hover {
            background: #f8fafc;
        }
        .health-card-header.expanded {
            border-bottom-color: #e5e9f2;
        }

        .health-card-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .health-card-icon {
            font-size: 1.4rem;
            width: 36px;
            text-align: center;
        }
        .health-card-label {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.95rem;
        }
        .health-card-status {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 14px;
            border-radius: 20px;
            white-space: nowrap;
        }
        .health-card-duration {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-left: 12px;
            white-space: nowrap;
        }

        .health-card-body {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
        }
        .health-card-body.open {
            max-height: 300px;
            padding: 12px 20px 16px;
        }
        .health-card-detail {
            font-size: 0.9rem;
            color: #475569;
            line-height: 1.5;
            font-family: 'SF Mono', 'Menlo', 'Monaco', 'Consolas', monospace;
            background: #f8fafc;
            border-radius: 8px;
            padding: 10px 14px;
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .health-footer {
            margin-top: 24px;
            padding: 16px 20px;
            background: #fff;
            border-radius: 12px;
            border: 1px solid rgba(226, 232, 240, 0.4);
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .health-footer code {
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            color: #475569;
        }

        .btn-toggle-all {
            font-size: 0.8rem;
            padding: 4px 16px;
            border-radius: 20px;
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #e2e8f0;
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Dark mode */


        /* Responsive */
        @media (max-width: 576px) {
            .health-header { padding: 16px; }
            .health-card-header { flex-wrap: wrap; gap: 8px; }
            .health-card-left { flex: 1; min-width: 200px; }
            .health-card-duration { margin-left: 0; }
            .health-stats { grid-template-columns: repeat(2, 1fr); }
            .health-status-badge { font-size: 0.9rem; padding: 6px 16px; }
            .health-card-body.open { padding: 8px 14px 12px; }
            .health-card-detail { font-size: 0.8rem; padding: 8px 12px; }
        }
    </style>
</head>
<body>
    <div class="container py-4">

        <!-- ==================== EN-TÊTE ==================== -->
        <div class="health-header">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
                <div>
                    <h2 style="font-weight:700; margin:0; display:flex; align-items:center; gap:12px; color:#0f172a;">
                        <span style="font-size:1.8rem;">🩺</span>
                        État du système
                        <span class="health-status-badge" style="background:<?= $bg[$overall] ?>; color:<?= $text[$overall] ?>;">
                            <?= $icon[$overall] ?> <?= $label[$overall] ?>
                        </span>
                    </h2>
                    <p style="margin:4px 0 0 56px; color:#64748b; font-size:0.9rem;">
                        <?= date('d/m/Y H:i:s') ?> — <?= $stats['operational'] ?>/<?= $stats['total'] ?> composants opérationnels
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap" style="flex-shrink:0;">
                    <button type="button" id="btn-toggle-all" class="btn btn-outline-secondary btn-sm btn-toggle-all">
                        📂 Tout développer
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
                        ← Retour
                    </a>
                    <a href="health.php" class="btn btn-primary btn-sm" style="border-radius:8px;">
                        🔄 Actualiser
                    </a>
                </div>
            </div>

            <!-- ====== STATS ====== -->
            <div class="health-stats">
                <div class="health-stat">
                    <div class="health-stat-value" style="color:#22c55e;"><?= $stats['operational'] ?></div>
                    <div class="health-stat-label">✅ Opérationnel</div>
                </div>
                <div class="health-stat">
                    <div class="health-stat-value" style="color:#f59e0b;"><?= $stats['warning'] ?></div>
                    <div class="health-stat-label">⚠️ À surveiller</div>
                </div>
                <div class="health-stat">
                    <div class="health-stat-value" style="color:#ef4444;"><?= $stats['error'] ?></div>
                    <div class="health-stat-label">❌ Erreur</div>
                </div>
                <div class="health-stat">
                    <div class="health-stat-value" style="color:#3b82f6;"><?= $stats['avg_duration'] ?>ms</div>
                    <div class="health-stat-label">⏱️ Latence moyenne</div>
                </div>
            </div>
        </div>

        <!-- ==================== CHECKS ==================== -->
        <?php foreach ($checks as $c): ?>
        <div class="health-card" data-status="<?= $c['status'] ?>">
            <div class="health-card-header" onclick="toggleCard(this)">
                <div class="health-card-left">
                    <span class="health-card-icon"><?= $c['icon'] ?></span>
                    <span class="health-card-label"><?= htmlspecialchars($c['label']) ?></span>
                </div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <span class="health-card-status" style="background:<?= $bg[$c['status']] ?>; color:<?= $text[$c['status']] ?>;">
                        <?= $icon[$c['status']] ?> <?= $label[$c['status']] ?>
                    </span>
                    <span class="health-card-duration">⏱️ <?= $c['duration_ms'] ?> ms</span>
                    <span style="font-size:0.7rem; color:#94a3b8;">▼</span>
                </div>
            </div>
            <div class="health-card-body">
                <pre class="health-card-detail"><?= htmlspecialchars($c['detail']) ?></pre>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- ==================== FOOTER ==================== -->
        <div class="health-footer">
            <span>🔗 Format JSON pour supervision externe : <code>health.php?format=json</code></span>
            <span>🔄 Auto-refresh <span id="countdown">60</span>s <button class="btn btn-sm btn-outline-secondary" onclick="startAutoRefresh()" style="font-size:0.7rem; padding:2px 12px;">Démarrer</button></span>
        </div>
    </div>

    <script>
        // ============================================================
        // TOGGLE CARTES
        // ============================================================
        function toggleCard(header) {
            var body = header.nextElementSibling;
            var isOpen = body.classList.contains('open');
            body.classList.toggle('open');
            header.classList.toggle('expanded');
            // Changer le symbole
            var arrow = header.querySelector('.health-card-header span:last-child');
            if (arrow) {
                arrow.textContent = isOpen ? '▼' : '▲';
            }
        }

        // Ouvrir la première carte par défaut
        document.addEventListener('DOMContentLoaded', function() {
            var firstBody = document.querySelector('.health-card-body');
            if (firstBody) {
                firstBody.classList.add('open');
                var firstHeader = firstBody.previousElementSibling;
                if (firstHeader) firstHeader.classList.add('expanded');
                var arrow = firstHeader.querySelector('.health-card-header span:last-child');
                if (arrow) arrow.textContent = '▲';
            }
        });

        // ============================================================
        // TOUT DÉVELOPPER / TOUT RÉDUIRE
        // ============================================================
        document.getElementById('btn-toggle-all').addEventListener('click', function() {
            var bodies = document.querySelectorAll('.health-card-body');
            var allOpen = Array.from(bodies).every(function(b) { return b.classList.contains('open'); });
            bodies.forEach(function(body) {
                var header = body.previousElementSibling;
                if (allOpen) {
                    body.classList.remove('open');
                    if (header) header.classList.remove('expanded');
                    var arrow = header ? header.querySelector('.health-card-header span:last-child') : null;
                    if (arrow) arrow.textContent = '▼';
                } else {
                    body.classList.add('open');
                    if (header) header.classList.add('expanded');
                    var arrow = header ? header.querySelector('.health-card-header span:last-child') : null;
                    if (arrow) arrow.textContent = '▲';
                }
            });
            this.textContent = allOpen ? '📂 Tout développer' : '📂 Tout réduire';
        });

        // ============================================================
        // AUTO-REFRESH
        // ============================================================
        var countdownEl = document.getElementById('countdown');
        var countdown = 60;
        var refreshInterval = null;

        function startAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
                countdown = 60;
                countdownEl.textContent = countdown;
                return;
            }
            refreshInterval = setInterval(function() {
                countdown--;
                countdownEl.textContent = countdown;
                if (countdown <= 0) {
                    window.location.reload();
                }
            }, 1000);
            // Changer le texte du bouton
            var btn = document.querySelector('[onclick="startAutoRefresh()"]');
            if (btn) btn.textContent = '⏹️ Arrêter';
        }
    </script>
</body>
</html>