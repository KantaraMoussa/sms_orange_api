<?php  
(isset($_SESSION))? null : session_start();
require_once('layout.php');
if($_GET['page']=="dashdoards"){
    function GetTotalSms($sms){
    $statsArray = $sms->statistics('GIN');
    $totalSms = 0;
    $countryStats = $statsArray['partnerStatistics']['statistics'][0]['serviceStatistics'][0]['countryStatistics'];
    foreach ($countryStats as $stat) {
        $totalSms += $stat['usage'];
    }
    return   $totalSms;
}
$data=[];
$expirationDate= new DateTime($sms->balance('GIN')[0]["expirationDate"]);
$_SESSION['soldeSms']=$sms->balance('GIN')[0]["availableUnits"];
$_SESSION['dateExpiration']=$expirationDate->format('d/m/Y H:i:s');
$_SESSION['status']=$sms->balance('GIN')[0]["status"];
$_SESSION['history_purchase']=$sms->ordersHistory('GIN');
$_SESSION['totalSmsSend']=GetTotalSms($sms);
}

?>