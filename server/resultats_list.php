<?php

/**
 * Liste paginée des étudiants correspondant aux filtres (cahier des charges
 * V2.0, §16-17 : tableau matricule/nom/classe/téléphone/moyenne/mention/statut,
 * recherche, exclusion des étudiants déjà envoyés). Lecture seule (pas de
 * vérification CSRF nécessaire), même modèle que resultats_preview.php.
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
    'search' => trim($_GET['search'] ?? ''),
    'exclude_already_sent' => !empty($_GET['exclude_already_sent']),
];

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(200, max(10, (int) ($_GET['per_page'] ?? 50)));

$results = academicResults();
$total = $results->countMatching($filters);
$rows = $results->getMatching($filters, $perPage, ($page - 1) * $perPage);

echo json_encode([
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => (int) ceil($total / $perPage),
    'rows' => array_map(static function ($r) {
        return [
            'id' => (int) $r['id'],
            'matricule' => $r['matricule'],
            'nom' => $r['nom'],
            'prenom' => $r['prenom'],
            'classe' => $r['classe'],
            'telephone' => $r['telephone'],
            'moyenne' => $r['moyenne'],
            'mention' => $r['mention'],
            'deja_envoye' => $r['deja_envoye'],
        ];
    }, $rows),
]);
