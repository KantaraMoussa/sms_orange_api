<?php

namespace App\Controllers;

use App\Core\Controller;
use Exception;

class SmsHistoryController extends Controller
{
    public function index(): void
    {
        $balanceError = null;
        $balance = [];
        $stats = [];
        $history = [];

        try {
            $balance = orangeSms()->getBalance();
            $stats = orangeSms()->getStatistics();
            $history = orangeSms()->getHistory();
        } catch (Exception $e) {
            $balanceError = $e->getMessage();
        }

        // Aplati les statistiques d'usage (structure imbriquée service > pays > application) en lignes simples.
        $statRows = [];
        foreach (($stats['partnerStatistics']['statistics'] ?? []) as $service) {
            foreach (($service['serviceStatistics'] ?? []) as $svc) {
                foreach (($svc['countryStatistics'] ?? []) as $country) {
                    $statRows[] = [
                        'service' => $service['service'] ?? '',
                        'pays' => $svc['country'] ?? '',
                        'application' => $country['appid'] ?: '(par défaut)',
                        'usage' => (int) ($country['usage'] ?? 0),
                        'rejets' => (int) ($country['nbEnforcements'] ?? 0),
                    ];
                }
            }
        }
        $totalUsage = array_sum(array_column($statRows, 'usage'));

        $this->view('sms-history/index', [
            'balanceError' => $balanceError,
            'balance' => $balance,
            'statRows' => $statRows,
            'totalUsage' => $totalUsage,
            'history' => $history,
        ]);
    }
}
