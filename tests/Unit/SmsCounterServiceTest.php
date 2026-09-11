<?php

namespace Tests\Unit;

use App\Services\SmsCounterService;
use PHPUnit\Framework\TestCase;

/**
 * Covers cahier des charges §18/§26 : calcul du nombre de SMS, en tenant
 * compte de l'encodage réellement utilisé (jamais de sous-estimation).
 */
class SmsCounterServiceTest extends TestCase
{
    public function testShortGsmMessageIsOneSegment(): void
    {
        $result = SmsCounterService::analyze('Bonjour Amadou, vos resultats sont disponibles.');

        $this->assertSame('GSM-7', $result['encoding']);
        $this->assertSame(1, $result['segments']);
    }

    public function testMessageWithAccentsIsStillGsm7(): void
    {
        // é, è, à, ç... font partie de l'alphabet GSM de base : ne doit PAS basculer en UCS-2.
        $result = SmsCounterService::analyze('Vos résultats du Sémestre 1 - Session 2025-2026 sont disponibles.');

        $this->assertSame('GSM-7', $result['encoding']);
    }

    public function testMessageWithEmojiSwitchesToUcs2(): void
    {
        $result = SmsCounterService::analyze('Résultats disponibles ✅');

        $this->assertSame('UCS-2', $result['encoding']);
    }

    public function testLongGsmMessageSplitsIntoMultipleSegmentsAt153(): void
    {
        $message = str_repeat('A', 200); // > 160 (single) but within GSM alphabet
        $result = SmsCounterService::analyze($message);

        $this->assertSame('GSM-7', $result['encoding']);
        $this->assertSame(200, $result['length']);
        $this->assertSame(2, $result['segments'], '200 chars > 160 single-limit must split at the 153-char multipart threshold');
    }

    public function testExactly160GsmCharsIsStillOneSegment(): void
    {
        $result = SmsCounterService::analyze(str_repeat('A', 160));

        $this->assertSame(1, $result['segments']);
    }

    public function test161GsmCharsBecomesTwoSegments(): void
    {
        $result = SmsCounterService::analyze(str_repeat('A', 161));

        $this->assertSame(2, $result['segments']);
    }

    public function testUcs2MessageUsesTighterThresholds(): void
    {
        $message = str_repeat('✅', 71); // emoji forces UCS-2; > 70-char single limit
        $result = SmsCounterService::analyze($message);

        $this->assertSame('UCS-2', $result['encoding']);
        $this->assertSame(2, $result['segments']);
    }

    public function testEmptyMessageIsZeroSegments(): void
    {
        $result = SmsCounterService::analyze('');

        $this->assertSame(0, $result['segments']);
    }

    public function testDollarSignDoesNotBreakGsmDetection(): void
    {
        // Régression : GSM_BASIC contient un '$' littéral — s'assurer qu'il est bien
        // reconnu comme caractère GSM valide (et que la classe elle-même se charge sans erreur).
        $result = SmsCounterService::analyze('Montant : 250000 $ GNF');

        $this->assertSame('GSM-7', $result['encoding']);
    }
}
