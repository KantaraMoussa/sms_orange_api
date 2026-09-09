<?php
(isset($_SESSION))? null : session_start();
require_once('config.php');
if (($_GET['page'] ?? '') === "dashdoards") {
    $balance = orangeSms()->getBalance();
    $expirationDate = new DateTime($balance["expirationDate"]);
    $_SESSION['soldeSms'] = $balance["availableUnits"];
    $_SESSION['dateExpiration'] = $expirationDate->format('d/m/Y H:i:s');
    $_SESSION['status'] = $balance["status"];
    $_SESSION['history_purchase'] = orangeSms()->getHistory();
    $_SESSION['totalSmsSend'] = orangeSms()->getTotalSmsSent();
}

?>