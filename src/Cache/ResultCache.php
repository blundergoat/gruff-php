<?php

declare(strict_types=1);

namespace GruffPhp\Cache;

use GruffPhp\Finding\Finding;
use JsonException;

/**
 * Content-addressed, on-disk cache of per-file findings for warm hook feedback.
 *
 * A cache hit must be byte-identical to a cold run; on any doubt (missing entry,
 * unreadable or corrupt payload, encode failure) it fails open — a miss, never a
 * stale serve. Entries are bounded by a size cap with oldest-first eviction, and
 * the store never holds raw source — only the redacted findings a run produced.
 */
final readonly class ResultCache
{
    /**
     * Project-local cache directory name (gitignored, ignored by discovery).
     */
    public const DIRECTORY = '.gruff-cache';

    /**
     * Maximum cached file entries before oldest-first eviction.
     */
    private const MAX_ENTRIES = 4096;

    /**
     * @param string $cacheDir - Project-local directory holding cache entries.
     */
    public function __construct(private string $cacheDir)
    {
    }

    /**
     * Build the cache rooted at the project's gitignored cache directory.
     *
     * @param string $projectRoot - Project root the cache lives under.
     *
     * @return self - Cache for the project.
     */
    public static function forProject(string $projectRoot): self
    {
        // Cache lives in a fixed gitignored directory directly under the project root.
        return new self(rtrim($projectRoot, '/') . '/' . self::DIRECTORY);
    }

    /**
     * Return the cached findings for a key, or null on any miss or doubt.
     *
     * @param string $key - Cache key for a file's per-unit findings.
     *
     * @return list<Finding>|null - Reconstructed findings, or null when not cached.
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

        if (!is_array($decoded)) {
            // The payload decoded to a non-array, so it is not a findings list; discard it as a miss.
            return null;
        }

        $findings = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                // One malformed row invalidates the whole entry; reconstructing a partial list could drop findings.
                return null;
            }

            /** @var array<string, mixed> $entry A cached row is a string-keyed finding payload; the is_array guard cannot express that to PHPStan. */
            $findings[] = Finding::fromArray($entry);
        }

        // Fully reconstructed findings for the key; a byte-identical cold run would have produced this same list.
        return $findings;
    }

    /**
     * Store a file's per-unit findings under its key. Best-effort; failures are silent.
     *
     * @param string        $key - Cache key for a file's per-unit findings.
     * @param list<Finding> $findings - Findings produced for the file.
     *
     * @return void
     */
    public function put(string $key, array $findings): void
    {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            // Cache directory is absent and could not be created; skip persisting since the cache is best-effort.
            return;
        }

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
        $this->evictIfOverCap();
    }

    /**
     * Resolve the on-disk path for a cache key.
     *
     * @param string $key - Cache key.
     *
     * @return string - Absolute entry path.
     */
    private function pathFor(string $key): string
    {
        // One JSON file per key inside the cache directory; the key is already a hex hash so it is filename-safe.
        return $this->cacheDir . '/' . $key . '.json';
    }

    /**
     * Evict the oldest entries when the cache exceeds its size cap.
     *
     * @return void
     */
    private function evictIfOverCap(): void
    {
        $entries = glob($this->cacheDir . '/*.json');
        if (!is_array($entries) || count($entries) <= self::MAX_ENTRIES) {
            // Glob failed or the cache is still within its size cap, so there is nothing to evict this pass.
            return;
        }

        usort($entries, static fn (string $left, string $right): int => (int) filemtime($left) <=> (int) filemtime($right));
        foreach (array_slice($entries, 0, count($entries) - self::MAX_ENTRIES) as $stale) {
            unlink($stale);
        }
    }
}
