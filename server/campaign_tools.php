<?php

/**
 * Outils AJAX du créateur de campagne (§18 : compteur de caractères/SMS,
 * §17 : prévisualisation réelle du message avant envoi) — endpoint de
 * lecture seule, appelé par fetch() pendant la composition du message,
 * jamais de mutation ici donc pas de vérification CSRF (même principe que
 * server/campaign_worker.php).
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!auth()->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$message = (string) ($_GET['message'] ?? $_POST['message'] ?? '');
$groupeIdRaw = $_GET['groupe_id'] ?? $_POST['groupe_id'] ?? '';
$groupeId = $groupeIdRaw !== '' ? (int) $groupeIdRaw : null;
$segmentIdRaw = $_GET['segment_id'] ?? $_POST['segment_id'] ?? '';
$segmentId = $segmentIdRaw !== '' ? (int) $segmentIdRaw : null;

$analysis = \App\Services\SmsCounterService::analyze($message);

// sampleContact() est déjà scopé à l'organisation courante et ne renvoie
// jamais le contact d'une autre organisation, même si groupe_id/segment_id
// est forgé.
$contact = $segmentId !== null ? segments()->sampleContact($segmentId) : contacts()->sampleContact($groupeId);
$preview = null;
$missing = [];

if ($contact !== null) {
    $rendered = \App\Services\MessageTemplateService::render($message, [
        'nom' => $contact['nom'],
        'prenom' => $contact['prenom'],
        'telephone' => $contact['telephone'],
        'email' => $contact['email'],
    ]);
    $preview = $rendered['message'];
    $missing = $rendered['missing'];
}

// JSON_INVALID_UTF8_SUBSTITUTE : sans ce flag, json_encode() renvoie
// silencieusement `false` (donc une réponse HTTP 200 vide, sans indice
// d'erreur côté JS) si $message contient des octets invalides en UTF-8 —
// trouvé en testant avec un caractère accentué mal encodé.
echo json_encode([
    'length' => $analysis['length'],
    'encoding' => $analysis['encoding'],
    'segments' => $analysis['segments'],
    'per_segment' => $analysis['per_segment'],
    'has_contact' => $contact !== null,
    'preview' => $preview,
    'missing' => $missing,
], JSON_INVALID_UTF8_SUBSTITUTE);
