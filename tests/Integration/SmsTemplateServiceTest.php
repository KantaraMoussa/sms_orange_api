<?php

namespace Tests\Integration;

use App\Services\SmsTemplateService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database. All rows use nom prefixed
 * "PHPUNITTPL-" and are deleted in tearDown().
 *
 * Covers cahier des charges V2.0 §25 : créer/modifier/dupliquer/archiver.
 */
class SmsTemplateServiceTest extends TestCase
{
    private PDO $pdo;
    private SmsTemplateService $service;
    /** @var int[] */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->service = new SmsTemplateService($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM sms_templates WHERE nom LIKE 'PHPUNITTPL-%'");
    }

    public function testCreateAndFind(): void
    {
        $id = $this->service->create('PHPUNITTPL-Bienvenue', 'notification', 'Bonjour {{prenom}}', 'phpunit');

        $found = $this->service->find($id);

        $this->assertSame('PHPUNITTPL-Bienvenue', $found['nom']);
        $this->assertSame('notification', $found['categorie']);
        $this->assertFalse(in_array($found['archive'], [true, 't', '1', 1], true));
    }

    public function testUpdateChangesContent(): void
    {
        $id = $this->service->create('PHPUNITTPL-Update', 'rappel', 'Ancien contenu');

        $this->service->update($id, 'PHPUNITTPL-Update', 'rappel', 'Nouveau contenu');

        $this->assertSame('Nouveau contenu', $this->service->find($id)['contenu']);
    }

    public function testDuplicateCreatesASeparateCopy(): void
    {
        $id = $this->service->create('PHPUNITTPL-Original', 'information', 'Contenu original');

        $copyId = $this->service->duplicate($id);

        $this->assertNotNull($copyId);
        $this->assertNotSame($id, $copyId);
        $this->assertSame('PHPUNITTPL-Original (copie)', $this->service->find($copyId)['nom']);
        $this->assertSame('Contenu original', $this->service->find($copyId)['contenu']);
    }

    public function testDuplicateOfMissingTemplateReturnsNull(): void
    {
        $this->assertNull($this->service->duplicate(999999999));
    }

    public function testArchiveExcludesFromDefaultListing(): void
    {
        $id = $this->service->create('PHPUNITTPL-Archivable', 'alerte', 'Contenu');

        $activeBefore = array_column($this->service->all(false), 'nom');
        $this->assertContains('PHPUNITTPL-Archivable', $activeBefore);

        $this->service->setArchived($id, true);

        $activeAfter = array_column($this->service->all(false), 'nom');
        $this->assertNotContains('PHPUNITTPL-Archivable', $activeAfter);

        $withArchived = array_column($this->service->all(true), 'nom');
        $this->assertContains('PHPUNITTPL-Archivable', $withArchived);
    }
}
