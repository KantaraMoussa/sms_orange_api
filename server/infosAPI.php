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

    // Centre de notifications (§73) : alerte de solde faible, au plus une fois
    // par jour (le solde ne change pas assez vite pour justifier plus).
    $threshold = (int) env('LOW_BALANCE_THRESHOLD', 2000);
    if ((int) $balance["availableUnits"] < $threshold) {
        notifications()->createUnlessRecentDuplicate(
            'solde_faible',
            'Solde SMS faible',
            "Solde actuel : {$balance['availableUnits']} SMS (seuil d'alerte : $threshold).",
            null,
            1440
        );
    }
}

?>