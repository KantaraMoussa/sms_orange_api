<?php

namespace Tests\Integration;

use App\Services\ContactService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database. All rows use telephone prefixed
 * 6299 (matching the convention in CampaignQueueServiceTest) and a
 * distinctive nom "PHPUNITCONTACT" or group nom "PHPUNITGROUP", deleted in
 * tearDown().
 *
 * Covers cahier des charges V2.0 §22-24 : contacts, groupes, import CSV.
 */
class ContactServiceTest extends TestCase
{
    private PDO $pdo;
    private ContactService $service;
    /** @var string[] */
    private array $tmpFiles = [];
    /** @var int[] */
    private array $groupIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->service = new ContactService($this->pdo, 1);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM groupe_contacts_v2 WHERE groupe_id IN (SELECT id FROM groupes_v2 WHERE nom LIKE 'PHPUNITGROUP%')");
        $this->pdo->exec("DELETE FROM groupes_v2 WHERE nom LIKE 'PHPUNITGROUP%'");
        $this->pdo->exec("DELETE FROM contacts_v2 WHERE nom LIKE 'PHPUNITCONTACT%' OR telephone LIKE '+2246299%'");
        $this->pdo->exec("DELETE FROM imports_contacts WHERE filename LIKE 'phpunit_%'");

        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function makeCsv(array $rows, string $header = 'nom,prenom,telephone,email'): string
    {
        $path = sys_get_temp_dir() . '/phpunit_contacts_' . uniqid() . '.csv';
        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        file_put_contents($path, implode("\n", $lines));
        $this->tmpFiles[] = $path;

        return $path;
    }

    public function testCreateContactNormalizesPhoneAndRejectsInvalid(): void
    {
        $id = $this->service->createContact('PHPUNITCONTACT-1', 'Amadou', '622990001', 'a@example.com');

        $stmt = $this->pdo->prepare("SELECT telephone FROM contacts_v2 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $this->assertSame('+224622990001', $stmt->fetchColumn());

        $this->expectException(\Exception::class);
        $this->service->createContact('PHPUNITCONTACT-2', 'X', '12345');
    }

    public function testCreateContactUpsertsOnDuplicatePhone(): void
    {
        $id1 = $this->service->createContact('PHPUNITCONTACT-3', 'Old', '622990002');
        $id2 = $this->service->createContact('PHPUNITCONTACT-3', 'New', '622990002');

        $this->assertSame($id1, $id2, 'same phone number must update the existing contact, not duplicate it');
    }

    public function testGroupCreationAndMembership(): void
    {
        $groupId = $this->service->createGroup('PHPUNITGROUP-1', 'desc', 'phpunit');
        $this->groupIds[] = $groupId;
        $contactId = $this->service->createContact('PHPUNITCONTACT-4', 'A', '622990003');

        $this->service->addContactToGroup($groupId, $contactId);
        $members = $this->service->allContacts($groupId);
        $this->assertCount(1, $members);
        $this->assertSame('PHPUNITCONTACT-4', $members[0]['nom']);

        $this->service->removeContactFromGroup($groupId, $contactId);
        $this->assertCount(0, $this->service->allContacts($groupId));
    }

    public function testImportValidatesDeduplicatesAndReportsErrors(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITCONTACT-5', 'A', '622990004', 'a@x.com'],
            ['PHPUNITCONTACT-6', 'B', 'bad-phone', ''],
            ['PHPUNITCONTACT-7', 'C', '622990004', ''], // doublon du premier (même téléphone)
        ]);

        $report = $this->service->importFile($path, 'csv', null, 'phpunit');

        $this->assertSame(3, $report['total']);
        $this->assertSame(1, $report['valides']);
        $this->assertSame(1, $report['invalides']);
        $this->assertSame(1, $report['doublons']);
        $this->assertCount(2, $report['errors']);
    }

    public function testImportIntoGroupAddsContactsDirectly(): void
    {
        $groupId = $this->service->createGroup('PHPUNITGROUP-2', '', 'phpunit');
        $this->groupIds[] = $groupId;

        $path = $this->makeCsv([
            ['PHPUNITCONTACT-8', 'A', '622990005', ''],
        ]);
        $this->service->importFile($path, 'csv', $groupId, 'phpunit');

        $members = $this->service->allContacts($groupId);
        $this->assertCount(1, $members);
        $this->assertSame('PHPUNITCONTACT-8', $members[0]['nom']);
    }

    public function testSearchFiltersContacts(): void
    {
        $this->service->createContact('PHPUNITCONTACT-Zoumanigui', 'Kadiatou', '622990006');

        $results = $this->service->allContacts(null, 'Zoumanigui');
        $this->assertNotEmpty($results);
        $this->assertSame('PHPUNITCONTACT-Zoumanigui', $results[0]['nom']);

        $this->assertEmpty(array_filter($this->service->allContacts(null, 'NoSuchNameXYZ'), fn($c) => $c['nom'] === 'PHPUNITCONTACT-Zoumanigui'));
    }

    public function testSampleContactReturnsNullWhenNoneExist(): void
    {
        $this->assertNull($this->service->sampleContact(999999999));
    }

    public function testSampleContactReturnsOneRealContactFromTheGroup(): void
    {
        $groupId = $this->service->createGroup('PHPUNITGROUP-Sample');
        $this->groupIds[] = $groupId;
        $otherContactId = $this->service->createContact('PHPUNITCONTACT-OutsideGroup', 'X', '622990007');
        $inGroupId = $this->service->createContact('PHPUNITCONTACT-InGroup', 'Y', '622990008');
        $this->service->addContactToGroup($groupId, $inGroupId);

        $sample = $this->service->sampleContact($groupId);

        $this->assertNotNull($sample);
        $this->assertSame('PHPUNITCONTACT-InGroup', $sample['nom']);
        $this->assertNotSame($otherContactId, $sample['id']);
    }

    public function testSampleContactWithoutGroupPicksAnyContact(): void
    {
        $this->service->createContact('PHPUNITCONTACT-AnySample', 'Z', '622990009');

        $sample = $this->service->sampleContact(null);

        $this->assertNotNull($sample);
        $this->assertArrayHasKey('telephone', $sample);
    }
}
