<?php

namespace App\Controllers;

use App\Core\Controller;

class DashboardController extends Controller
{
    public function index(): void
    {
        $orgId = auth()->organizationId();

        // Synchronise le solde/statistiques Orange en session (anciennement
        // server/infosAPI.php, appelé uniquement pour cette page).
        $balance = orangeSms()->getBalance();
        $expirationDate = new \DateTime($balance['expirationDate']);
        $_SESSION['soldeSms'] = $balance['availableUnits'];
        $_SESSION['dateExpiration'] = $expirationDate->format('d/m/Y H:i:s');
        $_SESSION['status'] = $balance['status'];
        $_SESSION['history_purchase'] = orangeSms()->getHistory();
        $_SESSION['totalSmsSend'] = orangeSms()->getTotalSmsSent();

        $organization = organizations()->find($orgId);
        $threshold = (int) ($organization['low_balance_threshold'] ?? 2000);
        if ((int) $balance['availableUnits'] < $threshold) {
            notifications()->createUnlessRecentDuplicate(
                'solde_faible',
                'Solde SMS faible',
                "Solde actuel : {$balance['availableUnits']} SMS (seuil d'alerte : $threshold).",
                null,
                1440
            );
        }

        $this->view('dashboard/index', [
            'evolution' => getSmsEvolution($orgId, 14),
            'globalStats' => getGlobalSmsStats($orgId),
            'performance' => getCampaignPerformance($orgId, 6),
            'successRateEvolution' => getSuccessRateEvolution($orgId, 14),
            'recentCampagnes' => array_slice(getCampagne($orgId), 0, 5),
            'smsToday' => getSmsSentToday($orgId),
            'smsThisMonth' => getSmsSentThisMonth($orgId),
            'activeCampaigns' => getActiveCampaignsCount($orgId),
        ]);
    }
}
