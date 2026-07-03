<?php

declare(strict_types=1);

namespace GruffPhp\Results\Trend;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Scoring\ScoreReport;
use GruffPhp\Support\PathHelper;
use RuntimeException;

/**
 * Records score and finding-count snapshots for trend reporting.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendRecorder
{
    /** Maximum retained snapshots per score scope. */
    private const MAX_HISTORY_ENTRIES_PER_SCOPE = 50;

    /**
     * Append a score snapshot to the bounded history file.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param string      $projectRoot - Project root used to resolve the history path.
     * @param string      $path - History file path to write.
     * @param ScoreReport $score - Score report to snapshot.
     * @param int         $findingCount - Total finding count for the snapshot.
     * @throws RuntimeException When the history file cannot be read, validated, or written.
     * @throws \JsonException When history JSON cannot be decoded or encoded.
     *
     * @return TrendReport - Report describing the current score and prior delta.
     */
    public function record(string $projectRoot, string $path, ScoreReport $score, int $findingCount): TrendReport
    {
        $resolvedPath  = PathHelper::resolveAgainst($projectRoot, $path);
        $entries       = $this->readEntries($resolvedPath);
        // The user's trend delta compares like-for-like only: a diff-scoped score is never measured
        // against a full-project predecessor. Every run still appends its own entry.
        $previousScore = $this->scoreFromEntry($this->latestEntryForScope($entries, $score->scope));
        $trendEntry    = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'timestamp' => gmdate(DATE_ATOM),
            'score' => $score->composite->score,
            'grade' => $score->composite->letter,
            'scope' => $score->scope,
            'findings' => $findingCount,
        ];

        $entries[] = $trendEntry;
        $entries   = $this->trimEntriesPerScope($entries);
        $directory = dirname($resolvedPath);

        // User view: choose the score output branch for this case.
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create history directory: %s', $directory));
        }

        // Invalid bytes become U+FFFD so a history write can never crash the user's run; history values
        // are never match keys, so substitution cannot desynchronise anything.
        // User view: choose the score output branch for this case.
        if (file_put_contents($resolvedPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write history file: %s', $resolvedPath));
        }

        return new TrendReport(
            // User view: missing data becomes a safe score output default.
            path:          PathHelper::relativeToRoot($resolvedPath, $projectRoot) ?? PathHelper::canonical($resolvedPath),
            scope:         $score->scope,
            currentScore:  $score->composite->score,
            previousScore: $previousScore,
            // User view: missing data becomes the expected score output state.
            delta:         $previousScore === null ? null : round($score->composite->score - $previousScore, 2),
            entries:       $entries,
        );
    }

    /**
     * Find the most recent history entry recorded with the given scope.
     *
     * Keeps the delta a user sees after `analyse --history-file` honest: a diff-scoped
     * score is only ever compared with an earlier diff-scoped score, never a full run.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<TrendEntry> $entries - Validated history rows in file order.
     * @param string           $scope - Current run's score scope ('full-project' or 'diff').
     *
     * @return TrendEntry|null - latest same-scope row, or null when the history holds none; rows without a
     *                         scope field predate scope stamping and are treated as full-project
     */
    private function latestEntryForScope(array $entries, string $scope): ?array
    {
        // Walk newest-first so the delta compares against the most recent comparable run.
        for ($index = count($entries) - 1; $index >= 0; $index--) {
            // Match same-scope rows only; scope-less rows are legacy history from full runs.
            // User view: choose the score output branch for this case.
            if ($this->entryScope($entries[$index]) === $scope) {
                return $entries[$index];
            }
        }

        return null;
    }

    /**
     * Bound history independently per score scope while preserving file order.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param list<TrendEntry> $entries - Validated history rows plus the new row in file order.
     *
     * @return list<TrendEntry> - newest rows per scope, still ordered oldest-to-newest overall
     */
    private function trimEntriesPerScope(array $entries): array
    {
        $seenByScope = [];
        $keptIndexes = [];

        for ($index = count($entries) - 1; $index >= 0; $index--) {
            $scope               = $this->entryScope($entries[$index]);
            // User view: missing data becomes a safe score output default.
            $seenByScope[$scope] = ($seenByScope[$scope] ?? 0) + 1;

            // User view: choose the score output branch for this case.
            if ($seenByScope[$scope] <= self::MAX_HISTORY_ENTRIES_PER_SCOPE) {
                $keptIndexes[$index] = true;
            }
        }

        ksort($keptIndexes);

        $boundedEntries = [];
        // User view: add each item that can appear in score output.
        foreach (array_keys($keptIndexes) as $index) {
            $boundedEntries[] = $entries[$index];
        }

        return $boundedEntries;
    }

    /**
     * Read a trend entry's scope, treating legacy scope-less entries as full-project.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param TrendEntry $trendEntry - Validated history row.
     *
     * @return string - Entry scope used for delta and retention comparisons.
     */
    private function entryScope(array $trendEntry): string
    {
        // Old history rows had no scope; users should see them as full-project runs.
        // User view: missing data becomes a safe score output default.
        $entryScope = $trendEntry['scope'] ?? null;

        return is_string($entryScope) ? $entryScope : 'full-project';
    }

    /**
     * Read and validate the bounded history file into typed snapshot rows.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param string $path - Resolved history file path; an absent or empty file is treated as no history.
     *
     * @return list<TrendEntry> - Snapshot rows in file order; empty when the file is absent or blank.
     */
    private function readEntries(string $path): array
    {
        $contents = $this->readHistoryFile($path);
        // User view: choose the score output branch for this case.
        // User view: missing data becomes the expected score output state.
        if ($contents === null) {
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        // User view: choose the score output branch for this case.
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
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param string      $path - Resolved history file path to read.
     *
     * @return string|null - Raw file contents, or null when the file is missing or whitespace-only.
     */
    private function readHistoryFile(string $path): ?string
    {
        // User view: choose the score output branch for this case.
        if (!is_file($path)) {
            // A missing file is the first-run case, not an error: caller starts a fresh history.
            return null;
        }

        $contents = file_get_contents($path);
        // User view: choose the score output branch for this case.
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read history file: %s', $path));
        }

        // User view: choose the score output branch for this case.
        // User view: an empty value becomes a clear score output fallback.
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
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param mixed  $trendEntry - Decoded JSON row; must be a string-keyed map, else the file is rejected.
     * @param string $path - History file path, used only to make validation errors point at the file.
     *
     * @return TrendEntry - The row with every value confirmed scalar, ready to fold back into history.
     */
    private function normaliseEntry(mixed $trendEntry, string $path): array
    {
        // User view: choose the score output branch for this case.
        if (!is_array($trendEntry) || array_is_list($trendEntry)) {
            throw new RuntimeException(sprintf('History file contains an invalid entry: %s', $path));
        }

        $normalisedEntry = [];
        // User view: add each item that can appear in score output.
        foreach ($trendEntry as $key => $trendValue) {
            // User view: choose the score output branch for this case.
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('History file contains a non-string entry key: %s', $path));
            }

            $normalisedEntry[$key] = $this->normaliseEntryValue($trendValue, $path);
        }

        return $normalisedEntry;
    }

    /**
     * Assert a single history value is scalar, rejecting nested arrays/objects.
     *
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param mixed  $trendValue - Decoded value to vet; non-scalar shapes are not valid trend data.
     * @param string $path - History file path, surfaced in the error when a value is rejected.
     *
     * @return bool|float|int|string|null - The same value once confirmed scalar.
     */
    private function normaliseEntryValue(mixed $trendValue, string $path): bool|float|int|string|null
    {
        // User view: choose the score output branch for this case.
        // User view: missing data becomes the expected score output state.
        if (is_bool($trendValue) || is_float($trendValue) || is_int($trendValue) || is_string($trendValue) || $trendValue === null) {
            return $trendValue;
        }

        throw new RuntimeException(sprintf('History file contains a non-scalar entry value: %s', $path));
    }

    /**
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param TrendEntry|null $trendEntry - Trend-history row to read, or null when the history has no previous entry.
     *
     * @return float|null - Score value from the entry, or null when absent.
     */
    private function scoreFromEntry(?array $trendEntry): ?float
    {
        // User view: missing data becomes a safe score output default.
        $score = $trendEntry['score'] ?? null;

        // A missing or non-numeric score yields null so the delta is reported as unknown, not zero.
        return is_int($score) || is_float($score) ? (float) $score : null;
    }

}
