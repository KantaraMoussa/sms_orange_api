<?php

namespace Tests\Unit;

use App\Services\MessageTemplateService;
use PHPUnit\Framework\TestCase;

/**
 * Covers cahier des charges §16-17 : rendu des variables dynamiques, sans
 * jamais produire un message corrompu si une variable est manquante.
 */
class MessageTemplateServiceTest extends TestCase
{
    public function testSubstitutesKnownVariables(): void
    {
        $result = MessageTemplateService::render(
            'Bonjour {{prenom}} {{nom}}, moyenne : {{moyenne}}',
            ['prenom' => 'Amadou', 'nom' => 'Diallo', 'moyenne' => '14.25/20']
        );

        $this->assertSame('Bonjour Amadou Diallo, moyenne : 14.25/20', $result['message']);
        $this->assertSame([], $result['missing']);
    }

    public function testMissingVariableIsReplacedByEmptyStringAndReported(): void
    {
        $result = MessageTemplateService::render('Bonjour {{prenom}}, rang {{rang}}', ['prenom' => 'Amadou']);

        $this->assertSame('Bonjour Amadou, rang ', $result['message']);
        $this->assertSame(['rang'], $result['missing']);
    }

    public function testUnknownTokensOutsideVariableSyntaxAreLeftAlone(): void
    {
        $result = MessageTemplateService::render('Prix : 100% garanti, contact {{prenom}}', ['prenom' => 'Fatou']);

        $this->assertSame('Prix : 100% garanti, contact Fatou', $result['message']);
    }

    public function testEmptyStringValueCountsAsMissing(): void
    {
        $result = MessageTemplateService::render('Mention : {{mention}}', ['mention' => '']);

        $this->assertSame(['mention'], $result['missing']);
    }

    public function testSameVariableUsedTwiceIsReportedOnce(): void
    {
        $result = MessageTemplateService::render('{{x}} et encore {{x}}', []);

        $this->assertSame(['x'], $result['missing']);
    }
}
