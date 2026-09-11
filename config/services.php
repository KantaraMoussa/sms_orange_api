<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/orange.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

use App\Services\CampaignQueueService;
use App\Services\AcademicResultsService;
use App\Services\ActivityLogger;
use App\Services\SmsTemplateService;

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

function activityLog(): ActivityLogger
{
    static $service = null;

    if ($service === null) {
        $service = new ActivityLogger(db());
    }

    return $service;
}

function smsTemplates(): SmsTemplateService
{
    static $service = null;

    if ($service === null) {
        $service = new SmsTemplateService(db());
    }

    return $service;
}
