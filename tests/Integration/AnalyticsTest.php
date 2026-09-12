<?php

namespace Tests\Integration;

use App\Services\CampaignQueueService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Filtres analytics avancés (§33) : période (préréglages + personnalisée)
 * et campagne, appliqués à getGlobalSmsStats/getCampaignsReport/getTopErrors.
 *
 * Runs against the real apiSms database. All rows use type='phpunit_test'
 * et sont supprimées dans tearDown().
 */
class AnalyticsTest extends TestCase
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

    private function makeCampaign(string $nom): int
    {
        $id = $this->queue->createCampaign(1, $nom, '', 'phpunit_test', 'phpunit', 50);
        $this->createdCampaignIds[] = $id;

        return $id;
    }

    public function testResolveDateRangePresetToday(): void
    {
        $range = resolveDateRangePreset('today', null, null);
        $this->assertSame(date('Y-m-d'), $range['from']);
        $this->assertSame(date('Y-m-d'), $range['to']);
    }

    public function testResolveDateRangePreset7Days(): void
    {
        $range = resolveDateRangePreset('7d', null, null);
        $this->assertSame(date('Y-m-d', strtotime('-6 days')), $range['from']);
        $this->assertSame(date('Y-m-d'), $range['to']);
    }

    public function testResolveDateRangePresetCustomPassesThroughInput(): void
    {
        $range = resolveDateRangePreset('custom', '2026-01-01', '2026-01-31');
        $this->assertSame('2026-01-01', $range['from']);
        $this->assertSame('2026-01-31', $range['to']);
    }

    public function testResolveDateRangePresetUnknownOrEmptyMeansNoFilter(): void
    {
        $this->assertSame(['from' => null, 'to' => null], resolveDateRangePreset('', null, null));
        $this->assertSame(['from' => null, 'to' => null], resolveDateRangePreset('bogus', null, null));
    }

    public function testGetGlobalSmsStatsFiltersByDateRangeButNotPendingCount(): void
    {
        $id = $this->makeCampaign('PHPUnit analytics date test');
        $this->queue->addRecipients($id, [
            ['destinataire' => '622996001', 'contenu' => 'A'],
            ['destinataire' => '622996002', 'contenu' => 'B'],
            ['destinataire' => '622996003', 'contenu' => 'C'],
        ]);
        $messages = getMessageCampagne($id);
        // Un envoyé "aujourd'hui", un envoyé "il y a 10 jours", un encore en attente.
        $this->pdo->prepare("UPDATE messages SET statut = 'envoye', date_traitement = NOW() WHERE id = :id")
            ->execute([':id' => $messages[0]['id']]);
        $this->pdo->prepare("UPDATE messages SET statut = 'envoye', date_traitement = NOW() - INTERVAL '10 days' WHERE id = :id")
            ->execute([':id' => $messages[1]['id']]);

        $today = date('Y-m-d');
        $stats = getGlobalSmsStats(1, $today, $today, $id);

        $this->assertSame(1, $stats['envoyes'], 'only the send from today must count within a today-only range');
        $this->assertSame(1, $stats['en_attente'], 'pending count must not be zeroed out by a date filter (date_traitement is NULL until processed)');
    }

    public function testGetGlobalSmsStatsFiltersByCampaign(): void
    {
        $idA = $this->makeCampaign('PHPUnit analytics campaign filter A');
        $idB = $this->makeCampaign('PHPUnit analytics campaign filter B');
        $this->queue->addRecipients($idA, [['destinataire' => '622996004', 'contenu' => 'A']]);
        $this->queue->addRecipients($idB, [['destinataire' => '622996005', 'contenu' => 'B']]);
        $msgA = getMessageCampagne($idA)[0];
        $msgB = getMessageCampagne($idB)[0];
        $this->pdo->prepare("UPDATE messages SET statut = 'envoye', date_traitement = NOW() WHERE id = :id")->execute([':id' => $msgA['id']]);
        $this->pdo->prepare("UPDATE messages SET statut = 'envoye', date_traitement = NOW() WHERE id = :id")->execute([':id' => $msgB['id']]);

        $statsA = getGlobalSmsStats(1, null, null, $idA);

        $this->assertSame(1, $statsA['envoyes'], 'campaign filter must exclude the other campaign\'s sends');
    }

    public function testGetCampaignsReportFiltersByDateAndCampaign(): void
    {
        $id = $this->makeCampaign('PHPUnit analytics report filter');

        $matchToday = getCampaignsReport(1, date('Y-m-d'), date('Y-m-d'), $id);
        $this->assertCount(1, array_filter($matchToday, fn($c) => (int) $c['id'] === $id));

        $matchOldRange = getCampaignsReport(1, '2000-01-01', '2000-01-02', $id);
        $this->assertCount(0, array_filter($matchOldRange, fn($c) => (int) $c['id'] === $id));
    }

    public function testGetTopErrorsFiltersByCampaign(): void
    {
        $idA = $this->makeCampaign('PHPUnit analytics errors A');
        $idB = $this->makeCampaign('PHPUnit analytics errors B');
        $this->queue->addRecipients($idA, [['destinataire' => '622996006', 'contenu' => 'A']]);
        $this->queue->addRecipients($idB, [['destinataire' => '622996007', 'contenu' => 'B']]);
        $msgA = getMessageCampagne($idA)[0];
        $msgB = getMessageCampagne($idB)[0];
        $this->pdo->prepare("UPDATE messages SET statut='echec', error_code='PHPUNIT_ERR_A', date_traitement=NOW() WHERE id=:id")->execute([':id' => $msgA['id']]);
        $this->pdo->prepare("UPDATE messages SET statut='echec', error_code='PHPUNIT_ERR_B', date_traitement=NOW() WHERE id=:id")->execute([':id' => $msgB['id']]);

        $errorsA = getTopErrors(1, 10, null, null, $idA);
        $codes = array_column($errorsA, 'error_code');

        $this->assertContains('PHPUNIT_ERR_A', $codes);
        $this->assertNotContains('PHPUNIT_ERR_B', $codes);
    }
}
