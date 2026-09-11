<?php

/**
 * Endpoint JSON appelé en AJAX depuis app/templete/resultats.php à chaque
 * changement de filtre ou du modèle de message : nombre d'étudiants
 * correspondants, aperçu réel du message (même moteur que l'envoi, §17),
 * calcul du nombre de SMS (§18/§26) et solde Orange disponible (§20/§27).
 * Lecture seule — aucune mutation, donc pas de vérification CSRF nécessaire.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!auth()->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$filters = [
    'session_academique' => trim($_GET['session'] ?? ''),
    'niveau' => trim($_GET['niveau'] ?? ''),
    'classe' => trim($_GET['classe'] ?? ''),
    'programme' => trim($_GET['programme'] ?? ''),
    'semestre' => trim($_GET['semestre'] ?? ''),
];
$template = (string) ($_GET['template'] ?? '');

$results = academicResults();
$count = $results->countMatching($filters);
$sample = $results->getSample($filters);

$templateVars = $sample !== null ? \App\Services\AcademicResultsService::toTemplateVars($sample) : [];
$rendered = \App\Services\MessageTemplateService::render($template, $templateVars);
$calc = \App\Services\SmsCounterService::analyze($rendered['message']);

$balance = null;
try {
    $b = orangeSms()->getBalance();
    $balance = (int) ($b['availableUnits'] ?? 0);
} catch (Exception $e) {
    $balance = null; // API indisponible : on ne bloque pas la prévisualisation pour autant.
}

echo json_encode([
    'count' => $count,
    'sample' => $sample,
    'preview_message' => $rendered['message'],
    'missing_vars' => $rendered['missing'],
    'sms' => $calc,
    'estimated_sms_total' => $count * max(1, $calc['segments']),
    'balance' => $balance,
    'balance_sufficient' => $balance === null ? null : ($balance >= $count * max(1, $calc['segments'])),
]);
