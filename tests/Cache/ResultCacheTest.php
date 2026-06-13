<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Cache;

use GruffPhp\Engine\Cache\ResultCache;
use PHPUnit\Framework\TestCase;

/**
 * Covers the result cache's once-per-run eviction contract: put() never evicts
 * (per-put eviction globbed the cache directory on every write, which made
 * large-repo scans I/O-bound), finalizeRun() trims the store to its entry cap
 * oldest-first exactly once, and canHoldRun() rejects working sets that cannot
 * fit under the cap so over-cap runs skip the cache instead of thrashing it.
 */
final class ResultCacheTest extends TestCase
{
    /**
     * Deliberately tiny entry cap so eviction is exercised without writing thousands of files.
     */
    private const TEST_ENTRY_CAP = 3;

    /**
     * Entries each test writes through put(); deliberately above TEST_ENTRY_CAP.
     */
    private const ENTRIES_WRITTEN = 6;

    /**
     * Throwaway cache directory used by one test.
     */
    private string $cacheDir = '';

    /**
     * Choose a unique throwaway cache directory; the cache creates it on first put.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gruff-result-cache-' . bin2hex(random_bytes(6));
    }

    /**
     * Remove the throwaway cache directory and its entries.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->entryPaths() as $entryPath) {
            unlink($entryPath);
        }

        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    /**
     * Verify put() never evicts mid-run, even once the store is past its cap.
     *
     * @return void
     */
    public function testPutBeyondTheCapDoesNotEvictDuringTheRun(): void
    {
        $cache = new ResultCache($this->cacheDir, self::TEST_ENTRY_CAP);

        $this->putEntries($cache, self::ENTRIES_WRITTEN);

        self::assertCount(
            self::ENTRIES_WRITTEN,
            $this->entryPaths(),
            'put() must defer eviction to finalizeRun(); evicting per put globs the cache directory on every write.',
        );
    }

    /**
     * Verify finalizeRun() trims the store to its cap, evicting oldest entries first.
     *
     * @return void
     */
    public function testFinalizeRunEvictsOldestEntriesDownToTheCap(): void
    {
        $cache = new ResultCache($this->cacheDir, self::TEST_ENTRY_CAP);
        $this->putEntries($cache, self::ENTRIES_WRITTEN);
        $this->staggerEntryTimestamps();

        $cache->finalizeRun();

        self::assertSame(
            $this->newestEntryNames(self::TEST_ENTRY_CAP),
            $this->sortedEntryNames(),
            'finalizeRun() must keep exactly the cap of newest entries and evict the oldest ones.',
        );
    }

    /**
     * Verify canHoldRun() accepts a working set that exactly fills the cap.
     *
     * @return void
     */
    public function testCanHoldRunAcceptsAWorkingSetExactlyAtTheCap(): void
    {
        self::assertTrue(ResultCache::canHoldRun(ResultCache::MAX_ENTRIES));
    }

    /**
     * Verify canHoldRun() rejects a working set one file over the cap.
     *
     * @return void
     */
    public function testCanHoldRunRejectsAWorkingSetOverTheCap(): void
    {
        self::assertFalse(ResultCache::canHoldRun(ResultCache::MAX_ENTRIES + 1));
    }

    /**
     * Write the given number of empty-findings entries through put().
     *
     * @param ResultCache $cache - Cache under test.
     * @param int         $entryCount - Entries to write, keyed entry-0 .. entry-N.
     *
     * @return void
     */
    private function putEntries(ResultCache $cache, int $entryCount): void
    {
        for ($entryIndex = 0; $entryIndex < $entryCount; $entryIndex++) {
            $cache->put('entry-' . $entryIndex, []);
        }
    }

    /**
     * Give each entry a distinct ascending mtime (entry-0 oldest) so oldest-first
     * eviction is deterministic despite filemtime's one-second granularity.
     *
     * @return void
     */
    private function staggerEntryTimestamps(): void
    {
        $baseTimestamp = time() - 3600;
        for ($entryIndex = 0; $entryIndex < self::ENTRIES_WRITTEN; $entryIndex++) {
            touch($this->cacheDir . '/entry-' . $entryIndex . '.json', $baseTimestamp + $entryIndex);
        }

        clearstatcache();
    }

    /**
     * Build the basenames of the newest staggered entries, in ascending name order.
     *
     * @param int $surviveCount - How many of the newest entries to name.
     *
     * @return list<string> - Basenames expected to survive eviction.
     */
    private function newestEntryNames(int $surviveCount): array
    {
        $basenames = [];
        for ($entryIndex = self::ENTRIES_WRITTEN - $surviveCount; $entryIndex < self::ENTRIES_WRITTEN; $entryIndex++) {
            $basenames[] = 'entry-' . $entryIndex . '.json';
        }

        return $basenames;
    }

    /**
     * List the basenames of on-disk cache entries in ascending name order.
     *
     * @return list<string> - Sorted entry basenames.
     */
    private function sortedEntryNames(): array
    {
        $basenames = array_map(basename(...), $this->entryPaths());
        sort($basenames);

        return $basenames;
    }

    /**
     * List the absolute paths of on-disk cache entries.
     *
     * @return list<string> - Entry paths; empty when the directory does not exist yet.
     */
    private function entryPaths(): array
    {
        $paths = glob($this->cacheDir . '/*.json');
        if (!is_array($paths)) {
            // Glob failed or the cache directory was never created; report an empty store.
            return [];
        }

        return $paths;
    }
}
