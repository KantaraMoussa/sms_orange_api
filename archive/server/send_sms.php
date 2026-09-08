<?php
require '../vendor/autoload.php';
$logFile = "sms_log.txt";

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;

// ⚡ Configuration Orange
$client_id = "VDnMeAPmoenbvOD2BTtWDTe0ILdQ4SLC";
$client_secret = "HQckwZtQNOGFXKb2tdUjG0ZZQSO4UFPpFueKU2l8GyFk";

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    die('<div class="alert alert-danger">Erreur lors du téléchargement du fichier CSV !</div>');
}

$from = $_POST['from_number'] ?? '';
if (empty($from)) die('<div class="alert alert-danger">Numéro expéditeur requis !</div>');

// Initialiser le client Orange
$client = SMSClient::getInstance($client_id, $client_secret);
$sms = new SMS($client);

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
        $response = $sms->message($message)
            ->from($from)
            ->to($to)
            ->send();
        // Vérifier le statut
        $status = $response['outboundSMSMessageRequest']['deliveryInfoList']['deliveryInfo'][0]['deliveryStatus'] ?? 'UNKNOWN';
        $log = date("Y-m-d H:i:s") . " | $to | $status | SUCCESS\n";
        file_put_contents($logFile, $log, FILE_APPEND);
    } catch (Exception $e) {
        $log = date("Y-m-d H:i:s") . " | $to | ERROR | " . $e->getMessage() . "\n";
        file_put_contents($logFile, $log, FILE_APPEND);
    }

    /* try {
        $response = $sms->message($message)
            ->from($from)
            ->to($to)
            ->send();
        if (isset($response['outboundSMSMessageRequest']['deliveryInfoList']['deliveryInfo'][0]['deliveryStatus'])) {
            $status = $response['outboundSMSMessageRequest']['deliveryInfoList']['deliveryInfo'][0]['deliveryStatus'];

            if ($status === "DeliveredToTerminal") {
                echo "<div style='color:green'><b>✅ SMS livré avec succès !</b></div>";
            } else {
                echo "<div style='color:orange'><b>⚠️ SMS envoyé mais statut : $status</b></div>";
            }
        } else {
            echo "<div style='color:red'><b>❌ Réponse inattendue de l'API Orange</b></div>";
            echo "<pre>" . htmlspecialchars(print_r($response, true)) . "</pre>";
        }
        echo "<div class='alert alert-success'>✅ SMS envoyé à $to</div>";
    } catch (\Exception $e) {
        echo "<div class='alert alert-danger'>❌ Erreur pour $to : " . $e->getMessage() . "</div>";
    }*/
     usleep(200000);
}

fclose($handle);
echo "<div class='alert alert-info'>🎯 Envoi terminé !</div>";
echo '</div>';
