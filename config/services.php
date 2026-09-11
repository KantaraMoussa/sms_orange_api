<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/orange.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

use App\Services\CampaignQueueService;
use App\Services\AcademicResultsService;

function campaignQueue(): CampaignQueueService
{
    static $service = null;

    if ($service === null) {
        $service = new CampaignQueueService(db(), orangeSms());
    }

    return $service;
}

function academicResults(): AcademicResultsService
{
    static $service = null;

    if ($service === null) {
        $service = new AcademicResultsService(db());
    }

    return $service;
}
