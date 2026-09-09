<?php

/**
 * Minimal CSRF protection (cahier des charges §36) — no form on this app
 * had any token before, meaning any external page could silently submit
 * "single-sender", "cancel_campagne", etc. on behalf of a
 * logged-in admin.
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $submitted = $_POST['_csrf'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    return $submitted !== '' && $expected !== '' && hash_equals($expected, $submitted);
}
