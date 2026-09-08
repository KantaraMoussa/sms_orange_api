<?php
require __DIR__ . '/vendor/autoload.php';

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;

// ⚡ Étape 1 : Initialisation du client Orange
$client_id = '<client_id>';
$client_secret = '<client_secret>';
$client = SMSClient::getInstance($client_id, $client_secret);

// ⚡ Étape 2 : Instancier le service SMS
$sms = new SMS($client);

// ----------------------------
// 1️⃣ Envoi d’un SMS
// ----------------------------
$from = '<num_expediteur>'; // Exemple : +224600000000
$to = '<num_destinataire>'; // Exemple : +224650000000
$message = "Bonjour, ceci est un test Orange SMS API";

try {
    $response = $sms->message($message)
                    ->from($from)
                    ->to($to)
                    ->send();
    echo "✅ SMS envoyé avec succès !\n";
    print_r($response);
} catch (\Exception $e) {
    echo "❌ Erreur lors de l'envoi du SMS : " . $e->getMessage() . "\n";
}

// ----------------------------
// 2️⃣ Vérifier le solde SMS
// ----------------------------
try {
    $balance = $sms->balance('GIN'); // GIN = Guinée
    echo "\n💰 Solde SMS :\n";
    print_r($balance);
} catch (\Exception $e) {
    echo "❌ Erreur lors de la récupération du solde : " . $e->getMessage() . "\n";
}

// ----------------------------
// 3️⃣ Afficher les statistiques d’envoi
// ----------------------------
try {
    $statsArray = $sms->statistics('GIN'); // pays : Guinée
    echo "\n📊 Statistiques SMS :\n";

    foreach ($statsArray['partnerStatistics']['statistics'][0]['serviceStatistics'][0]['countryStatistics'] as $stat) {
        $appId = $stat['appid'] ?: 'Général';
        echo "Application: $appId | SMS envoyés: {$stat['usage']} | Appels API: {$stat['nbEnforcements']}\n";
    }
} catch (\Exception $e) {
    echo "❌ Erreur lors de la récupération des statistiques : " . $e->getMessage() . "\n";
}
