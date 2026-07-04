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
     * Maximum cached file entries before oldest-first eviction.
     *
     * Sized so shopware-scale repos fit their whole working set - the cap must
     * cover the DISCOVERED file count (PHP plus text units; shopware discovers
     * 17,543), not just files with findings, because a cap below the discovery
     * count evicts entries before any warm run can reuse them, making the
     * cache pure overhead. Measured store cost is ~39MB per 4096 entries, so
     * this cap bounds the steady-state store at roughly 320MB.
     */
    public const MAX_ENTRIES = 32768;

    /**
     * Wraps the project's cache directory; use forProject() to build one rooted under the project.
     *
     * @param string $cacheDir - Project-local directory holding cache entries.
     * @param int    $maxEntries - Entry cap enforced by finalizeRun(); injectable so tests exercise eviction without thousands of writes.
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
        // No entry on disk for this key, or it is unreadable: a plain cache miss.
        if (!is_file($path) || !is_readable($path)) {
            // No entry on disk for this key, or it is unreadable: a plain cache miss, so callers re-run cold.
            return null;
        }

        $raw = file_get_contents($path);
        // The file existed but could not be read, so fail open to a miss rather than risk a stale serve.
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
        // Rebuild each cached finding in turn; a single bad row voids the whole entry.
        foreach ($decoded as $entry) {
            // One malformed row invalidates the whole entry - a partial rebuild could silently drop findings.
            if (!is_array($entry)) {
                // One malformed row invalidates the whole entry; reconstructing a partial list could drop findings.
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
     * Reports whether a run's discovered files all fit under the entry cap, so callers can skip caching
     * a working set too big to ever be reused.
     *
     * When it does not, every entry the run writes would be evicted before a
     * warm run could reuse it, so callers skip the cache entirely for that
     * run: caching a working set that cannot fit is pure overhead.
     *
     * @param int $discoveredFileCount - Analysable files discovered for the run.
     *
     * @return bool - True when the whole working set fits under the entry cap; false when the run should skip the cache.
     */
    public static function canHoldRun(int $discoveredFileCount): bool
    {
        return $discoveredFileCount <= self::MAX_ENTRIES;
    }

    /**
     * Stores a file's findings under its key, best-effort - any failure is silent, since the cache is
     * only ever an optimisation over recomputing from source.
     *
     * Best-effort; failures are
     * silent, and eviction is deferred to finalizeRun() so a large run never
     * globs the cache directory per write.
     *
     * @param string        $key - Cache key for a file's per-unit findings.
     * @param list<Finding> $findings - Findings produced for the file.
     *
     * @return void
     */
    public function put(string $key, array $findings): void
    {
        // No cache directory and we could not create one, so quietly skip persisting.
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            // Cache directory is absent and could not be created; skip persisting since the cache is best-effort.
            return;
        }

        // A read-only cache directory is not worth failing the run over, so give up silently.
        if (!is_writable($this->cacheDir)) {
            // Read-only cache directory: give up silently rather than fail the run for an optional optimisation.
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
     * Trims the store back to its entry cap at the end of a run, evicting the oldest entries first.
     *
     * Called once at the end of a run rather than on every put(): per-put
     * eviction globbed the whole cache directory on each write, which made
     * large-repo scans I/O-bound.
     *
     * @return void
     */
    public function finalizeRun(): void
    {
        $entries = glob($this->cacheDir . '/*.json');
        // Nothing to evict when the glob failed or the store is already within its cap.
        if (!is_array($entries) || count($entries) <= $this->maxEntries) {
            // Glob failed or the cache is within its entry cap, so there is nothing to evict for this run.
            return;
        }

        usort($entries, static fn (string $left, string $right): int => (int) filemtime($left) <=> (int) filemtime($right));
        // Delete the oldest entries past the cap, keeping the store bounded.
        foreach (array_slice($entries, 0, count($entries) - $this->maxEntries) as $stale) {
            unlink($stale);
        }
    }

    /**
     * Resolves the on-disk path for a cache key.
     *
     * @param string $key - Cache key.
     *
     * @return string - Absolute path to the key's entry file.
     */
    private function pathFor(string $key): string
    {
        return $this->cacheDir . '/' . $key . '.json';
    }
}
