<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Cache;

use GruffPhp\Engine\Cache\ResultCache;
use PHPUnit\Framework\TestCase;

/**
 * Covers cache entry retention before and after an explicit finalizeRun() call.
 * Asserts that writes beyond the cap retain all entries and finalization keeps the newest entries.
 * Checks that canHoldRun() accepts the default cap and rejects a working set one file larger.
 */
final class ResultCacheTest extends TestCase
{
    /**
     * Deliberately tiny entry cap so eviction is exercised without writing thousands of files.
     */
    private const TEST_ENTRY_CAP = 3;

    /**
     * Entry count for the write tests; deliberately above TEST_ENTRY_CAP.
     */
    private const ENTRIES_WRITTEN = 6;

    /**
     * Throwaway cache directory used by one test.
     */
    private string $cacheDir = '';

    /**
     * Choose a randomly named throwaway cache directory path; the cache creates it on first put.
     *
     * @return void - Sets the temporary cache path used by the test.
     */
    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gruff-result-cache-' . bin2hex(random_bytes(6));
    }

    /**
     * Remove the throwaway cache directory and its entries.
     *
     * @return void - Deletes matching JSON entries and removes the directory if present.
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
     * Verify all entries remain after writing beyond the configured cap.
     *
     * @return void - Asserts that the on-disk entry count equals the number written.
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
     * @return void - Asserts that exactly the newest entries remain after finalization.
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
     * @return void - Asserts that a file count of MAX_ENTRIES is accepted.
     */
    public function testCanHoldRunAcceptsAWorkingSetExactlyAtTheCap(): void
    {
        self::assertTrue(ResultCache::canHoldRun(ResultCache::MAX_ENTRIES));
    }

    /**
     * Verify canHoldRun() rejects a working set one file over the cap.
     *
     * @return void - Asserts that one file beyond MAX_ENTRIES is rejected.
     */
    public function testCanHoldRunRejectsAWorkingSetOverTheCap(): void
    {
        self::assertFalse(ResultCache::canHoldRun(ResultCache::MAX_ENTRIES + 1));
    }

    /**
     * Write the given number of empty-findings entries through put().
     *
     * @param ResultCache $cache - Cache that receives the generated entries.
     * @param int         $entryCount - Number of entries to write; zero or negative values write none.
     *
     * @return void - Writes entries with empty findings using zero-based entry keys.
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
     * @return void - Assigns increasing timestamps to the expected entries and clears cached file metadata.
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
     * @return list<string> - Entry paths; empty when no JSON entries match or glob() fails.
     */
    private function entryPaths(): array
    {
        $paths = glob($this->cacheDir . '/*.json');
        if (!is_array($paths)) {

            return [];
        }

        return $paths;
    }
}
