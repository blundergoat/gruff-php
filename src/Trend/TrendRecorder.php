<?php

declare(strict_types=1);

namespace GruffPhp\Trend;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Scoring\ScoreReport;
use GruffPhp\Support\PathHelper;
use RuntimeException;

/**
 * Records score and finding-count snapshots for trend reporting.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendRecorder
{
    /**
     * Append a score snapshot to the bounded history file.
     *
     * @param string      $projectRoot  Project root used to resolve the history path.
     * @param string      $path         History file path to write.
     * @param ScoreReport $score        Score report to snapshot.
     * @param int         $findingCount Total finding count for the snapshot.
     * @throws RuntimeException When the history file cannot be read, validated, or written.
     * @throws \JsonException When history JSON cannot be decoded or encoded.
     * @return TrendReport Report describing the current score and prior delta.
     */
    public function record(string $projectRoot, string $path, ScoreReport $score, int $findingCount): TrendReport
    {
        $resolvedPath  = PathHelper::resolveAgainst($projectRoot, $path);
        $entries       = $this->readEntries($resolvedPath);
        $previous      = $entries === [] ? null : $entries[array_key_last($entries)];
        $previousScore = $this->scoreFromEntry($previous);
        $trendEntry    = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'timestamp' => gmdate(DATE_ATOM),
            'score' => $score->composite->score,
            'grade' => $score->composite->letter,
            'scope' => $score->scope,
            'findings' => $findingCount,
        ];

        $entries[] = $trendEntry;
        $entries   = array_slice($entries, -50);
        $directory = dirname($resolvedPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create history directory: %s', $directory));
        }

        if (file_put_contents($resolvedPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write history file: %s', $resolvedPath));
        }

        // Report the just-written snapshot plus its delta against the prior run for callers to display.
        return new TrendReport(
            path:          PathHelper::relativeToRoot($resolvedPath, $projectRoot) ?? PathHelper::canonical($resolvedPath),
            currentScore:  $score->composite->score,
            previousScore: $previousScore,
            delta:         $previousScore === null ? null : round($score->composite->score - $previousScore, 2),
            entries:       $entries,
        );
    }

    /**
     * Read and validate the bounded history file into typed snapshot rows.
     *
     * @param string $path Resolved history file path; an absent or empty file is treated as no history.
     * @return list<TrendEntry> Snapshot rows in file order; empty when the file is absent or blank.
     */
    private function readEntries(string $path): array
    {
        $contents = $this->readHistoryFile($path);
        if ($contents === null) {
            // No file or a blank file means there is simply no prior history yet.
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(sprintf('History file must contain a JSON array: %s', $path));
        }

        // Each decoded row is re-validated so a tampered history file fails loudly rather than silently.
        return array_map(
            fn (mixed $trendEntry): array => $this->normaliseEntry($trendEntry, $path),
            $decoded,
        );
    }

    /**
     * Read raw history file bytes, distinguishing "no history" from an unreadable file.
     *
     * @param string      $path Resolved history file path to read.
     * @return string|null Raw file contents, or null when the file is missing or whitespace-only.
     */
    private function readHistoryFile(string $path): ?string
    {
        if (!is_file($path)) {
            // A missing file is the first-run case, not an error: caller starts a fresh history.
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read history file: %s', $path));
        }

        if (trim($contents) === '') {
            // A blank file is treated like no history so a truncated write cannot crash the next run.
            return null;
        }

        // Defer JSON parsing to the caller; this method only owns presence and readability.
        return $contents;
    }

    /**
     * Validate one decoded history row and preserve its scalar values.
     *
     * @param mixed  $trendEntry Decoded JSON row; must be a string-keyed map, else the file is rejected.
     * @param string $path       History file path, used only to make validation errors point at the file.
     * @return TrendEntry The row with every value confirmed scalar, ready to fold back into history.
     */
    private function normaliseEntry(mixed $trendEntry, string $path): array
    {
        if (!is_array($trendEntry) || array_is_list($trendEntry)) {
            throw new RuntimeException(sprintf('History file contains an invalid entry: %s', $path));
        }

        $normalisedEntry = [];
        foreach ($trendEntry as $key => $trendValue) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('History file contains a non-string entry key: %s', $path));
            }

            $normalisedEntry[$key] = $this->normaliseEntryValue($trendValue, $path);
        }

        // Hand back the rebuilt row so an invalid value anywhere aborts before it re-enters history.
        return $normalisedEntry;
    }

    /**
     * Assert a single history value is scalar, rejecting nested arrays/objects.
     *
     * @param mixed  $trendValue Decoded value to vet; non-scalar shapes are not valid trend data.
     * @param string $path       History file path, surfaced in the error when a value is rejected.
     * @return bool|float|int|string|null The same value once confirmed scalar.
     */
    private function normaliseEntryValue(mixed $trendValue, string $path): bool|float|int|string|null
    {
        if (is_bool($trendValue) || is_float($trendValue) || is_int($trendValue) || is_string($trendValue) || $trendValue === null) {
            // Pass scalars through untouched; only their type is contractually guaranteed.
            return $trendValue;
        }

        throw new RuntimeException(sprintf('History file contains a non-scalar entry value: %s', $path));
    }

    /**
     * @param TrendEntry|null $trendEntry
     *
     * @return float|null Score value from the entry, or null when absent.
     */
    private function scoreFromEntry(?array $trendEntry): ?float
    {
        $score = $trendEntry['score'] ?? null;

        // A missing or non-numeric score yields null so the delta is reported as unknown, not zero.
        return is_int($score) || is_float($score) ? (float) $score : null;
    }

}
