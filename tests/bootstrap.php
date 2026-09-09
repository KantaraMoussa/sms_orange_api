<?php

// Buffered so PHPUnit's own console output never trips PHP's "headers already
// sent" guard around session_start()/session_regenerate_id() (AuthServiceTest).
ob_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/services.php';
// Legacy global helpers (getSingleCampagne, getMessageCampagne, db(), ...) used
// by the integration tests, exactly as the rest of the application uses them.
require_once __DIR__ . '/../server/config.php';

// Started here, before PHPUnit prints anything, so AuthService's own
// session_start() (skipped once a session is already active) never hits
// PHP's "headers already sent" guard against a session started after output.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
