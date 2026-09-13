<?php

/**
 * Builds a URL to a named route on the single front controller
 * (app/index.php). No mod_rewrite in this XAMPP setup, so routes are
 * addressed by ?route=name instead of a pretty path — see Router.
 */
function route(string $name, array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['route' => $name], $params));
}
