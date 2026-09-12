<?php

namespace Tests\Integration;

use App\Services\CreditService;
use App\Services\OrganizationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real apiSms database. Uses a dedicated test organization
 * (nom préfixé "PHPUNITCREDIT-") supprimée dans tearDown().
 *
 * Covers cahier des charges V2.0 §34 (Phase 2) : solde de crédits interne
 * par organisation, distinct du solde Orange réel partagé (§59).
 */
class CreditServiceTest extends TestCase
{
    private PDO $pdo;
    private int $orgId;
    private CreditService $credits;

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->orgId = (new OrganizationService($this->pdo))->create(['nom' => 'PHPUNITCREDIT-Org-' . uniqid()]);
        $this->credits = new CreditService($this->pdo, $this->orgId);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM credit_transactions WHERE organization_id = {$this->orgId}");
        $this->pdo->exec("DELETE FROM organizations WHERE id = {$this->orgId}");
    }

    public function testNewOrganizationHasDefaultBalance(): void
    {
        $this->assertSame(100000, $this->credits->balance());
    }

    public function testCreditIncreasesBalanceAndLogsTransaction(): void
    {
        $newBalance = $this->credits->credit(500, 'Recharge test', 'phpunit');

        $this->assertSame(100500, $newBalance);
        $this->assertSame(100500, $this->credits->balance());

        $history = $this->credits->history();
        $this->assertSame('credit', $history[0]['type']);
        $this->assertSame(500, (int) $history[0]['amount']);
        $this->assertSame(100500, (int) $history[0]['balance_after']);
    }

    public function testCreditRejectsNonPositiveAmount(): void
    {
        $this->expectException(\Exception::class);
        $this->credits->credit(0, 'invalide');
    }

    public function testDebitIfSufficientSucceedsAndLogsTransaction(): void
    {
        $ok = $this->credits->debitIfSufficient(100, 'Test debit', null);

        $this->assertTrue($ok);
        $this->assertSame(99900, $this->credits->balance());
        $history = $this->credits->history();
        $this->assertSame('debit', $history[0]['type']);
    }

    public function testDebitIfSufficientFailsAndDoesNotChangeBalanceWhenInsufficient(): void
    {
        $ok = $this->credits->debitIfSufficient(999999999, 'Trop cher');

        $this->assertFalse($ok);
        $this->assertSame(100000, $this->credits->balance(), 'a refused debit must not touch the balance');
        $this->assertEmpty($this->credits->history(), 'a refused debit must not be logged as a transaction');
    }

    public function testHasSufficientBalance(): void
    {
        $this->assertTrue($this->credits->hasSufficientBalance(100000));
        $this->assertFalse($this->credits->hasSufficientBalance(100001));
    }

    public function testRecordConsumptionAlwaysDebitsEvenBeyondBalance(): void
    {
        // Simule un solde déjà bas puis une consommation qui le dépasse —
        // recordConsumption() ne doit jamais bloquer (le SMS a déjà été
        // envoyé/facturé par Orange à ce stade), contrairement à debitIfSufficient().
        $this->pdo->exec("UPDATE organizations SET credits_balance = 5 WHERE id = {$this->orgId}");

        $this->credits->recordConsumption(10, 'Envoi réel déjà effectué', null);

        $this->assertSame(-5, $this->credits->balance(), 'going negative is allowed here — the cost was already incurred');
    }

    public function testHistoryOrdersByMostRecentFirstAndJoinsCampaignName(): void
    {
        $campagneId = campaignQueue()->createCampaign($this->orgId, 'PHPUNITCREDIT-Campaign');
        $this->credits->credit(10, 'first');
        $this->credits->debitIfSufficient(5, 'second', $campagneId);

        $history = $this->credits->history();

        $this->assertCount(2, $history);
        $this->assertSame('debit', $history[0]['type'], 'most recent transaction must come first');
        $this->assertSame('PHPUNITCREDIT-Campaign', $history[0]['campagne_nom']);

        $this->pdo->exec("DELETE FROM campagne WHERE id = $campagneId");
    }

    public function testCreditsAreIsolatedBetweenOrganizations(): void
    {
        $otherOrgId = (new OrganizationService($this->pdo))->create(['nom' => 'PHPUNITCREDIT-Other-' . uniqid()]);
        $otherCredits = new CreditService($this->pdo, $otherOrgId);

        $this->credits->credit(1000, 'only for org A');

        $this->assertSame(101000, $this->credits->balance());
        $this->assertSame(100000, $otherCredits->balance(), 'crediting one organization must not affect another');

        $this->pdo->exec("DELETE FROM organizations WHERE id = $otherOrgId");
    }
}
