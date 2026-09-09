<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/orange.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

use App\Services\CampaignQueueService;

function campaignQueue(): CampaignQueueService
{
    static $service = null;

    if ($service === null) {
        $service = new CampaignQueueService(db(), orangeSms());
    }

    return $service;
}
