<?php

namespace Tests\Unit;

use App\Services\AcademicResultsService;
use App\Services\MessageTemplateService;
use PHPUnit\Framework\TestCase;

/**
 * Régression : {{session}} et {{total}} (noms de variables du cahier des
 * charges §4/§62) doivent se résoudre même si les colonnes en base
 * s'appellent `session_academique`/`total_classe` (noms explicites choisis
 * pour le schéma). Bug réel trouvé en testant le flux complet en navigateur :
 * le message envoyé affichait "Vos résultats du S4 -  sont disponibles."
 * (session vide) avant ce correctif.
 */
class AcademicResultsServiceMappingTest extends TestCase
{
    public function testSessionAndTotalColumnsMapToShortVariableNames(): void
    {
        $row = [
            'nom' => 'Diallo', 'prenom' => 'Amadou', 'matricule' => 'M1',
            'session_academique' => '2025-2026', 'total_classe' => '85',
            'semestre' => 'S4', 'moyenne' => '14.25', 'mention' => 'Bien', 'rang' => '12',
        ];

        $vars = AcademicResultsService::toTemplateVars($row);
        $rendered = MessageTemplateService::render(
            'Résultats du {{semestre}} {{session}} : moyenne {{moyenne}}, rang {{rang}}/{{total}}',
            $vars
        );

        $this->assertSame('Résultats du S4 2025-2026 : moyenne 14.25, rang 12/85', $rendered['message']);
        $this->assertSame([], $rendered['missing']);
    }
}
