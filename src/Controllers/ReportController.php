<?php

namespace App\Controllers;

use App\Core\Controller;

class ReportController extends Controller
{
    public function index(): void
    {
        $orgId = auth()->organizationId();

        $datePreset = $_GET['date_range'] ?? '';
        $dateFromInput = $_GET['date_from'] ?? '';
        $dateToInput = $_GET['date_to'] ?? '';
        $campagneFiltre = !empty($_GET['campagne_id']) ? (int) $_GET['campagne_id'] : null;

        $range = resolveDateRangePreset($datePreset, $dateFromInput, $dateToInput);

        $this->view('reports/index', [
            'datePreset' => $datePreset,
            'dateFromInput' => $dateFromInput,
            'dateToInput' => $dateToInput,
            'campagneFiltre' => $campagneFiltre,
            'range' => $range,
            'campaigns' => getCampaignsReport($orgId, $range['from'], $range['to'], $campagneFiltre),
            'topErrors' => getTopErrors($orgId, 10, $range['from'], $range['to'], $campagneFiltre),
            'globalStats' => getGlobalSmsStats($orgId, $range['from'], $range['to'], $campagneFiltre),
            'toutesCampagnes' => getCampagne($orgId),
        ]);
    }
}
