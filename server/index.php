<?php
/*/ Autres fonctionnalités disponibles :
$balance = $sms->balance('GIN');
$history = $sms->ordersHistory('GIN');
$stats = $sms->statistics('GIN', '<app_id>');
$response = $sms->setDeliveryReceiptNotificationUrl($url, '+224600000000');*/
require_once __DIR__ . '/vendor/autoload.php';
use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;
$clientId = "VDnMeAPmoenbvOD2BTtWDTe0ILdQ4SLC";
$clientSecret = "HQckwZtQNOGFXKb2tdUjG0ZZQSO4UFPpFueKU2l8GyFk";
$data=[];
$client = SMSClient::getInstance($clientId, $clientSecret);
$sms = new SMS($client);
$data['soldeSms']=$sms->balance('GIN')[0]["availableUnits"];
$data['dateExpiration']=$sms->balance('GIN')[0]["expirationDate"];
$data['status']=$sms->balance('GIN')[0]["status"];
$data['history_purchase']=$sms->ordersHistory('GIN');
/*/ statistique sur les sms
$statsArray = $sms->statistics('GIN');
foreach ($statsArray['partnerStatistics']['statistics'][0]['serviceStatistics'][0]['countryStatistics'] as $stat) {
    $appId = $stat['appid'] ?: 'Général';
    echo "Application: $appId | SMS envoyés: {$stat['usage']} | Appels API: {$stat['nbEnforcements']}\n";
}
exit();*/



print_r($sms->statistics('GIN','BvbBTCF8t0X7UKz3'));

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Envoi de SMS - Orange API</title>
    <style>
        body { font-family: Arial; background: #f2f2f2; padding: 20px; }
        form { background: white; padding: 20px; border-radius: 8px; max-width: 400px; margin: auto; }
        input, textarea, button { width: 100%; margin-bottom: 10px; padding: 10px; }
        button { background: #ff6600; color: white; border: none; cursor: pointer; }
        button:hover { background: #e55b00; }
    </style>
</head>
<body>
    <h2>📩 Envoi de SMS via Orange API</h2>
    <form action="send.php" method="POST">
        <label>Numéro destinataire (+224...)</label>
        <input type="text" name="numero" required>

        <label>Message</label>
        <textarea name="message" maxlength="160" required></textarea>

        <button type="submit">Envoyer</button>
    </form>
</body>
</html>
