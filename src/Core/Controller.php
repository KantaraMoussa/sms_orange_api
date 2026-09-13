<?php

namespace App\Core;

use App\Services\AuthService;

abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/app'): void
    {
        View::render($view, $data, $layout);
    }

    protected function redirect(string $routeName, array $params = []): void
    {
        header('Location: ' . route($routeName, $params));
        exit;
    }

    /**
     * Same anti-open-redirect / anti-missing-referer logic as the legacy
     * redirectBack() (server/config.php, kept as the Model layer's helper —
     * see tests/Unit/RedirectBackTest.php), reused here instead of
     * duplicated.
     */
    protected function redirectBack(string $fallbackRoute = 'dashboard.index'): void
    {
        redirectBack(route($fallbackRoute));
        exit;
    }

    /**
     * JSON_INVALID_UTF8_SUBSTITUTE: without this flag json_encode() silently
     * returns false (an empty HTTP 200 body, no error indication client-side)
     * if any string contains invalid UTF-8 bytes — found while testing an
     * accented character with a broken encoding (see AUDIT.md).
     */
    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    protected function requireRole(array $roles): void
    {
        if (!auth()->hasRole($roles)) {
            http_response_code(403);
            exit('Accès refusé : votre rôle ne permet pas cette action.');
        }
    }

    protected function requireMutationRole(): void
    {
        $this->requireRole(AuthService::MUTATION_ROLES);
    }

    protected function requireManagementRole(): void
    {
        $this->requireRole(AuthService::MANAGEMENT_ROLES);
    }

    protected function requireCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
            http_response_code(419);
            exit('Session expirée ou requête invalide, veuillez recharger la page et réessayer.');
        }
    }

    protected function flash(string $class, string $message): void
    {
        $_SESSION['class'] = $class;
        $_SESSION['message'] = $message;
    }

    protected function actor(): ?string
    {
        return auth()->user()['nom'] ?? null;
    }
}
