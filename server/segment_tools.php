<?php

/**
 * Aperçu en direct du nombre de contacts correspondant à un critère de
 * segment (§13), pendant la composition du formulaire — lecture seule,
 * jamais de mutation, donc pas de vérification CSRF (même principe que
 * server/campaign_tools.php et server/campaign_worker.php).
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!auth()->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$criteria = [
    'search' => $_GET['search'] ?? null,
    'statut' => $_GET['statut'] ?? null,
    'groupe_id' => $_GET['groupe_id'] ?? null,
    'created_after' => $_GET['created_after'] ?? null,
    'created_before' => $_GET['created_before'] ?? null,
];

echo json_encode(['count' => segments()->previewCount($criteria)], JSON_INVALID_UTF8_SUBSTITUTE);
