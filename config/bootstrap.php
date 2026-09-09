<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (!isset($GLOBALS['__sms_orange_env_loaded'])) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
    $GLOBALS['__sms_orange_env_loaded'] = true;
}

function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}
