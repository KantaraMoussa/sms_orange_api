<?php

require_once __DIR__ . '/database.php';

use App\Services\AuthService;

function auth(): AuthService
{
    static $service = null;

    if ($service === null) {
        $service = new AuthService(db());
    }

    return $service;
}
