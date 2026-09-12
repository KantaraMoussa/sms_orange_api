<?php

namespace Tests\Unit;

use App\Services\AuthService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Uses a mocked PDO so no real database is touched — covers the pure
 * decision logic (password check, role check) required by cahier des
 * charges §37-§38.
 */
class AuthServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function authWithUserRow(?array $row): AuthService
    {
        if ($row !== null) {
            $row += ['organization_id' => 1];
        }

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($row === null ? false : $row);
        // Deuxième requête d'AuthService::attempt() (nom de l'organisation) — le
        // même mock $stmt sert aux deux prepare(), fetchColumn() n'a pas besoin
        // d'être réaliste ici, seul fetch() (ligne utilisateur) importe au test.
        $stmt->method('fetchColumn')->willReturn('Organisation Test');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new AuthService($pdo);
    }

    public function testAttemptFailsWhenUserDoesNotExist(): void
    {
        $auth = $this->authWithUserRow(null);

        $this->assertFalse($auth->attempt('nobody@example.com', 'whatever'));
        $this->assertFalse($auth->check());
    }

    public function testAttemptFailsWithWrongPassword(): void
    {
        $auth = $this->authWithUserRow([
            'id' => 1, 'nom' => 'Test', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('correct-password', PASSWORD_DEFAULT),
            'role' => 'ADMIN',
        ]);

        $this->assertFalse($auth->attempt('a@example.com', 'wrong-password'));
        $this->assertFalse($auth->check());
    }

    public function testAttemptSucceedsWithCorrectPasswordAndPopulatesSession(): void
    {
        $auth = $this->authWithUserRow([
            'id' => 42, 'nom' => 'Admin Test', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('correct-password', PASSWORD_DEFAULT),
            'role' => 'SUPER_ADMIN',
        ]);

        $this->assertTrue($auth->attempt('a@example.com', 'correct-password'));
        $this->assertTrue($auth->check());
        $this->assertSame([
            'id' => 42, 'nom' => 'Admin Test', 'email' => 'a@example.com', 'role' => 'SUPER_ADMIN',
            'organization_id' => 1, 'organization_nom' => 'Organisation Test',
        ], $auth->user());
    }

    public function testLogoutClearsSession(): void
    {
        $auth = $this->authWithUserRow([
            'id' => 1, 'nom' => 'X', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('pw', PASSWORD_DEFAULT), 'role' => 'VIEWER',
        ]);
        $auth->attempt('a@example.com', 'pw');
        $this->assertTrue($auth->check());

        $auth->logout();

        $this->assertFalse($auth->check());
        $this->assertNull($auth->user());
    }

    public function testHasRoleReflectsLoggedInUserRole(): void
    {
        $auth = $this->authWithUserRow([
            'id' => 1, 'nom' => 'X', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('pw', PASSWORD_DEFAULT), 'role' => 'VIEWER',
        ]);
        $auth->attempt('a@example.com', 'pw');

        $this->assertTrue($auth->hasRole(['VIEWER', 'OPERATOR']));
        $this->assertFalse($auth->hasRole(['SUPER_ADMIN', 'ADMIN', 'OPERATOR']));
    }

    public function testHasRoleIsFalseWhenNotLoggedIn(): void
    {
        $auth = $this->authWithUserRow(null);

        $this->assertFalse($auth->hasRole(['VIEWER']));
    }

    public function testAttemptBelowLockoutThresholdDoesNotLock(): void
    {
        // 3 échecs déjà enregistrés : le 4e échec ne doit pas encore verrouiller (seuil = 5).
        $auth = $this->authWithUserRow([
            'id' => 1, 'nom' => 'X', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('correct', PASSWORD_DEFAULT), 'role' => 'VIEWER',
            'failed_attempts' => 3, 'locked_until' => null,
        ]);

        $this->assertFalse($auth->attempt('a@example.com', 'wrong'));
        $this->assertNull($auth->lockedForSeconds());
    }

    public function testAttemptLocksAccountOnceMaxAttemptsReached(): void
    {
        // 4 échecs déjà enregistrés : le 5e échec doit déclencher le verrouillage.
        $auth = $this->authWithUserRow([
            'id' => 1, 'nom' => 'X', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('correct', PASSWORD_DEFAULT), 'role' => 'VIEWER',
            'failed_attempts' => 4, 'locked_until' => null,
        ]);

        $this->assertFalse($auth->attempt('a@example.com', 'wrong'));
        $this->assertNotNull($auth->lockedForSeconds());
        $this->assertGreaterThan(0, $auth->lockedForSeconds());
    }

    public function testAttemptRejectsEvenCorrectPasswordWhileLocked(): void
    {
        $auth = $this->authWithUserRow([
            'id' => 1, 'nom' => 'X', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('correct', PASSWORD_DEFAULT), 'role' => 'VIEWER',
            'failed_attempts' => 5, 'locked_until' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);

        $this->assertFalse($auth->attempt('a@example.com', 'correct'));
        $this->assertFalse($auth->check());
        $this->assertNotNull($auth->lockedForSeconds());
    }

    public function testAttemptSucceedsAfterLockoutWindowHasExpired(): void
    {
        $auth = $this->authWithUserRow([
            'id' => 1, 'nom' => 'X', 'email' => 'a@example.com',
            'mot_de_passe' => password_hash('correct', PASSWORD_DEFAULT), 'role' => 'VIEWER',
            'failed_attempts' => 5, 'locked_until' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $this->assertTrue($auth->attempt('a@example.com', 'correct'));
        $this->assertTrue($auth->check());
        $this->assertNull($auth->lockedForSeconds());
    }
}
