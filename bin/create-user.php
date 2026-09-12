<?php

/**
 * Provisions a user account inside an existing organization — for adding
 * teammates to an organization already created via app/register.php (or the
 * bootstrap organization id=1), without going through the app's UI.
 * Self-service organization signup (§3, §7) now lives in app/register.php;
 * this script is for provisioning additional accounts server-side.
 *
 * Usage: php bin/create-user.php "Nom Complet" email@example.com motdepasse [ROLE] [ORGANIZATION_ID]
 * ROLE in SUPER_ADMIN | OWNER | ADMIN | OPERATOR | VIEWER (default: ADMIN)
 * ORGANIZATION_ID: id numérique d'une organisation existante (default: 1)
 */

require_once __DIR__ . '/../config/services.php';

use App\Services\AuthService;

[$script, $nom, $email, $password, $role, $organizationId] = array_pad($argv, 6, null);
$role = $role ?? 'ADMIN';
$organizationId = $organizationId !== null ? (int) $organizationId : 1;

if (!$nom || !$email || !$password) {
    fwrite(STDERR, "Usage: php bin/create-user.php \"Nom\" email@example.com motdepasse [ROLE] [ORGANIZATION_ID]\n");
    exit(1);
}

if (!in_array($role, AuthService::ROLES, true)) {
    fwrite(STDERR, "Rôle invalide. Valeurs possibles : " . implode(', ', AuthService::ROLES) . "\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Le mot de passe doit contenir au moins 8 caractères.\n");
    exit(1);
}

if (!organizations()->find($organizationId)) {
    fwrite(STDERR, "Organisation #$organizationId introuvable.\n");
    exit(1);
}

try {
    $id = auth()->createUser($organizationId, $nom, $email, $password, $role);
    echo "Utilisateur créé (id=$id, role=$role, organization_id=$organizationId).\n";
} catch (Exception $e) {
    fwrite(STDERR, "Erreur : " . $e->getMessage() . "\n");
    exit(1);
}
