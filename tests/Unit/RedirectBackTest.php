<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Régression : resolveRedirectTarget() (extraite de redirectBack(),
 * server/config.php) doit fonctionner quel que soit le port du serveur.
 * Bug réel trouvé le 2026-09-12 : sur le serveur de dev PHP (port :8899),
 * chaque redirection post-mutation perdait le ?page=... et renvoyait vers
 * le fallback (page 404), car parse_url(..., PHP_URL_HOST) ne renvoie
 * jamais le port alors que $_SERVER['HTTP_HOST'] l'inclut.
 */
class RedirectBackTest extends TestCase
{
    public function testKeepsRefererOnDefaultPort(): void
    {
        $target = resolveRedirectTarget('http://example.com/app/index.php?page=contacts', 'example.com', '../app/index.php');

        $this->assertSame('http://example.com/app/index.php?page=contacts', $target);
    }

    public function testKeepsRefererWithNonStandardPort(): void
    {
        // Le cas qui échouait avant le correctif.
        $target = resolveRedirectTarget('http://localhost:8899/app/index.php?page=contacts', 'localhost:8899', '../app/index.php');

        $this->assertSame('http://localhost:8899/app/index.php?page=contacts', $target);
    }

    public function testFallsBackOnCrossOriginReferer(): void
    {
        $target = resolveRedirectTarget('http://evil.example.com/x', 'localhost:8899', '../app/index.php');

        $this->assertSame('../app/index.php', $target);
    }

    public function testFallsBackWhenSameHostButDifferentPort(): void
    {
        // Un Referer forgé sur le bon hôte mais un port différent ne doit pas être suivi.
        $target = resolveRedirectTarget('http://localhost:9999/x', 'localhost:8899', '../app/index.php');

        $this->assertSame('../app/index.php', $target);
    }

    public function testFallsBackWhenRefererMissing(): void
    {
        $target = resolveRedirectTarget('', 'localhost:8899', '../app/index.php');

        $this->assertSame('../app/index.php', $target);
    }
}
