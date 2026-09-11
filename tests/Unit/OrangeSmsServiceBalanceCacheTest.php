<?php

namespace Tests\Unit;

use App\Services\OrangeSmsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests the balance cache added to avoid a real network call to Orange on
 * every keystroke of the "Résultats académiques" live preview (AUDIT.md §7).
 * Exercises the cache read/write primitives directly via reflection so no
 * real Orange credentials or network access are needed — getBalance()
 * itself is never called here since it would trigger authenticate().
 */
class OrangeSmsServiceBalanceCacheTest extends TestCase
{
    private string $cacheFile;
    private OrangeSmsService $service;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/phpunit_orange_balance_' . uniqid() . '.json';
        $this->service = new OrangeSmsService('dummy', 'dummy', 'SMS_ORANGE', 'GN', null, $this->cacheFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    private function invoke(string $method, ...$args)
    {
        $ref = new ReflectionClass($this->service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->service, $args);
    }

    public function testReadCachedBalanceReturnsNullWhenNoFileExists(): void
    {
        $this->assertNull($this->invoke('readCachedBalance', 20));
    }

    public function testWriteThenReadReturnsTheSameBalanceWithinTtl(): void
    {
        $this->invoke('writeCachedBalance', ['availableUnits' => 906, 'status' => 'EXPIRED']);

        $cached = $this->invoke('readCachedBalance', 20);

        $this->assertSame(906, $cached['availableUnits']);
    }

    public function testExpiredCacheIsIgnored(): void
    {
        $this->invoke('writeCachedBalance', ['availableUnits' => 906]);
        // Simule un cache vieux de 100s en réécrivant directement le fichier.
        $data = json_decode(file_get_contents($this->cacheFile), true);
        $data['cached_at'] = time() - 100;
        file_put_contents($this->cacheFile, json_encode($data));

        $this->assertNull($this->invoke('readCachedBalance', 20), 'a cache older than maxAgeSeconds must be treated as a miss');
    }

    public function testMaxAgeZeroAlwaysForcesAMiss(): void
    {
        $this->invoke('writeCachedBalance', ['availableUnits' => 906]);

        $this->assertNull($this->invoke('readCachedBalance', 0), 'maxAgeSeconds=0 must always force a fresh read (used before actually creating a campaign)');
    }
}
