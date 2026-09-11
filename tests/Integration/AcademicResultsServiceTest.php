<?php

namespace Tests\Integration;

use App\Services\AcademicResultsService;
use App\Services\CampaignQueueService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database (no dedicated test database, see
 * DATABASE.md). All rows use matricule prefixed "PHPUNITRES-" and are
 * deleted in tearDown() regardless of test outcome.
 *
 * Covers cahier des charges V2.0 §10/§23 (rapport d'import, doublons),
 * §3-4 (filtrage), §16-17 (rendu réel identique à la prévisualisation,
 * recherche, exclusion des étudiants déjà envoyés).
 */
class AcademicResultsServiceTest extends TestCase
{
    private PDO $pdo;
    private AcademicResultsService $service;
    /** @var string[] */
    private array $tmpFiles = [];
    /** @var int[] */
    private array $createdCampaignIds = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->service = new AcademicResultsService($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("UPDATE resultats_academiques SET derniere_campagne_id = NULL WHERE matricule LIKE 'PHPUNITRES-%'");
        if (!empty($this->createdCampaignIds)) {
            $ids = implode(',', array_map('intval', $this->createdCampaignIds));
            $this->pdo->exec("DELETE FROM messages WHERE campagne_id IN ($ids)");
            $this->pdo->exec("DELETE FROM campagne WHERE id IN ($ids) AND type = 'phpunit_test'");
        }
        $this->pdo->exec("DELETE FROM resultats_academiques WHERE matricule LIKE 'PHPUNITRES-%'");
        $this->pdo->exec("DELETE FROM imports_resultats WHERE filename LIKE 'phpunit_%'");

        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function makeCsv(array $rows, string $header = "matricule,nom,prenom,telephone,session,niveau,classe,semestre,moyenne,mention,rang,total"): string
    {
        $path = sys_get_temp_dir() . '/phpunit_resultats_' . uniqid() . '.csv';
        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        file_put_contents($path, implode("\n", $lines));
        $this->tmpFiles[] = $path;

        return $path;
    }

    public function testImportValidatesAndReportsInvalidPhones(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITRES-1', 'Diallo', 'Amadou', '622111111', '2025-2026', 'L2', 'Info', 'S4', '14.25', 'Bien', '12', '85'],
            ['PHPUNITRES-2', 'Bah', 'Fatou', '12345', '2025-2026', 'L2', 'Info', 'S4', '11.00', 'Passable', '40', '85'], // invalid phone
        ]);

        $report = $this->service->importFile($path, 'csv', 'phpunit');

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['valides']);
        $this->assertSame(1, $report['invalides']);
        $this->assertCount(1, $report['errors']);
    }

    public function testReimportSameStudentUpdatesInsteadOfDuplicating(): void
    {
        $path1 = $this->makeCsv([
            ['PHPUNITRES-3', 'Diallo', 'Amadou', '622111111', '2025-2026', 'L2', 'Info', 'S4', '10.00', 'Passable', '50', '85'],
        ]);
        $this->service->importFile($path1, 'csv', 'phpunit');

        // Ré-import du même matricule/session/semestre avec une moyenne corrigée.
        $path2 = $this->makeCsv([
            ['PHPUNITRES-3', 'Diallo', 'Amadou', '622111111', '2025-2026', 'L2', 'Info', 'S4', '14.25', 'Bien', '12', '85'],
        ]);
        $this->service->importFile($path2, 'csv', 'phpunit');

        $rows = $this->service->getMatching(['session_academique' => '2025-2026', 'niveau' => 'L2', 'classe' => '', 'programme' => '', 'semestre' => 'S4']);
        $matching = array_values(array_filter($rows, fn($r) => $r['matricule'] === 'PHPUNITRES-3'));

        $this->assertCount(1, $matching, 'a re-import of the same student/session/semester must update, not duplicate');
        $this->assertSame('14.25', $matching[0]['moyenne']);
    }

    public function testFilteringMatchesOnAllCriteria(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITRES-4', 'Camara', 'Ibrahim', '622111112', '2025-2026', 'L3', 'Info', 'S6', '15.00', 'Bien', '3', '40'],
            ['PHPUNITRES-5', 'Sow', 'Aissatou', '622111113', '2025-2026', 'L2', 'Info', 'S4', '12.00', 'Assez Bien', '20', '85'],
        ]);
        $this->service->importFile($path, 'csv', 'phpunit');

        $l3Only = $this->service->getMatching(['session_academique' => '2025-2026', 'niveau' => 'L3', 'classe' => '', 'programme' => '', 'semestre' => 'S6']);
        $matricules = array_column($l3Only, 'matricule');

        $this->assertContains('PHPUNITRES-4', $matricules);
        $this->assertNotContains('PHPUNITRES-5', $matricules);
    }

    public function testImportBatchIsTrackedWithErrorsDownloadable(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITRES-6', 'Test', 'User', 'bad-phone', '2025-2026', 'L1', 'Droit', 'S1', '9.00', 'Insuffisant', '60', '85'],
        ]);

        $report = $this->service->importFile($path, 'csv', 'phpunit');
        $errors = $this->service->getImportErrors($report['import_id']);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('bad-phone', $errors[0]['erreur']);
    }

    public function testSearchFiltersByNomPrenomOrMatricule(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITRES-7', 'Zoumanigui', 'Kadiatou', '622111114', '2025-2026', 'L1', 'Droit', 'S1', '13.00', 'Bien', '5', '30'],
            ['PHPUNITRES-8', 'Traore', 'Mamadou', '622111115', '2025-2026', 'L1', 'Droit', 'S1', '12.50', 'Bien', '8', '30'],
        ]);
        $this->service->importFile($path, 'csv', 'phpunit');

        $byName = $this->service->getMatching(['search' => 'Zoumanigui']);
        $byMatricule = $this->service->getMatching(['search' => 'PHPUNITRES-8']);

        $this->assertCount(1, array_filter($byName, fn($r) => $r['matricule'] === 'PHPUNITRES-7'));
        $this->assertNotContains('PHPUNITRES-8', array_column($byName, 'matricule'));
        $this->assertContains('PHPUNITRES-8', array_column($byMatricule, 'matricule'));
    }

    public function testExcludeIdsRemovesSpecificRows(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITRES-9', 'A', 'B', '622111116', '2025-2026', 'L1', 'Droit', 'S1', '10', 'Passable', '1', '2'],
            ['PHPUNITRES-10', 'C', 'D', '622111117', '2025-2026', 'L1', 'Droit', 'S1', '10', 'Passable', '2', '2'],
        ]);
        $this->service->importFile($path, 'csv', 'phpunit');

        $all = $this->service->getMatching(['session_academique' => '2025-2026', 'niveau' => 'L1', 'classe' => '', 'programme' => '', 'semestre' => 'S1', 'search' => 'PHPUNITRES-']);
        $toExclude = array_values(array_filter($all, fn($r) => $r['matricule'] === 'PHPUNITRES-9'));
        $this->assertCount(1, $toExclude);

        $filtered = $this->service->getMatching(['search' => 'PHPUNITRES-1', 'exclude_ids' => [$toExclude[0]['id']]]);
        $this->assertNotContains('PHPUNITRES-9', array_column($filtered, 'matricule'));
        $this->assertContains('PHPUNITRES-10', array_column($filtered, 'matricule'));
    }

    public function testMarkCampaignForRowsTracksAlreadySentStatus(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITRES-11', 'Sent', 'Student', '622111118', '2025-2026', 'L1', 'Droit', 'S1', '10', 'Passable', '1', '1'],
        ]);
        $this->service->importFile($path, 'csv', 'phpunit');

        $row = $this->service->getMatching(['search' => 'PHPUNITRES-11'])[0];
        $this->assertFalse($row['deja_envoye'], 'a student never assigned to a campaign must not appear as already sent');

        $queue = new CampaignQueueService($this->pdo, orangeSms());
        $campaignId = $queue->createCampaign('PHPUnit resultats already-sent', '', 'phpunit_test', 'phpunit', 50);
        $this->createdCampaignIds[] = $campaignId;
        $queue->addRecipients($campaignId, [[
            'destinataire' => $row['telephone'],
            'contenu' => 'Test',
            'matricule' => $row['matricule'],
        ]]);
        $this->service->markCampaignForRows($campaignId, [$row['id']]);

        // Tant que le message n'est pas réellement "envoye", l'étudiant ne doit pas apparaître comme déjà envoyé.
        $stillPending = $this->service->getMatching(['search' => 'PHPUNITRES-11'])[0];
        $this->assertFalse($stillPending['deja_envoye']);

        $this->pdo->prepare("UPDATE messages SET statut = 'envoye' WHERE campagne_id = :id")->execute([':id' => $campaignId]);

        $sent = $this->service->getMatching(['search' => 'PHPUNITRES-11'])[0];
        $this->assertTrue($sent['deja_envoye']);

        $excluded = $this->service->getMatching(['search' => 'PHPUNITRES-11', 'exclude_already_sent' => true]);
        $this->assertCount(0, $excluded, 'exclude_already_sent must filter out students whose last campaign succeeded');
    }

    public function testOnlyWithPhoneFilter(): void
    {
        // Le téléphone est NOT NULL en base (rejeté à l'import sinon) : le filtre
        // doit rester un no-op sûr, jamais exclure une ligne valide (§17).
        $path = $this->makeCsv([
            ['PHPUNITRES-12', 'Avec', 'Telephone', '622111119', '2025-2026', 'L1', 'Droit', 'S1', '10', 'Passable', '1', '1'],
        ]);
        $this->service->importFile($path, 'csv', 'phpunit');

        $rows = $this->service->getMatching(['search' => 'PHPUNITRES-12', 'only_with_phone' => true]);

        $this->assertCount(1, $rows);
    }

    public function testOnlyWithResultsFilterExcludesEmptyMoyenne(): void
    {
        $path = $this->makeCsv([
            ['PHPUNITRES-13', 'Avec', 'Moyenne', '622111120', '2025-2026', 'L1', 'Droit', 'S1', '14.00', 'Bien', '1', '1'],
            ['PHPUNITRES-14', 'Sans', 'Moyenne', '622111121', '2025-2026', 'L1', 'Droit', 'S1', '', '', '', ''],
        ]);
        $this->service->importFile($path, 'csv', 'phpunit');

        $withResults = $this->service->getMatching(['search' => 'PHPUNITRES-1', 'only_with_results' => true]);
        $matricules = array_column($withResults, 'matricule');

        $this->assertContains('PHPUNITRES-13', $matricules);
        $this->assertNotContains('PHPUNITRES-14', $matricules);
    }
}
