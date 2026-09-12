<?php

/**
 * Téléchargement des erreurs d'un import de résultats académiques (§10 :
 * "permettre de télécharger les erreurs"). Script autonome (et non un bloc
 * dans app/templete/resultats.php) car un export de fichier doit envoyer ses
 * en-têtes HTTP avant toute sortie HTML, ce que la page templée ne permet
 * plus une fois incluse dans le shell d'app/index.php.
 */

require_once __DIR__ . '/config.php';

auth()->requireLogin('../app/login.php');

$importId = filter_input(INPUT_GET, 'import_id', FILTER_VALIDATE_INT);
if (!$importId) {
    http_response_code(400);
    exit('import_id manquant ou invalide');
}

$errors = academicResults()->getImportErrors($importId);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="erreurs_import_' . $importId . '.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour qu'Excel affiche correctement les accents.
fputcsv($out, ['Ligne', 'Erreur'], ';');
foreach ($errors as $error) {
    fputcsv($out, [$error['ligne'] ?? '', $error['erreur'] ?? ''], ';');
}
fclose($out);
