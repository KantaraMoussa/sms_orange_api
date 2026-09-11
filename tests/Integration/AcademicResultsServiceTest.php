<?php

namespace Tests\Integration;

use App\Services\AcademicResultsService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database (no dedicated test database, see
 * DATABASE.md). All rows use matricule prefixed "PHPUNITRES-" and are
 * deleted in tearDown() regardless of test outcome.
 *
 * Covers cahier des charges V2.0 §10/§23 (rapport d'import, doublons),
 * §3-4 (filtrage), §16-17 (rendu réel identique à la prévisualisation).
 */
class AcademicResultsServiceTest extends TestCase
{
    private PDO $pdo;
    private AcademicResultsService $service;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->service = new AcademicResultsService($this->pdo);
    }

    protected function tearDown(): void
    {
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
}
