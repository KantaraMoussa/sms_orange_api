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

    public function testGetSmsSentTodayCountsOnlyTodaysSentMessages(): void
    {
        $id = $this->makeCampaign('PHPUnit today test');
        $this->queue->addRecipients($id, $this->fakeRows(2));
        $messages = getMessageCampagne($id);
        $this->pdo->prepare("UPDATE messages SET statut = 'envoye', date_traitement = NOW() WHERE id = :id")
            ->execute([':id' => $messages[0]['id']]);
        $this->pdo->prepare("UPDATE messages SET statut = 'envoye', date_traitement = NOW() - INTERVAL '2 days' WHERE id = :id")
            ->execute([':id' => $messages[1]['id']]);

        $this->assertSame(1, getSmsSentToday(1));
    }

    public function testScheduleSetsStatutAndScheduledAt(): void
    {
        $id = $this->makeCampaign('PHPUnit schedule test');
        $when = new \DateTime('+1 hour');

        $this->queue->schedule($id, $when, true);

        $campagne = getSingleCampagne($id);
        $this->assertSame('SCHEDULED', $campagne['statut']);
        // scheduled_at est stocké en UTC (voir CampaignQueueService::schedule()) —
        // il faut le relire comme tel avant de comparer, sinon on retombe dans
        // le même piège de fuseau que celui que la conversion corrige.
        $storedUtc = new \DateTime($campagne['scheduled_at'], new \DateTimeZone('UTC'));
        $expectedUtc = (clone $when)->setTimezone(new \DateTimeZone('UTC'));
        $this->assertSame($expectedUtc->format('Y-m-d H:i'), $storedUtc->format('Y-m-d H:i'));
        $this->assertTrue(in_array($campagne['dry_run'], [true, 't', '1', 1], true));
    }

    public function testUnscheduleReturnsCampaignToDraft(): void
    {
        $id = $this->makeCampaign('PHPUnit unschedule test');
        $this->queue->schedule($id, new \DateTime('+1 hour'));

        $this->queue->unschedule($id);

        $campagne = getSingleCampagne($id);
        $this->assertSame('DRAFT', $campagne['statut']);
        $this->assertNull($campagne['scheduled_at']);
    }

    public function testUnscheduleDoesNothingToACampaignThatIsNotScheduled(): void
    {
        $id = $this->makeCampaign('PHPUnit unschedule noop test');
        $this->pdo->exec("UPDATE campagne SET statut = 'RUNNING' WHERE id = $id");

        $this->queue->unschedule($id);

        $this->assertSame('RUNNING', getSingleCampagne($id)['statut'], 'unschedule() must only affect SCHEDULED campaigns');
    }

    public function testPromoteDueCampaignsPromotesOnlyPastDueScheduledOnes(): void
    {
        $due = $this->makeCampaign('PHPUnit promote due test');
        $this->queue->schedule($due, new \DateTime('-1 minute'));

        $notYetDue = $this->makeCampaign('PHPUnit promote not-due test');
        $this->queue->schedule($notYetDue, new \DateTime('+1 hour'));

        $promoted = $this->queue->promoteDueCampaigns();

        $this->assertGreaterThanOrEqual(1, $promoted);
        $this->assertSame('QUEUED', getSingleCampagne($due)['statut']);
        $this->assertSame('SCHEDULED', getSingleCampagne($notYetDue)['statut'], 'a campaign scheduled in the future must not be promoted yet');
    }

    public function testConfigureRecurrenceRejectsInvalidFrequency(): void
    {
        $id = $this->makeCampaign('PHPUnit recurrence invalid freq');

        $this->expectException(\Exception::class);
        $this->queue->configureRecurrence($id, 'hourly', 'Bonjour', 'all', null, null);
    }

    public function testConfigureRecurrenceSetsFieldsAndNextOccurrence(): void
    {
        $id = $this->makeCampaign('PHPUnit recurrence config');

        $this->queue->configureRecurrence($id, 'weekly', 'Bonjour {{prenom}}', 'all', null, null);

        $row = getSingleCampagne($id);
        $this->assertSame('weekly', $row['recurrence']);
        $this->assertSame('all', $row['recurrence_audience_type']);
        $this->assertSame('Bonjour {{prenom}}', $row['message_template']);
        $this->assertNotNull($row['next_occurrence_at']);
        // +7 jours, à la minute près (marge pour le temps d'exécution du test).
        $expected = new \DateTime('+7 days', new \DateTimeZone('UTC'));
        $actual = new \DateTime($row['next_occurrence_at'], new \DateTimeZone('UTC'));
        $this->assertLessThan(60, abs($expected->getTimestamp() - $actual->getTimestamp()));
    }

    public function testStopRecurrenceClearsFields(): void
    {
        $id = $this->makeCampaign('PHPUnit recurrence stop');
        $this->queue->configureRecurrence($id, 'daily', 'Bonjour', 'all', null, null);

        $this->queue->stopRecurrence($id);

        $row = getSingleCampagne($id);
        $this->assertNull($row['recurrence']);
        $this->assertNull($row['next_occurrence_at']);
    }

    public function testProcessRecurringCampaignsSpawnsOccurrenceForAllContacts(): void
    {
        $contacts = new \App\Services\ContactService($this->pdo, 1);
        $contactId = $contacts->createContact('PHPUNITRECUR-All', 'Test', '622997001');

        $id = $this->makeCampaign('PHPUnit recurrence spawn all');
        $this->queue->configureRecurrence($id, 'daily', 'Bonjour {{prenom}} !', 'all', null, null);
        // Force l'échéance dans le passé pour simuler qu'elle est due.
        $this->pdo->exec("UPDATE campagne SET next_occurrence_at = NOW() - INTERVAL '1 minute' WHERE id = $id");

        $spawned = $this->queue->processRecurringCampaigns();

        $this->assertNotEmpty($spawned);
        $newId = end($spawned);
        $this->createdCampaignIds[] = $newId;
        $messages = getMessageCampagne($newId);
        $this->assertNotEmpty($messages);
        $this->assertContains('Bonjour Test !', array_column($messages, 'contenu'));

        // L'échéance du parent doit avoir avancé, pas rester dans le passé.
        $parent = getSingleCampagne($id);
        $this->assertGreaterThan(new \DateTime('now', new \DateTimeZone('UTC')), new \DateTime($parent['next_occurrence_at'], new \DateTimeZone('UTC')));

        $this->pdo->exec("DELETE FROM contacts_v2 WHERE id = $contactId");
    }

    public function testProcessRecurringCampaignsSkipsButAdvancesWhenAudienceEmpty(): void
    {
        $id = $this->makeCampaign('PHPUnit recurrence empty audience');
        // Groupe inexistant : audience toujours vide.
        $this->queue->configureRecurrence($id, 'daily', 'Bonjour', 'group', 999999999, null);
        $this->pdo->exec("UPDATE campagne SET next_occurrence_at = NOW() - INTERVAL '1 minute' WHERE id = $id");
        $before = getSingleCampagne($id)['next_occurrence_at'];

        $spawned = $this->queue->processRecurringCampaigns();

        $after = getSingleCampagne($id)['next_occurrence_at'];
        $this->assertNotEquals($before, $after, 'next_occurrence_at must advance even when no campaign was spawned, to avoid looping forever');
    }

    public function testProcessRecurringCampaignsIgnoresNotYetDueCampaigns(): void
    {
        $id = $this->makeCampaign('PHPUnit recurrence not due');
        $this->queue->configureRecurrence($id, 'monthly', 'Bonjour', 'all', null, null);
        // configureRecurrence() place déjà next_occurrence_at dans le futur (+1 mois).

        $spawnedBefore = $this->queue->processRecurringCampaigns();

        $this->assertNotContains($id, $spawnedBefore);
        $this->assertSame('monthly', getSingleCampagne($id)['recurrence'], 'a not-yet-due campaign must be left untouched');
    }

    public function testGetActiveCampaignsCountIncludesQueuedRunningAndPausedOnly(): void
    {
        $draft = $this->makeCampaign('PHPUnit active-count draft');
        $queued = $this->makeCampaign('PHPUnit active-count queued');
        $this->pdo->exec("UPDATE campagne SET statut = 'QUEUED' WHERE id = $queued");
        $completed = $this->makeCampaign('PHPUnit active-count completed');
        $this->pdo->exec("UPDATE campagne SET statut = 'COMPLETED' WHERE id = $completed");

        $before = getActiveCampaignsCount(1);
        // draft/completed must not count; only the QUEUED one should have added +1.
        $this->assertGreaterThanOrEqual(1, $before);

        $this->pdo->exec("UPDATE campagne SET statut = 'DRAFT' WHERE id = $queued");
        $after = getActiveCampaignsCount(1);
        $this->assertSame($before - 1, $after, 'moving the queued campaign back to DRAFT must remove it from the active count');
    }
}
