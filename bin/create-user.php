<?php

/**
 * Provisions an admin account. No public registration is exposed on purpose
 * (cahier des charges §37-§38) — this tool sends paid SMS, accounts are
 * created by whoever controls the server, not by self-service signup.
 *
 * Usage: php bin/create-user.php "Nom Complet" email@example.com motdepasse [ROLE]
 * ROLE in SUPER_ADMIN | ADMIN | OPERATOR | VIEWER (default: SUPER_ADMIN)
 */

require_once __DIR__ . '/../config/services.php';

use App\Services\AuthService;

[$script, $nom, $email, $password, $role] = array_pad($argv, 5, null);
$role = $role ?? 'SUPER_ADMIN';

if (!$nom || !$email || !$password) {
    fwrite(STDERR, "Usage: php bin/create-user.php \"Nom\" email@example.com motdepasse [ROLE]\n");
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

try {
    $id = auth()->createUser($nom, $email, $password, $role);
    echo "Utilisateur créé (id=$id, role=$role).\n";
} catch (Exception $e) {
    fwrite(STDERR, "Erreur : " . $e->getMessage() . "\n");
    exit(1);
}
