<?php

namespace App\Services;

use PDO;

/**
 * Session-based authentication against the `utilisateurs` table that already
 * existed in the database (unused until now, see AUDIT.md §1). No public
 * self-registration is exposed on purpose (cahier des charges §37-§38): this
 * is an internal admin tool that sends paid SMS, not a public SaaS signup —
 * accounts are provisioned with bin/create-user.php.
 */
class AuthService
{
    public const ROLES = ['SUPER_ADMIN', 'ADMIN', 'OPERATOR', 'VIEWER'];

    /** Nombre d'échecs consécutifs avant verrouillage temporaire du compte. */
    private const MAX_ATTEMPTS = 5;

    /** Durée du verrouillage une fois le seuil atteint. */
    private const LOCKOUT_MINUTES = 15;

    private ?int $lockedForSeconds = null;

    public function __construct(private PDO $pdo)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Bloque les tentatives une fois MAX_ATTEMPTS échecs consécutifs atteints,
     * pendant LOCKOUT_MINUTES (protection brute-force basique — pas de CAPTCHA
     * ni de rate-limit par IP, volontairement simple pour un outil interne).
     * En cas d'échec par verrouillage, lockedForSeconds() renvoie le temps
     * restant pour que la vue puisse afficher un message précis.
     */
    public function attempt(string $email, string $password): bool
    {
        $this->lockedForSeconds = null;

        $stmt = $this->pdo->prepare("SELECT * FROM utilisateurs WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        if (!empty($user['locked_until'])) {
            // locked_until vient d'une colonne PostgreSQL "timestamp without time
            // zone" écrite en UTC (NOW()), mais sans suffixe de fuseau dans la
            // chaîne renvoyée par PDO. Sans préciser " UTC" ici, strtotime()
            // l'interprète avec le fuseau par défaut de PHP (date.timezone,
            // souvent différent d'UTC — ex. Europe/Berlin, UTC+2), ce qui
            // décale le calcul et peut faire passer un compte verrouillé pour
            // déverrouillé (bug constaté et corrigé en testant en conditions réelles).
            $remaining = strtotime($user['locked_until'] . ' UTC') - time();
            if ($remaining > 0) {
                $this->lockedForSeconds = $remaining;
                return false;
            }
        }

        if (!password_verify($password, $user['mot_de_passe'])) {
            $this->registerFailedAttempt((int) $user['id'], (int) ($user['failed_attempts'] ?? 0));
            return false;
        }

        $this->pdo->prepare("UPDATE utilisateurs SET failed_attempts = 0, locked_until = NULL WHERE id = :id")
            ->execute([':id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_nom'] = $user['nom'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];

        return true;
    }

    private function registerFailedAttempt(int $userId, int $currentFailedAttempts): void
    {
        $attempts = $currentFailedAttempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->pdo->prepare(
                "UPDATE utilisateurs SET failed_attempts = :n, locked_until = NOW() + (:mins || ' minutes')::interval WHERE id = :id"
            )->execute([':n' => $attempts, ':mins' => self::LOCKOUT_MINUTES, ':id' => $userId]);
            $this->lockedForSeconds = self::LOCKOUT_MINUTES * 60;
        } else {
            $this->pdo->prepare("UPDATE utilisateurs SET failed_attempts = :n WHERE id = :id")
                ->execute([':n' => $attempts, ':id' => $userId]);
        }
    }

    /**
     * Secondes restantes de verrouillage après le dernier attempt() en échec,
     * ou null si l'échec n'était pas dû à un verrouillage (mauvais mot de
     * passe simple, compte inexistant).
     */
    public function lockedForSeconds(): ?int
    {
        return $this->lockedForSeconds;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }

        return [
            'id' => $_SESSION['user_id'],
            'nom' => $_SESSION['user_nom'],
            'email' => $_SESSION['user_email'],
            'role' => $_SESSION['user_role'],
        ];
    }

    public function hasRole(array $allowed): bool
    {
        return $this->check() && in_array($_SESSION['user_role'], $allowed, true);
    }

    /**
     * Redirects to the login page if there is no active session (§37 : protection des routes).
     */
    public function requireLogin(string $loginUrl = '/app/login.php'): void
    {
        if (!$this->check()) {
            header("Location: $loginUrl");
            exit;
        }
    }

    public function createUser(string $nom, string $email, string $password, string $role = 'ADMIN'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (:nom, :email, :hash, :role) RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom,
            ':email' => $email,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
        ]);

        return (int) $stmt->fetchColumn();
    }
}
