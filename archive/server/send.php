<?php
require_once __DIR__ . '/vendor/autoload.php';

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;

// Identifiants API Orange
$clientId = "VDnMeAPmoenbvOD2BTtWDTe0ILdQ4SLC";
$clientSecret = "HQckwZtQNOGFXKb2tdUjG0ZZQSO4UFPpFueKU2l8GyFk";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero = trim($_POST['numero']);
    $message = trim($_POST['message']);


    try {
        // Connexion à l'API
        $client = SMSClient::getInstance($clientId, $clientSecret);
        $sms = new SMS($client);
        // Envoi
        $response = $sms->message($message)
            ->from('+224627447348') // Numéro expéditeur
            ->to($numero)
            ->send();

        //echo "<h3>✅ SMS envoyé avec succès !</h3>";
        echo "<pre>" . print_r($response, true) . "</pre>";
    } catch (Exception $e) {
        echo "<h3>❌ Erreur : " . $e->getMessage() . "</h3>";
    }
}
