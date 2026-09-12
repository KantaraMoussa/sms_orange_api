<?php

namespace Tests\Integration;

use App\Services\ActivityLogger;
use App\Services\CampaignQueueService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database. All rows use user_nom
 * 'phpunit_activity_test' and are deleted in tearDown().
 *
 * Covers cahier des charges V2.0 §35 (journalisation) / §64 (audit trail) :
 * qui a créé/lancé/mis en pause/repris/annulé/réessayé une campagne.
 */
class ActivityLoggerTest extends TestCase
{
    private PDO $pdo;
    private ActivityLogger $logger;
    /** @var int[] */
    private array $createdCampaignIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->logger = new ActivityLogger($this->pdo, 1);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM activity_logs WHERE user_nom = 'phpunit_activity_test'");
        if (!empty($this->createdCampaignIds)) {
            $ids = implode(',', array_map('intval', $this->createdCampaignIds));
            $this->pdo->exec("DELETE FROM activity_logs WHERE campagne_id IN ($ids)");
            $this->pdo->exec("DELETE FROM messages WHERE campagne_id IN ($ids)");
            $this->pdo->exec("DELETE FROM campagne WHERE id IN ($ids) AND type = 'phpunit_test'");
        }
    }

    public function testLogAndRecentRoundTrip(): void
    {
        $this->logger->log('connexion', null, 'phpunit_activity_test');

        $recent = $this->logger->recent(5);

        $this->assertNotEmpty($recent);
        $this->assertSame('connexion', $recent[0]['action']);
        $this->assertSame('phpunit_activity_test', $recent[0]['user_nom']);
    }

    public function testForCampaignReturnsOnlyThatCampaignsEntries(): void
    {
        $queue = new CampaignQueueService($this->pdo, orangeSms());
        $campaignId = $queue->createCampaign(1, 'PHPUnit activity log campaign', '', 'phpunit_test', 'phpunit_activity_test', 50);
        $this->createdCampaignIds[] = $campaignId;

        $this->logger->log('creation_campagne', $campaignId, 'phpunit_activity_test', 'test');
        $this->logger->log('lancement_campagne', $campaignId, 'phpunit_activity_test');
        $this->logger->log('connexion', null, 'phpunit_activity_test'); // sans lien avec la campagne

        $entries = $this->logger->forCampaign($campaignId);

        $this->assertCount(2, $entries);
        $actions = array_column($entries, 'action');
        $this->assertContains('creation_campagne', $actions);
        $this->assertContains('lancement_campagne', $actions);
    }

    public function testRecentJoinsCampaignName(): void
    {
        $queue = new CampaignQueueService($this->pdo, orangeSms());
        $campaignId = $queue->createCampaign(1, 'PHPUnit activity log join test', '', 'phpunit_test', 'phpunit_activity_test', 50);
        $this->createdCampaignIds[] = $campaignId;

        $this->logger->log('creation_campagne', $campaignId, 'phpunit_activity_test');

        $recent = $this->logger->recent(5);
        $match = array_values(array_filter($recent, fn($r) => (int) $r['campagne_id'] === $campaignId));

        $this->assertCount(1, $match);
        $this->assertSame('PHPUnit activity log join test', $match[0]['campagne_nom']);
    }
}
