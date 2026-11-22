<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Cache;

use GruffPhp\Results\Finding\Finding;
use JsonException;
use Throwable;

/**
 * Stores findings per file to speed up repeated analysis and hook runs.
 *
 * An unreadable, malformed, or missing entry prompts a fresh scan of that file.
 * Only redacted findings are stored. After the scan, eviction keeps the cache within its entry cap.
 */
final readonly class ResultCache
{
    /**
     * Project-local cache directory name (gitignored, ignored by discovery).
     */
    public const DIRECTORY = '.gruff-cache';

    /**
     * Entry cap used by finalizeRun() and canHoldRun().
     *
     * Files with no findings still occupy cache entries, so eligibility uses the full discovered file count.
     */
    public const MAX_ENTRIES = 32768;

    /**
     * Wraps the project's cache directory; use forProject() to build one rooted under the project.
     *
     * @param string $cacheDir   - Project-local directory holding cache entries.
     * @param int    $maxEntries - Maximum number of JSON entries retained after finalizeRun().
     */
    public function __construct(private string $cacheDir, private int $maxEntries = self::MAX_ENTRIES)
    {
    }

    /**
     * Builds the cache rooted at the project's gitignored cache directory.
     *
     * @param string $projectRoot - Project root the cache lives under.
     *
     * @return self - Cache for the project.
     */
    public static function forProject(string $projectRoot): self
    {
        return new self(rtrim($projectRoot, '/') . '/' . self::DIRECTORY);
    }

    /**
     * Returns a key's cached findings, or null on any miss or doubt - so a warm hook run reuses work
     * only when the entry is unmistakably good, never when it might be stale.
     *
     * @param string $key - Cache key for a file's per-unit findings.
     *
     * @return list<Finding>|null - Cached findings. Null means rescan this file, while an empty list means the cached scan found no issues.
     */
    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);

        // A missing or unreadable cache entry lets the scan compute the file's findings again.
        if (!is_file($path) || !is_readable($path)) {
            // No entry on disk for this key, or it is unreadable: a plain cache miss, so callers re-run cold.
            return null;
        }

        $raw = file_get_contents($path);

        // A cache file can disappear between checks. Continue from the source when that happens.
        if (!is_string($raw)) {
            // The entry could not be read despite existing; fail open to a miss rather than risk a stale serve.
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A truncated entry, such as one left by an interrupted write, triggers a fresh scan for that file.
            return null;
        }

        // The payload is not a list at all, so it cannot be a findings array; treat it as a miss.
        if (!is_array($decoded)) {
            // The payload decoded to a non-array, so it is not a findings list; discard it as a miss.
            return null;
        }

        $findings = [];

        // Rebuild every cached finding so the current report never gets a partial file result.
        foreach ($decoded as $entry) {
            // One malformed row invalidates the whole entry - a partial rebuild could silently drop findings.
            if (!is_array($entry)) {

                return null;
            }

            try {
                /** @var array<string, mixed> $entry - A cached finding must use named fields. Finding::fromArray checks them. */
                $findings[] = Finding::fromArray($entry);
            } catch (Throwable) {
                // An older or malformed cached finding cannot fill part of a report. Recompute the file from source.
                return null;
            }
        }

        return $findings;
    }

    /**
     * Reports whether the discovered working set fits within the default entry cap.
     * Callers skip caching oversized runs to avoid retaining only part of that working set.
     *
     * @param int $discoveredFileCount - Number of analysable files discovered for the run.
     *
     * @return bool - True when the file count is at most MAX_ENTRIES.
     */
    public static function canHoldRun(int $discoveredFileCount): bool
    {
        return $discoveredFileCount <= self::MAX_ENTRIES;
    }

    /**
     * Stores a file's findings under its key; cache failures leave the analysis result unchanged.
     * Eviction waits for finalizeRun() so per-file writes do not scan the cache directory.
     *
     * @param string        $key      - Cache key for a file's per-unit findings.
     * @param list<Finding> $findings - Findings produced for the file.
     *
     * @return void - Cache failures are not returned to the caller.
     */
    public function put(string $key, array $findings): void
    {

        // If the cache directory cannot be created, keep the scan usable without saved results.
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            // Cache directory is absent and could not be created; skip persisting since the cache is best-effort.
            return;
        }

        // A read-only cache directory is not worth failing the run over, so give up silently.
        if (!is_writable($this->cacheDir)) {

            return;
        }

        $payload = array_map(static fn (Finding $finding): array => $finding->toArray(), $findings);

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // If a finding cannot be written as JSON, the next scan recomputes it from source.
            return;
        }

        file_put_contents($this->pathFor($key), $json, LOCK_EX);
    }

    /**
     * Applies oldest-first eviction when the cache exceeds its configured entry cap.
     * Serializes eviction across runs so each process starts from the entries still on disk.
     *
     * @return void - Attempts to remove excess JSON entries in ascending modification-time order.
     */
    public function finalizeRun(): void
    {
        // Skip eviction when the cache cannot be changed, such as on a read-only checkout.
        if (!is_writable($this->cacheDir)) {
            return;
        }

        $lock = fopen($this->cacheDir . '/.eviction.lock', 'c');
        // Without a shared lock file, leave this run's cache entries in place safely.
        if ($lock === false) {
            return;
        }

        try {
            // Several scans can finish together. Wait for the shared lock before choosing what to remove.
            if (flock($lock, LOCK_EX)) {
                $this->evictOldestEntries();
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * Removes any entries above the cap while the caller holds the eviction lock.
     *
     * @return void - Deletes the excess entries in modification-time order.
     */
    private function evictOldestEntries(): void
    {
        $entries = glob($this->cacheDir . '/*.json');
        // An already small cache needs no eviction, and an unreadable directory yields no candidates.
        if (!is_array($entries) || count($entries) <= $this->maxEntries) {
            return;
        }

        usort($entries, static fn (string $left, string $right): int => (int)filemtime($left) <=> (int)filemtime($right));
        // Discard the oldest entries first so recently scanned files can still be reused.
        foreach (array_slice($entries, 0, count($entries) - $this->maxEntries) as $stale) {
            unlink($stale);
        }
    }

    /**
     * Resolves the on-disk path for a cache key.
     *
     * @param string $key - Key appended to the cache directory before the .json suffix.
     *
     * @return string - Entry path, relative if the configured cache directory is relative.
     */
    private function pathFor(string $key): string
    {
        return $this->cacheDir . '/' . $key . '.json';
    }
}
