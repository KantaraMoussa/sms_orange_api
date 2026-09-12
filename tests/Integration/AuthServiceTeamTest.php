<?php

namespace Tests\Integration;

use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Gestion d'équipe par organisation (§6, §7) : ajout de membres, changement
 * de rôle, retrait — avec la garde-fou "on ne retire jamais le dernier
 * OWNER d'une organisation".
 *
 * Runs against the real apiSms database. All rows use un email préfixé
 * "phpunitteam-" et sont supprimées dans tearDown() avec leur organisation.
 */
class AuthServiceTeamTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;
    /** @var int[] */
    private array $orgIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->auth = new AuthService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (empty($this->orgIds)) {
            return;
        }
        $ids = implode(',', array_map('intval', $this->orgIds));
        $this->pdo->exec("DELETE FROM utilisateurs WHERE organization_id IN ($ids)");
        $this->pdo->exec("DELETE FROM organizations WHERE id IN ($ids)");
    }

    private function registerOrg(string $suffix): int
    {
        $result = $this->auth->registerOrganization(
            ['nom' => "PHPUNITTEAM-$suffix"],
            "Owner $suffix",
            "phpunitteam-$suffix@example.com",
            'password1234'
        );
        $this->orgIds[] = $result['organization_id'];

        return $result['organization_id'];
    }

    public function testUsersInOrganizationListsOnlyThatOrganization(): void
    {
        $orgA = $this->registerOrg('list-a');
        $this->registerOrg('list-b');

        $members = $this->auth->usersInOrganization($orgA);

        $this->assertCount(1, $members);
        $this->assertSame('OWNER', $members[0]['role']);
    }

    public function testCreateUserAddsATeammateToTheSameOrganization(): void
    {
        $orgId = $this->registerOrg('add');

        $this->auth->createUser($orgId, 'Coéquipier', 'phpunitteam-add-2@example.com', 'password1234', 'CAMPAIGN_MANAGER');

        $members = $this->auth->usersInOrganization($orgId);
        $this->assertCount(2, $members);
        $roles = array_column($members, 'role');
        $this->assertContains('CAMPAIGN_MANAGER', $roles);
    }

    public function testCreateUserRejectsDuplicateEmailEvenAcrossOrganizations(): void
    {
        $orgA = $this->registerOrg('dup-a');
        $orgB = $this->registerOrg('dup-b');

        $this->expectException(\Exception::class);
        $this->auth->createUser($orgB, 'Autre', "phpunitteam-dup-a@example.com", 'password1234', 'VIEWER');
    }

    public function testUpdateUserRoleChangesRole(): void
    {
        $orgId = $this->registerOrg('role');
        $userId = $this->auth->createUser($orgId, 'X', 'phpunitteam-role-2@example.com', 'password1234', 'VIEWER');

        $this->auth->updateUserRole($orgId, $userId, 'ANALYST');

        $members = $this->auth->usersInOrganization($orgId);
        $updated = current(array_filter($members, fn($m) => (int) $m['id'] === $userId));
        $this->assertSame('ANALYST', $updated['role']);
    }

    public function testCannotDemoteTheLastOwner(): void
    {
        $orgId = $this->registerOrg('lastowner-demote');
        $members = $this->auth->usersInOrganization($orgId);
        $ownerId = (int) $members[0]['id'];

        $this->expectException(\Exception::class);
        $this->auth->updateUserRole($orgId, $ownerId, 'ADMIN');
    }

    public function testCanDemoteAnOwnerWhenAnotherOwnerRemains(): void
    {
        $orgId = $this->registerOrg('multiowner-demote');
        $members = $this->auth->usersInOrganization($orgId);
        $firstOwnerId = (int) $members[0]['id'];
        $secondOwnerId = $this->auth->createUser($orgId, 'Second Owner', 'phpunitteam-multiowner-demote-2@example.com', 'password1234', 'OWNER');

        $this->auth->updateUserRole($orgId, $firstOwnerId, 'ADMIN');

        $updated = current(array_filter($this->auth->usersInOrganization($orgId), fn($m) => (int) $m['id'] === $firstOwnerId));
        $this->assertSame('ADMIN', $updated['role']);
        $this->assertNotSame(0, $secondOwnerId);
    }

    public function testCannotDeleteTheLastOwner(): void
    {
        $orgId = $this->registerOrg('lastowner-delete');
        $ownerId = (int) $this->auth->usersInOrganization($orgId)[0]['id'];

        $this->expectException(\Exception::class);
        $this->auth->deleteUser($orgId, $ownerId);
    }

    public function testDeleteUserRemovesANonOwnerMember(): void
    {
        $orgId = $this->registerOrg('delete');
        $userId = $this->auth->createUser($orgId, 'To Remove', 'phpunitteam-delete-2@example.com', 'password1234', 'VIEWER');

        $this->auth->deleteUser($orgId, $userId);

        $this->assertCount(1, $this->auth->usersInOrganization($orgId));
    }

    public function testUpdateUserRoleRejectsUserFromAnotherOrganization(): void
    {
        $orgA = $this->registerOrg('cross-a');
        $orgB = $this->registerOrg('cross-b');
        $userIdInB = (int) $this->auth->usersInOrganization($orgB)[0]['id'];

        $this->expectException(\Exception::class);
        $this->auth->updateUserRole($orgA, $userIdInB, 'ADMIN');
    }
}
