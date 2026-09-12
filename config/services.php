<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/orange.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

use App\Services\CampaignQueueService;
use App\Services\ActivityLogger;
use App\Services\SmsTemplateService;
use App\Services\ContactService;
use App\Services\NotificationService;

function campaignQueue(): CampaignQueueService
{
    static $service = null;

    if ($service === null) {
        $service = new CampaignQueueService(db(), orangeSms());
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

function contacts(): ContactService
{
    static $service = null;

    if ($service === null) {
        $service = new ContactService(db());
    }

    return $service;
}

function notifications(): NotificationService
{
    static $service = null;

    if ($service === null) {
        $service = new NotificationService(db());
    }

    return $service;
}
