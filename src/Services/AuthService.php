<?php

namespace App\Services;

use PDO;

/**
 * Session-based authentication against the `utilisateurs` table that already
 * existed in the database (unused until now, see AUDIT.md §1). Public
 * organization signup (app/register.php, §3/§7) creates the first account of
 * a new organization via registerOrganization(); additional teammates are
 * either invited from the app (§6, not yet built) or provisioned with
 * bin/create-user.php.
 */
class AuthService
{
    /**
     * OWNER a été ajouté avec le passage au multi-tenant (§5-§7) : c'est le
     * rôle du créateur d'une organisation. SUPER_ADMIN reste distinct : c'est
     * un rôle plateforme (compte historique de l'exploitant), pas un rôle par
     * organisation.
     *
     * CAMPAIGN_MANAGER et ANALYST ajoutés pour §6 (rôles fins par
     * organisation) : CAMPAIGN_MANAGER a les mêmes droits qu'OPERATOR
     * (conservé pour compatibilité avec d'éventuels comptes existants — les
     * deux sont traités de façon identique partout, voir
     * MUTATION_ROLES/MANAGEMENT_ROLES ci-dessous) ; ANALYST est en lecture
     * seule comme VIEWER (accès rapports/analytics, jamais d'envoi ni de
     * modification).
     */
    public const ROLES = ['SUPER_ADMIN', 'OWNER', 'ADMIN', 'CAMPAIGN_MANAGER', 'OPERATOR', 'ANALYST', 'VIEWER'];

    /** Rôles autorisés à déclencher une mutation (campagnes, contacts, modèles, envois) — §6. */
    public const MUTATION_ROLES = ['SUPER_ADMIN', 'OWNER', 'ADMIN', 'CAMPAIGN_MANAGER', 'OPERATOR'];

    /** Rôles autorisés à gérer l'organisation et son équipe (§6 : réservé ADMIN+). */
    public const MANAGEMENT_ROLES = ['SUPER_ADMIN', 'OWNER', 'ADMIN'];

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
        $_SESSION['organization_id'] = (int) $user['organization_id'];

        $org = $this->pdo->prepare('SELECT nom FROM organizations WHERE id = :id');
        $org->execute([':id' => $user['organization_id']]);
        $_SESSION['organization_nom'] = $org->fetchColumn() ?: null;

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
        // organization_id est requis en plus de user_id : une session ouverte
        // avant l'introduction du multi-tenant (ou autrement corrompue) n'a
        // que user_id, et organizationId() (int) coercerait son absence en 0,
        // un id d'organisation inexistant (violation FK plus loin, ex.
        // NotificationService::create()). On force plutôt une reconnexion.
        return isset($_SESSION['user_id'], $_SESSION['organization_id']);
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
            'organization_id' => $_SESSION['organization_id'] ?? null,
            'organization_nom' => $_SESSION['organization_nom'] ?? null,
        ];
    }

    /**
     * Id de l'organisation de l'utilisateur connecté — clé de voûte de
     * l'isolation multi-tenant (§59) : chaque Service métier l'utilise pour
     * ne jamais lire/écrire les données d'une autre organisation.
     */
    public function organizationId(): ?int
    {
        return $this->check() ? (int) $_SESSION['organization_id'] : null;
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

    /**
     * @throws \Exception si l'email est déjà utilisé — vérifié explicitement
     * plutôt que de laisser remonter la violation de contrainte unique
     * PostgreSQL (utilisateurs_email_key, globale : un email ne peut
     * appartenir qu'à une seule organisation) telle quelle en PDOException.
     */
    public function createUser(int $organizationId, string $nom, string $email, string $password, string $role = 'ADMIN'): int
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM utilisateurs WHERE email = :email');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetchColumn()) {
            throw new \Exception('Cette adresse email est déjà utilisée.');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO utilisateurs (nom, email, mot_de_passe, role, organization_id) VALUES (:nom, :email, :hash, :role, :organization_id) RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom,
            ':email' => $email,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':organization_id' => $organizationId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Auto-inscription d'une nouvelle entreprise (§3, §7) : crée l'organisation
     * et son premier utilisateur (OWNER) dans une même transaction — soit les
     * deux existent, soit aucun (pas d'organisation orpheline sans compte, pas
     * de compte flottant sans organisation).
     *
     * @param array $orgData voir OrganizationService::create()
     * @return array{organization_id: int, user_id: int}
     */
    public function registerOrganization(array $orgData, string $ownerNom, string $ownerEmail, string $ownerPassword): array
    {
        // La vérification d'email dupliqué est faite par createUser() — la
        // transaction annule proprement la création de l'organisation si elle
        // échoue, pas besoin de la dupliquer ici.
        $this->pdo->beginTransaction();
        try {
            $organizations = new OrganizationService($this->pdo);
            $organizationId = $organizations->create($orgData);
            $userId = $this->createUser($organizationId, $ownerNom, $ownerEmail, $ownerPassword, 'OWNER');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['organization_id' => $organizationId, 'user_id' => $userId];
    }

    // ------------------------------------------------------------------
    // Gestion d'équipe par organisation (§6, §7)
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function usersInOrganization(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nom, email, role, date_creation FROM utilisateurs WHERE organization_id = :org ORDER BY date_creation"
        );
        $stmt->execute([':org' => $organizationId]);

        return $stmt->fetchAll();
    }

    private function countOwners(int $organizationId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE organization_id = :org AND role = 'OWNER'");
        $stmt->execute([':org' => $organizationId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @throws \Exception si le rôle est invalide, l'utilisateur n'appartient
     * pas à l'organisation, ou si le changement retirerait le dernier OWNER
     * de l'organisation (une organisation doit toujours garder au moins un
     * propriétaire capable de gérer l'équipe).
     */
    public function updateUserRole(int $organizationId, int $userId, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \Exception('Rôle invalide.');
        }

        $stmt = $this->pdo->prepare("SELECT role FROM utilisateurs WHERE id = :id AND organization_id = :org");
        $stmt->execute([':id' => $userId, ':org' => $organizationId]);
        $currentRole = $stmt->fetchColumn();

        if ($currentRole === false) {
            throw new \Exception('Utilisateur introuvable dans votre organisation.');
        }
        if ($currentRole === 'OWNER' && $role !== 'OWNER' && $this->countOwners($organizationId) <= 1) {
            throw new \Exception('Impossible de retirer le dernier propriétaire de l\'organisation.');
        }

        $this->pdo->prepare("UPDATE utilisateurs SET role = :role WHERE id = :id AND organization_id = :org")
            ->execute([':role' => $role, ':id' => $userId, ':org' => $organizationId]);
    }

    /**
     * @throws \Exception si l'utilisateur n'appartient pas à l'organisation
     * ou si sa suppression retirerait le dernier OWNER.
     */
    public function deleteUser(int $organizationId, int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT role FROM utilisateurs WHERE id = :id AND organization_id = :org");
        $stmt->execute([':id' => $userId, ':org' => $organizationId]);
        $role = $stmt->fetchColumn();

        if ($role === false) {
            throw new \Exception('Utilisateur introuvable dans votre organisation.');
        }
        if ($role === 'OWNER' && $this->countOwners($organizationId) <= 1) {
            throw new \Exception('Impossible de supprimer le dernier propriétaire de l\'organisation.');
        }

        $this->pdo->prepare("DELETE FROM utilisateurs WHERE id = :id AND organization_id = :org")
            ->execute([':id' => $userId, ':org' => $organizationId]);
    }
}
