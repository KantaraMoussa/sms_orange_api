<?php

/**
 * Single front controller. Replaces the old ?page=xxx if/elseif chain: every
 * request (view or mutation) is dispatched by route name to a Controller
 * action via App\Core\Router (see app/routes.php). No mod_rewrite in this
 * XAMPP setup, so routes are addressed by ?route=xxx rather than a pretty
 * path (see App\Core\Router / route()).
 */

require_once __DIR__ . '/../config/services.php';

$route = $_GET['route'] ?? 'dashboard.index';

// These three were standalone JSON endpoints (server/campaign_worker.php,
// campaign_tools.php, segment_tools.php) polled/fetched from the browser —
// a redirect-to-login response body would break response.json() client-side
// on session expiry, so (like before) they answer 401 JSON instead of
// redirecting, rather than going through auth()->requireLogin().
$jsonRoutes = ['campaigns.poll', 'campaigns.previewTools', 'segments.previewCount'];
if (in_array($route, $jsonRoutes, true)) {
    if (!auth()->check()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Non authentifié']);
        exit;
    }
} else {
    auth()->requireLogin('login.php');
}

// Legacy global helpers (getSingleCampagne, assertOwnsCampagne, getGlobalSmsStats,
// ...) — the Model layer, unchanged by this MVC reorganisation (see
// ARCHITECTURE.md). Runs checkInternet(), same as before this refactor
// (previously loaded unconditionally via server/infosAPI.php on every page).
require_once __DIR__ . '/../server/config.php';

$router = require __DIR__ . '/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $route);
