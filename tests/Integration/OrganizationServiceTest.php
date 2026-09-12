<?php

namespace Tests\Integration;

use App\Services\OrganizationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database. All rows use nom préfixé
 * "PHPUNITORGSVC-" et sont supprimées dans tearDown().
 */
class OrganizationServiceTest extends TestCase
{
    private PDO $pdo;
    private OrganizationService $service;
    /** @var int[] */
    private array $orgIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->service = new OrganizationService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (empty($this->orgIds)) {
            return;
        }
        $ids = implode(',', array_map('intval', $this->orgIds));
        $this->pdo->exec("DELETE FROM organizations WHERE id IN ($ids)");
    }

    private function makeOrg(array $data = []): int
    {
        $id = $this->service->create(array_merge(['nom' => 'PHPUNITORGSVC-Test'], $data));
        $this->orgIds[] = $id;

        return $id;
    }

    public function testCreateDefaultsLowBalanceThresholdTo2000(): void
    {
        $id = $this->makeOrg();

        $org = $this->service->find($id);

        $this->assertSame(2000, (int) $org['low_balance_threshold']);
    }

    public function testUpdateChangesOnlyTheProvidedFields(): void
    {
        $id = $this->makeOrg(['secteur' => 'Commerce', 'telephone' => '622000000']);

        // §34 : mettre à jour uniquement le seuil d'alerte ne doit pas
        // effacer les autres champs déjà renseignés (bug reproduit avec le
        // formulaire "Alertes", qui ne poste que nom + seuil).
        $this->service->update($id, ['nom' => 'PHPUNITORGSVC-Test', 'low_balance_threshold' => 500]);

        $org = $this->service->find($id);
        $this->assertSame(500, (int) $org['low_balance_threshold']);
        $this->assertSame('Commerce', $org['secteur'], 'a partial update must not wipe fields it did not include');
        $this->assertSame('622000000', $org['telephone']);
    }

    public function testUpdateIgnoresUnknownFields(): void
    {
        $id = $this->makeOrg();

        $this->service->update($id, ['nom' => 'PHPUNITORGSVC-Test', 'not_a_real_column' => 'x']);

        $this->assertNotNull($this->service->find($id));
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->service->find(999999999));
    }
}
