<?php
require __DIR__ . '/vendor/autoload.php';

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;

// Configuration API Orange
$client = SMSClient::getInstance('CLIENT_ID', 'CLIENT_SECRET'); // Remplace par tes infos
$sms = new SMS($client);

// Configuration
$csvFile = __DIR__ . "/contacts.csv";
$sender  = "SCOL_UGLCS"; // Sender Name validé
$message = "📢 ALERT UGLCS\nVotre résultat annuel est disponible.\nConnectez-vous pour le consulter.";
$batchSize = 500; // Nombre de SMS par lot
$logFile = __DIR__ . "/sms_log.txt";

// Lire le CSV
$numbers = [];
if (($handle = fopen($csvFile, "r")) !== false) {
    $header = fgetcsv($handle); // lire l’entête
    $colIndex = array_search('number', $header);

    while (($data = fgetcsv($handle)) !== false) {
        $numbers[] = $data[$colIndex];
    }
    fclose($handle);
}

// Découper en lots
$batches = array_chunk($numbers, $batchSize);

foreach ($batches as $i => $batch) {
    echo "Envoi lot " . ($i+1) . " (" . count($batch) . " SMS)\n";

    foreach ($batch as $number) {
        try {
            $response = $sms->message($message)
                ->from($sender)
                ->to($number)
                ->send();

            // Vérifier le statut
            $status = $response['outboundSMSMessageRequest']['deliveryInfoList']['deliveryInfo'][0]['deliveryStatus'] ?? 'UNKNOWN';
            $log = date("Y-m-d H:i:s") . " | $number | $status | SUCCESS\n";
            file_put_contents($logFile, $log, FILE_APPEND);

        } catch (Exception $e) {
            $log = date("Y-m-d H:i:s") . " | $number | ERROR | " . $e->getMessage() . "\n";
            file_put_contents($logFile, $log, FILE_APPEND);
        }

        usleep(200000); // Pause 0,2s pour limiter la charge API
    }
}

echo "✅ Envoi terminé. Consulte $logFile pour les statuts.";
