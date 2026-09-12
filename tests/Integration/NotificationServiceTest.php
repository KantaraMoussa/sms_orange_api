<?php

namespace Tests\Integration;

use App\Services\CampaignQueueService;
use App\Services\NotificationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database. All rows use type
 * 'phpunit_test_notif' and are deleted in tearDown().
 *
 * Covers cahier des charges V2.0 §73 : centre de notifications.
 */
class NotificationServiceTest extends TestCase
{
    private PDO $pdo;
    private NotificationService $service;
    /** @var int[] */
    private array $createdCampaignIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->service = new NotificationService($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM notifications WHERE type = 'phpunit_test_notif'");
        if (!empty($this->createdCampaignIds)) {
            $ids = implode(',', array_map('intval', $this->createdCampaignIds));
            $this->pdo->exec("DELETE FROM campagne WHERE id IN ($ids) AND type = 'phpunit_test'");
        }
    }

    public function testCreateAndRecent(): void
    {
        $this->service->create('phpunit_test_notif', 'Titre test', 'Message test');

        $recent = $this->service->recent(5);
        $this->assertNotEmpty($recent);
        $this->assertSame('Titre test', $recent[0]['titre']);
        $this->assertFalse(in_array($recent[0]['lu'], [true, 't', '1', 1], true), 'a new notification must start unread');
    }

    public function testUnreadCountAndMarkAllRead(): void
    {
        $this->service->create('phpunit_test_notif', 'A');
        $this->service->create('phpunit_test_notif', 'B');

        $before = $this->service->unreadCount();
        $this->service->markAllRead();
        $after = $this->service->unreadCount();

        $this->assertGreaterThanOrEqual(2, $before);
        $this->assertSame(0, $after);
    }

    public function testCreateUnlessRecentDuplicateSkipsWithinWindow(): void
    {
        $this->service->createUnlessRecentDuplicate('phpunit_test_notif', 'First', 'msg', null, 60);
        $this->service->createUnlessRecentDuplicate('phpunit_test_notif', 'Second', 'msg', null, 60);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'phpunit_test_notif'");
        $stmt->execute();

        $this->assertSame(1, (int) $stmt->fetchColumn(), 'the second call within the dedupe window must not create a new row');
    }

    public function testCreateUnlessRecentDuplicateIsScopedPerCampaign(): void
    {
        $queue = new CampaignQueueService($this->pdo, orangeSms());
        $campaignA = $queue->createCampaign('PHPUnit notif campaign A', '', 'phpunit_test', 'phpunit', 50);
        $campaignB = $queue->createCampaign('PHPUnit notif campaign B', '', 'phpunit_test', 'phpunit', 50);
        $this->createdCampaignIds = [$campaignA, $campaignB];

        $this->service->createUnlessRecentDuplicate('phpunit_test_notif', 'Campaign A done', 'msg', $campaignA, 60);
        $this->service->createUnlessRecentDuplicate('phpunit_test_notif', 'Campaign B done', 'msg', $campaignB, 60);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'phpunit_test_notif'");
        $stmt->execute();

        $this->assertSame(2, (int) $stmt->fetchColumn(), 'different campaign_id must not be deduped against each other');
    }

    public function testMarkRead(): void
    {
        $id = $this->service->create('phpunit_test_notif', 'To be read');
        $this->service->markRead($id);

        $stmt = $this->pdo->prepare("SELECT lu FROM notifications WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $this->assertTrue(in_array($stmt->fetchColumn(), [true, 't', '1', 1], true));
    }
}
