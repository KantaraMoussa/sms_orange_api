<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/orange.php';
$logFile = __DIR__ . '/sms_log.txt';

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    die('<div class="alert alert-danger">Erreur lors du téléchargement du fichier CSV !</div>');
}

$from = $_POST['from_number'] ?? '';
if (empty($from)) die('<div class="alert alert-danger">Numéro expéditeur requis !</div>');

// Lire le fichier CSV uploadé
$csvFile = $_FILES['csv_file']['tmp_name'];
$handle = fopen($csvFile, 'r');
if ($handle === false) die('<div class="alert alert-danger">Impossible d\'ouvrir le fichier CSV !</div>');

echo '<div class="container mt-4">';

while (($data = fgetcsv($handle, 1000, ',')) !== false) {
    $to = trim($data[0]);
    $message = trim($data[1]);

    if (empty($to) || empty($message)) continue;
    try {
        $response = orangeSms()->sendSms($to, $message, $from);
        $status = $response['outboundSMSMessageRequest']['deliveryInfoList']['deliveryInfo'][0]['deliveryStatus'] ?? 'UNKNOWN';
        $log = date("Y-m-d H:i:s") . " | $to | $status | SUCCESS\n";
        file_put_contents($logFile, $log, FILE_APPEND);
    } catch (Exception $e) {
        $log = date("Y-m-d H:i:s") . " | $to | ERROR | " . $e->getMessage() . "\n";
        file_put_contents($logFile, $log, FILE_APPEND);
    }

    usleep(200000);
}

fclose($handle);
echo "<div class='alert alert-info'>🎯 Envoi terminé !</div>";
echo '</div>';
