<?php
require __DIR__ . '/vendor/autoload.php';

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;

// ⚡ Configuration Orange
$client_id = '<client_id>';
$client_secret = '<client_secret>';
$from = '<num_expediteur>'; // ex : +224600000000

$client = SMSClient::getInstance($client_id, $client_secret);
$sms = new SMS($client);

// ⚡ Lire le CSV
$csvFile = __DIR__ . '/contacts.csv';
if (!file_exists($csvFile)) {
    die("❌ Fichier CSV introuvable !");
}

$handle = fopen($csvFile, 'r');
if ($handle === false) {
    die("❌ Impossible d'ouvrir le fichier CSV !");
}

// ⚡ Parcourir chaque ligne et envoyer le SMS
while (($data = fgetcsv($handle, 1000, ',')) !== false) {
    $to = trim($data[0]);
    $message = trim($data[1]);

    if (empty($to) || empty($message)) continue; // Ignorer les lignes vides

    try {
        $response = $sms->message($message)
                        ->from($from)
                        ->to($to)
                        ->send();
        echo "✅ SMS envoyé à $to | Réponse API: ";
        print_r($response);
        echo "\n";
    } catch (\Exception $e) {
        echo "❌ Erreur pour $to : " . $e->getMessage() . "\n";
    }
}

fclose($handle);

echo "\n🎯 Envoi terminé !\n";
