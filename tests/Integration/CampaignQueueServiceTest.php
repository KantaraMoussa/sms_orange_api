<?php

namespace Tests\Integration;

use App\Services\CampaignQueueService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database (no test database is configured for
 * this project — see DATABASE.md). Every row created here is tagged
 * type='phpunit_test' and removed in tearDown(), regardless of test outcome.
 * All sends go through dry_run=true, so OrangeSmsService::sendSms() is never
 * actually invoked — no real SMS is sent and no Orange credentials are
 * required for these tests to pass.
 *
 * Covers cahier des charges §59: création de campagne, sélection des
 * destinataires, idempotence, progression, pause/reprise, annulation.
 */
class CampaignQueueServiceTest extends TestCase
{
    private PDO $pdo;
    private CampaignQueueService $queue;
    /** @var int[] */
    private array $createdCampaignIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->queue = new CampaignQueueService($this->pdo, orangeSms());
    }

    protected function tearDown(): void
    {
        if (empty($this->createdCampaignIds)) {
            return;
        }

        $ids = implode(',', array_map('intval', $this->createdCampaignIds));
        $this->pdo->exec("DELETE FROM messages WHERE campagne_id IN ($ids)");
        $this->pdo->exec("DELETE FROM campagne WHERE id IN ($ids) AND type = 'phpunit_test'");
    }

    private function makeCampaign(string $nom = 'PHPUnit test campaign', int $batchSize = 50): int
    {
        $id = $this->queue->createCampaign(1, $nom, 'created by the automated test suite', 'phpunit_test', 'phpunit', $batchSize);
        $this->createdCampaignIds[] = $id;
        return $id;
    }

    private function fakeRows(int $count, string $prefix = '6299'): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'destinataire' => $prefix . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'contenu' => "Message de test #$i",
            ];
        }
        return $rows;
    }

    public function testNewCampaignStartsAsDraft(): void
    {
        $id = $this->makeCampaign();

        $campagne = getSingleCampagne($id);

        $this->assertSame('DRAFT', $campagne['statut']);
        $this->assertSame(0, (int) $campagne['total_destinataires']);
    }

    public function testAddRecipientsValidatesAndDeduplicates(): void
    {
        $id = $this->makeCampaign();
        $rows = $this->fakeRows(5);
        $rows[] = ['destinataire' => '12345', 'contenu' => 'invalide']; // invalid phone
        $duplicateOfFirst = $rows[0];

        $result = $this->queue->addRecipients($id, $rows);
        $this->assertSame(['added' => 5, 'duplicates' => 0, 'invalid' => 1], $result);

        // Idempotence (§7): re-adding the exact same recipient must not create a second row.
        $result2 = $this->queue->addRecipients($id, [$duplicateOfFirst]);
        $this->assertSame(['added' => 0, 'duplicates' => 1, 'invalid' => 0], $result2);

        $campagne = getSingleCampagne($id);
        $this->assertSame(5, (int) $campagne['total_destinataires']);
    }

    public function testClaimBatchNeverReturnsTheSameRecipientTwice(): void
    {
        $id = $this->makeCampaign('PHPUnit claim test', batchSize: 3);
        $this->queue->addRecipients($id, $this->fakeRows(7));
        $this->queue->queueCampaign($id, dryRun: true);

        $batch1 = $this->queue->claimBatch($id, 3);
        $batch2 = $this->queue->claimBatch($id, 3);
        $batch3 = $this->queue->claimBatch($id, 3);
        $batch4 = $this->queue->claimBatch($id, 3); // nothing left

        $allIds = array_merge(
            array_column($batch1, 'id'),
            array_column($batch2, 'id'),
            array_column($batch3, 'id')
        );

        $this->assertCount(7, $allIds, 'expected exactly 7 recipients claimed across all batches');
        $this->assertCount(7, array_unique($allIds), 'no recipient should be claimed twice');
        $this->assertCount(0, $batch4);
    }

    public function testFullLifecycleCompletesInASingleBatch(): void
    {
        // Regression test for a real bug found during manual testing: a campaign
        // that finishes within its very first batch used to stay stuck on QUEUED
        // forever instead of flipping to COMPLETED (see AUDIT.md, Phase 7/8).
        $id = $this->makeCampaign('PHPUnit single-batch completion', batchSize: 50);
        $this->queue->addRecipients($id, $this->fakeRows(3));
        $this->queue->queueCampaign($id, dryRun: true);

        $batch = $this->queue->claimBatch($id, 50);
        foreach ($batch as $recipient) {
            $this->queue->processRecipient($recipient, $id, dryRun: true);
        }
        $progress = $this->queue->getProgress($id);

        $this->assertTrue($progress['done']);
        $this->assertSame(3, $progress['sent']);
        $this->assertSame(0, $progress['failed']);
        $this->assertSame('COMPLETED', getSingleCampagne($id)['statut']);
    }

    public function testPauseResumeAndCancelTransitions(): void
    {
        $id = $this->makeCampaign('PHPUnit pause/resume/cancel');
        $this->queue->addRecipients($id, $this->fakeRows(2));
        $this->queue->queueCampaign($id);
        $this->pdo->prepare("UPDATE campagne SET statut = 'RUNNING' WHERE id = :id")->execute([':id' => $id]);

        $this->queue->pause($id);
        $this->assertSame('PAUSED', getSingleCampagne($id)['statut']);

        $this->queue->resume($id);
        $this->assertSame('QUEUED', getSingleCampagne($id)['statut']);

        $this->queue->cancel($id);
        $campagne = getSingleCampagne($id);
        $this->assertSame('CANCELLED', $campagne['statut']);

        $statuses = array_column(getMessageCampagne($id), 'statut');
        $this->assertSame(['annule', 'annule'], $statuses, 'pending recipients should be marked annule, not silently deleted');
    }

    public function testRetryFailedOnlyRequeuesRetryableCodes(): void
    {
        $id = $this->makeCampaign('PHPUnit retry test');
        $this->queue->addRecipients($id, $this->fakeRows(2));
        $messages = getMessageCampagne($id);

        // Simulate one retryable failure (API_ERROR) and one definitive failure (INVALID_PHONE).
        $this->pdo->prepare("UPDATE messages SET statut = 'echec', error_code = 'API_ERROR', tentative_count = 1 WHERE id = :id")
            ->execute([':id' => $messages[0]['id']]);
        $this->pdo->prepare("UPDATE messages SET statut = 'echec', error_code = 'INVALID_PHONE', tentative_count = 1 WHERE id = :id")
            ->execute([':id' => $messages[1]['id']]);

        $requeued = $this->queue->retryFailed($id);

        $this->assertSame(1, $requeued, 'only the retryable failure should be requeued');

        $stmt = $this->pdo->prepare("SELECT statut FROM messages WHERE id = :id");
        $stmt->execute([':id' => $messages[0]['id']]);
        $this->assertSame('en_attente', $stmt->fetchColumn());

        $stmt->execute([':id' => $messages[1]['id']]);
        $this->assertSame('echec', $stmt->fetchColumn(), 'a definitive failure must stay failed, never silently retried');
    }

    public function testEstimateSmsNeededSumsSegmentsNotJustRecipientCount(): void
    {
        $id = $this->makeCampaign('PHPUnit estimate test');
        // Un message court (1 segment) + un message long forçant 2 segments GSM-7 (>160 caractères).
        $longMessage = str_repeat('A', 200);
        $this->queue->addRecipients($id, [
            ['destinataire' => '622990100', 'contenu' => 'Court'],
            ['destinataire' => '622990101', 'contenu' => $longMessage],
        ]);

        $needed = estimateSmsNeeded($id);

        $this->assertSame(3, $needed, '1 (short) + 2 (long, >160 GSM-7 chars) = 3 segments, not 2 recipients');
    }

    public function testEstimateSmsNeededIgnoresAlreadyProcessedMessages(): void
    {
        $id = $this->makeCampaign('PHPUnit estimate processed test');
        $this->queue->addRecipients($id, $this->fakeRows(2));
        $messages = getMessageCampagne($id);
        $this->pdo->prepare("UPDATE messages SET statut = 'envoye' WHERE id = :id")->execute([':id' => $messages[0]['id']]);

        $needed = estimateSmsNeeded($id);

        $this->assertSame(1, $needed, 'only en_attente messages count toward what launching would still cost');
    }

    /**
     * Reproduit exactement ce que fait server/app.php::create_campaign_recipients
     * (audience "tous les contacts" / "un groupe") : un message avec variables
     * rendu individuellement par destinataire via MessageTemplateService avant
     * addRecipients() — cahier des charges §16-17.
     */
    public function testRecipientsBuiltFromAudienceHaveVariablesRenderedPerContact(): void
    {
        $id = $this->makeCampaign('PHPUnit audience render test');
        $contacts = [
            ['nom' => 'Diallo', 'prenom' => 'Fatoumata', 'telephone' => '+224622990200'],
            ['nom' => 'Barry', 'prenom' => 'Ibrahima', 'telephone' => '+224622990201'],
        ];

        $rows = [];
        foreach ($contacts as $c) {
            $rendered = \App\Services\MessageTemplateService::render('Bonjour {{prenom}} {{nom}} !', $c);
            $rows[] = ['destinataire' => $c['telephone'], 'contenu' => $rendered['message'], 'nom' => $c['nom'], 'prenom' => $c['prenom']];
        }
        $this->queue->addRecipients($id, $rows);

        $messages = getMessageCampagne($id);
        $contents = array_column($messages, 'contenu');
        $this->assertContains('Bonjour Fatoumata Diallo !', $contents);
        $this->assertContains('Bonjour Ibrahima Barry !', $contents);
    }
}
