<?php

require_once __DIR__ . '/bootstrap.php';

use App\Services\OrangeSmsService;

/**
 * Shared OrangeSmsService instance, configured from .env.
 * The rest of the app must depend on this, never on the SDK directly (§28).
 */
function orangeSms(): OrangeSmsService
{
    static $service = null;

    if ($service === null) {
        $service = new OrangeSmsService(
            env('ORANGE_CLIENT_ID', ''),
            env('ORANGE_CLIENT_SECRET', ''),
            env('ORANGE_SENDER_NAME', '+224600000000'),
            env('ORANGE_COUNTRY_CODE', 'GIN')
        );
    }

    return $service;
}
