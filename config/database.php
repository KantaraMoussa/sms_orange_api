<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Shared PDO connection to the apiSms database (the only database the
 * application actually uses — see AUDIT.md §2). Replaces the previous
 * PDO() function which opened a brand new connection on every single query.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host = env('DB_HOST', 'localhost');
        $port = env('DB_PORT', '5432');
        $dbname = env('DB_NAME', 'apiSms');
        $user = env('DB_USER', 'postgres');
        $password = env('DB_PASSWORD', '');

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}
