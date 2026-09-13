<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/orange.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../src/Core/helpers.php';

use App\Services\CampaignQueueService;
use App\Services\ActivityLogger;
use App\Services\SmsTemplateService;
use App\Services\ContactService;
use App\Services\NotificationService;
use App\Services\OrganizationService;
use App\Services\SegmentService;
use App\Services\CreditService;

function campaignQueue(): CampaignQueueService
{
    static $service = null;

    if ($service === null) {
        $service = new CampaignQueueService(db(), orangeSms());
    }

    return $service;
}

function organizations(): OrganizationService
{
    static $service = null;

    if ($service === null) {
        $service = new OrganizationService(db());
    }

    return $service;
}

// Les quatre services ci-dessous sont scopés à l'organisation de l'utilisateur
// connecté dès leur construction (§59 : isolation multi-tenant) — voir
// AuthService::organizationId(). CampaignQueueService reste volontairement
// à part : server/campaign_worker.php (tâche planifiée, sans session HTTP)
// doit pouvoir traiter les campagnes de toutes les organisations.

function activityLog(): ActivityLogger
{
    static $service = null;

    if ($service === null) {
        $service = new ActivityLogger(db(), auth()->organizationId());
    }

    return $service;
}

function smsTemplates(): SmsTemplateService
{
    static $service = null;

    if ($service === null) {
        $service = new SmsTemplateService(db(), auth()->organizationId());
    }

    return $service;
}

function contacts(): ContactService
{
    static $service = null;

    if ($service === null) {
        $service = new ContactService(db(), auth()->organizationId());
    }

    return $service;
}

function notifications(): NotificationService
{
    static $service = null;

    if ($service === null) {
        $service = new NotificationService(db(), auth()->organizationId());
    }

    return $service;
}

function segments(): SegmentService
{
    static $service = null;

    if ($service === null) {
        $service = new SegmentService(db(), auth()->organizationId());
    }

    return $service;
}

function credits(): CreditService
{
    static $service = null;

    if ($service === null) {
        $service = new CreditService(db(), auth()->organizationId());
    }

    return $service;
}
