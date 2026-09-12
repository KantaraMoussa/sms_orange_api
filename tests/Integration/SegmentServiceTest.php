<?php

namespace Tests\Integration;

use App\Services\ContactService;
use App\Services\SegmentService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database. All rows use nom/telephone
 * préfixés "PHPUNITSEG" et sont supprimées dans tearDown().
 *
 * Covers cahier des charges V2.0 §13 : segments dynamiques.
 */
class SegmentServiceTest extends TestCase
{
    private PDO $pdo;
    private SegmentService $segments;
    private ContactService $contacts;
    /** @var int[] */
    private array $segmentIds = [];
    /** @var int[] */
    private array $groupIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->segments = new SegmentService($this->pdo, 1);
        $this->contacts = new ContactService($this->pdo, 1);
    }

    protected function tearDown(): void
    {
        if (!empty($this->segmentIds)) {
            $ids = implode(',', array_map('intval', $this->segmentIds));
            $this->pdo->exec("DELETE FROM segments WHERE id IN ($ids)");
        }
        if (!empty($this->groupIds)) {
            $ids = implode(',', array_map('intval', $this->groupIds));
            $this->pdo->exec("DELETE FROM groupe_contacts_v2 WHERE groupe_id IN ($ids)");
            $this->pdo->exec("DELETE FROM groupes_v2 WHERE id IN ($ids)");
        }
        $this->pdo->exec("DELETE FROM contacts_v2 WHERE nom LIKE 'PHPUNITSEG%' AND organization_id = 1");
    }

    public function testCreateFindAndDelete(): void
    {
        $id = $this->segments->create('PHPUNITSEG-Basic', ['statut' => 'actif']);
        $this->segmentIds[] = $id;

        $found = $this->segments->find($id);
        $this->assertNotNull($found);
        $this->assertSame('PHPUNITSEG-Basic', $found['nom']);
        $this->assertSame('actif', $found['criteria']['statut']);

        $this->segments->delete($id);
        $this->assertNull($this->segments->find($id));
    }

    public function testResolveContactsFiltersBySearch(): void
    {
        $this->contacts->createContact('PHPUNITSEG-Zoumanigui', 'A', '622994001');
        $this->contacts->createContact('PHPUNITSEG-Other', 'B', '622994002');

        $id = $this->segments->create('PHPUNITSEG-Search', ['search' => 'Zoumanigui']);
        $this->segmentIds[] = $id;

        $matched = $this->segments->resolveContacts($id);
        $this->assertCount(1, $matched);
        $this->assertSame('PHPUNITSEG-Zoumanigui', $matched[0]['nom']);
    }

    public function testResolveContactsFiltersByGroup(): void
    {
        $groupId = $this->contacts->createGroup('PHPUNITGROUP-SEG');
        $this->groupIds[] = $groupId;
        $inGroup = $this->contacts->createContact('PHPUNITSEG-InGroup', 'A', '622994003');
        $this->contacts->createContact('PHPUNITSEG-OutGroup', 'B', '622994004');
        $this->contacts->addContactToGroup($groupId, $inGroup);

        $id = $this->segments->create('PHPUNITSEG-Group', ['groupe_id' => $groupId]);
        $this->segmentIds[] = $id;

        $matched = $this->segments->resolveContacts($id);
        $this->assertCount(1, $matched);
        $this->assertSame('PHPUNITSEG-InGroup', $matched[0]['nom']);
    }

    public function testResolveContactsCombinesCriteriaWithAnd(): void
    {
        $groupId = $this->contacts->createGroup('PHPUNITGROUP-SEGAND');
        $this->groupIds[] = $groupId;
        $matches = $this->contacts->createContact('PHPUNITSEG-Combo', 'A', '622994005');
        $inGroupOnly = $this->contacts->createContact('PHPUNITSEG-InGroupOnlyXYZ', 'B', '622994006');
        $this->contacts->addContactToGroup($groupId, $matches);
        $this->contacts->addContactToGroup($groupId, $inGroupOnly);

        // Cherche "Combo" ET dans le groupe : seul le premier contact correspond aux deux.
        $id = $this->segments->create('PHPUNITSEG-Combo', ['search' => 'Combo', 'groupe_id' => $groupId]);
        $this->segmentIds[] = $id;

        $matched = $this->segments->resolveContacts($id);
        $this->assertCount(1, $matched);
        $this->assertSame('PHPUNITSEG-Combo', $matched[0]['nom']);
    }

    public function testCountContactsMatchesResolveContactsCount(): void
    {
        $this->contacts->createContact('PHPUNITSEG-Count1', 'A', '622994007');
        $this->contacts->createContact('PHPUNITSEG-Count2', 'B', '622994008');

        $id = $this->segments->create('PHPUNITSEG-Count', ['search' => 'PHPUNITSEG-Count']);
        $this->segmentIds[] = $id;

        $this->assertSame(2, $this->segments->countContacts($id));
        $this->assertCount(2, $this->segments->resolveContacts($id));
    }

    public function testPreviewCountWorksWithoutCreatingASegment(): void
    {
        $this->contacts->createContact('PHPUNITSEG-Preview', 'A', '622994009');

        $count = $this->segments->previewCount(['search' => 'PHPUNITSEG-Preview']);

        $this->assertSame(1, $count);
        $this->assertEmpty($this->segments->all());
    }

    public function testEmptyCriteriaMatchesAllOrganizationContacts(): void
    {
        $before = $this->segments->previewCount([]);
        $this->contacts->createContact('PHPUNITSEG-Empty', 'A', '622994010');
        $after = $this->segments->previewCount([]);

        $this->assertSame($before + 1, $after);
    }

    public function testSampleContactReturnsAMatchingContact(): void
    {
        $this->contacts->createContact('PHPUNITSEG-Sample', 'A', '622994011');
        $id = $this->segments->create('PHPUNITSEG-Sample', ['search' => 'PHPUNITSEG-Sample']);
        $this->segmentIds[] = $id;

        $sample = $this->segments->sampleContact($id);

        $this->assertNotNull($sample);
        $this->assertSame('PHPUNITSEG-Sample', $sample['nom']);
    }

    public function testSampleContactReturnsNullWhenNoneMatch(): void
    {
        $id = $this->segments->create('PHPUNITSEG-NoMatch', ['search' => 'NoSuchContactXYZ123']);
        $this->segmentIds[] = $id;

        $this->assertNull($this->segments->sampleContact($id));
    }

    public function testSegmentsAreIsolatedBetweenOrganizations(): void
    {
        $otherOrgSegments = new SegmentService($this->pdo, 999999);
        $id = $this->segments->create('PHPUNITSEG-Isolation', []);
        $this->segmentIds[] = $id;

        $this->assertNull($otherOrgSegments->find($id));
        $this->assertNotContains('PHPUNITSEG-Isolation', array_column($otherOrgSegments->all(), 'nom'));
    }
}
