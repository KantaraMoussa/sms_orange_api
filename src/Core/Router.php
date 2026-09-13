<?php

namespace App\Core;

/**
 * Front-controller router. Routes are addressed by name via ?route=xxx
 * (no mod_rewrite/.htaccess in this XAMPP setup, and touching Apache config
 * is out of scope for this refactor) rather than by pretty path — this is
 * still a genuine router (name -> [Controller, action]) dispatched from a
 * single entry point, not the old ad-hoc if/elseif chain it replaces.
 */
class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $name, string $controllerClass, string $action): void
    {
        $this->routes['GET'][$name] = [$controllerClass, $action];
    }

    public function post(string $name, string $controllerClass, string $action): void
    {
        $this->routes['POST'][$name] = [$controllerClass, $action];
    }

    public function dispatch(string $method, string $routeName): void
    {
        $entry = $this->routes[$method][$routeName] ?? null;

        if ($entry === null) {
            (new \App\Controllers\ErrorController())->notFound();
            return;
        }

        [$controllerClass, $action] = $entry;
        $controller = new $controllerClass();
        $controller->$action();
    }
}
