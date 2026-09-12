<?php

// PostgreSQL stocke et compare tous les timestamps en UTC (session
// `timezone` = GMT, confirmé via `SHOW timezone`), mais le fuseau système
// par défaut de PHP peut être tout autre chose (Europe/Berlin en
// développement) — sans ce réglage, tout date()/new DateTime() sans fuseau
// explicite calcule une heure murale locale en avance de 1-2h sur l'UTC,
// qui bascule au jour suivant plusieurs heures avant minuit UTC. Ce piège a
// été trouvé et corrigé au cas par cas 4 fois (AuthService::locked_until,
// CampaignQueueService::schedule(), resolveDateRangePreset() à deux
// reprises) avant d'être traité ici une bonne fois pour toutes. N'affecte
// pas l'affichage dans le fuseau propre à chaque organisation
// (formatOrgDateTime() etc.), qui précise toujours explicitement son fuseau
// cible indépendamment de ce défaut.
date_default_timezone_set('UTC');

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
