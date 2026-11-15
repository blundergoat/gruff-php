<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Cache;

use GruffPhp\Results\Finding\Finding;
use JsonException;
use Throwable;

/**
 * Content-addressed, on-disk cache of per-file findings for warm hook feedback.
 *
 * A cache hit must be byte-identical to a cold run; on any doubt (missing entry,
 * unreadable or corrupt payload, encode failure) it fails open - a miss, never a
 * stale serve. Entries are bounded by an entry cap with oldest-first eviction
 * applied once per run via finalizeRun() - never per put - and the store never
 * holds raw source, only the redacted findings a run produced.
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
     * @param string $cacheDir - Project-local directory holding cache entries.
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
     * @return list<Finding>|null - Reconstructed findings; null on any miss (no entry, unreadable, corrupt, or malformed), so the caller re-runs cold.
     */
    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (!is_file($path) || !is_readable($path)) {
            // No entry on disk for this key, or it is unreadable: a plain cache miss, so callers re-run cold.
            return null;
        }

        $raw = file_get_contents($path);

        if (!is_string($raw)) {
            // The entry could not be read despite existing; fail open to a miss rather than risk a stale serve.
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A corrupt or truncated entry is treated as absent so a bad cache file never poisons the run.
            return null;
        }

        // The payload is not a list at all, so it cannot be a findings array; treat it as a miss.
        if (!is_array($decoded)) {
            // The payload decoded to a non-array, so it is not a findings list; discard it as a miss.
            return null;
        }

        $findings = [];

        foreach ($decoded as $entry) {
            // One malformed row invalidates the whole entry - a partial rebuild could silently drop findings.
            if (!is_array($entry)) {

                return null;
            }

            try {
                /** @var array<string, mixed> $entry A cached row is a string-keyed finding payload; the is_array guard cannot express that to PHPStan. */
                $findings[] = Finding::fromArray($entry);
            } catch (Throwable) {
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
     * @param string        $key - Cache key for a file's per-unit findings.
     * @param list<Finding> $findings - Findings produced for the file.
     *
     * @return void - Cache failures are not returned to the caller.
     */
    public function put(string $key, array $findings): void
    {

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
            // Findings that will not encode are simply not cached; the next run recomputes them from source.
            return;
        }

        file_put_contents($this->pathFor($key), $json, LOCK_EX);
    }

    /**
     * Applies oldest-first eviction when the cache exceeds its configured entry cap.
     * Call once per run to keep directory scans out of the per-file write path.
     *
     * @return void - Attempts to remove excess JSON entries in ascending modification-time order.
     */
    public function finalizeRun(): void
    {
        $entries = glob($this->cacheDir . '/*.json');

        if (!is_array($entries) || count($entries) <= $this->maxEntries) {

            return;
        }

        usort($entries, static fn (string $left, string $right): int => (int) filemtime($left) <=> (int) filemtime($right));

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
