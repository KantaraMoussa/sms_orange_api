<?php

/**
 * Téléchargement des erreurs d'un import de contacts (§23), même modèle que
 * resultats_export_errors.php (export de fichier = script autonome, ne peut
 * pas être un fragment inclus dans le shell HTML d'app/index.php).
 */

require_once __DIR__ . '/config.php';

auth()->requireLogin('../app/login.php');

$importId = filter_input(INPUT_GET, 'import_id', FILTER_VALIDATE_INT);
if (!$importId) {
    http_response_code(400);
    exit('import_id manquant ou invalide');
}

$errors = contacts()->getImportErrors($importId);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="erreurs_import_contacts_' . $importId . '.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['Ligne', 'Erreur'], ';');
foreach ($errors as $error) {
    fputcsv($out, [$error['ligne'] ?? '', $error['erreur'] ?? ''], ';');
}
fclose($out);
