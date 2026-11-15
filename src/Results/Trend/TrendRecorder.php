<?php

declare(strict_types=1);

namespace GruffPhp\Results\Trend;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Scoring\ScoreReport;
use GruffPhp\Support\PathHelper;
use RuntimeException;

/**
 * Records scored runs in a history file and compares each score with the latest entry from the same scope.
 * Retention is capped per scope, so full-project and diff runs keep separate histories.
 *
 * Missing or blank files start a new history. Nonblank files must decode to a list of string-keyed rows with scalar or null values.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendRecorder
{
    /** Maximum retained snapshots per score scope. */
    private const MAX_HISTORY_ENTRIES_PER_SCOPE = 50;

    /**
     * Record this run's score and compare it with the latest same-scope row's score.
     * The delta is null when that row is absent or has no numeric score.
     *
     * @param string      $projectRoot  - Project root used to resolve the history path.
     * @param string      $path         - History file path to append to.
     * @param ScoreReport $score        - Score report to snapshot.
     * @param int         $findingCount - Total finding count recorded alongside the score.
     * @throws RuntimeException - When the current score is absent or the history cannot be read, validated, or written.
     * @throws \JsonException - When history JSON cannot be decoded or encoded.
     *
     * @return TrendReport - Current score and change from the last same-scope run; null delta means no usable earlier score.
     */
    public function record(string $projectRoot, string $path, ScoreReport $score, int $findingCount): TrendReport
    {
        $composite = $score->composite;

        // A report without a composite cannot form a trend point, so rejecting it keeps later comparisons
        // from treating a scoreless row as the latest same-scope entry.
        if ($composite === null) {
            throw new RuntimeException('A run that evaluated nothing has no score to record in the trend history.');
        }

        $resolvedPath  = PathHelper::resolveAgainst($projectRoot, $path);
        $entries       = $this->readEntries($resolvedPath);
        // The user's trend delta compares like-for-like only: a diff-scoped score is never measured
        // against a full-project predecessor. Every run still appends its own entry.
        $previousScore = $this->scoreFromEntry($this->latestEntryForScope($entries, $score->scope));
        $trendEntry    = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'timestamp' => gmdate(DATE_ATOM),
            'score' => $composite->score,
            'grade' => $composite->letter,
            'scope' => $score->scope,
            'findings' => $findingCount,
        ];

        $entries[] = $trendEntry;
        $entries   = $this->trimEntriesPerScope($entries);
        $directory = dirname($resolvedPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create history directory: %s', $directory));
        }

        // During encoding, invalid UTF-8 is replaced rather than causing a JSON error.
        if (file_put_contents($resolvedPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write history file: %s', $resolvedPath));
        }

        return new TrendReport(
            // Show the history path relative to the project when possible, falling back to its canonical form.
            path:          PathHelper::relativeToRoot($resolvedPath, $projectRoot) ?? PathHelper::canonical($resolvedPath),
            scope:         $score->scope,
            currentScore:  $composite->score,
            previousScore: $previousScore,
            // No previous same-scope run means there is no movement to report.
            delta:         $previousScore === null ? null : round($composite->score - $previousScore, 2),
            entries:       $entries,
        );
    }

    /**
     * Find the latest entry with the same scope so a diff run is never compared with a full-project run.
     *
     * @param list<TrendEntry> $entries - Validated history rows in file order.
     * @param string           $scope   - Current run's score scope ('full-project' or 'diff').
     *
     * @return TrendEntry|null - Latest same-scope row, or null when none exists; rows with no scope count as full-project.
     */
    private function latestEntryForScope(array $entries, string $scope): ?array
    {
        for ($index = count($entries) - 1; $index >= 0; $index--) {
            if ($this->entryScope($entries[$index]) === $scope) {
                return $entries[$index];
            }
        }

        return null;
    }

    /**
     * Caps the history at the newest N entries per scope, so a long-lived project's trend file stays
     * bounded without losing the recent points the user actually looks at.
     *
     * @param list<TrendEntry> $entries - Validated history rows plus the new row, in file order.
     *
     * @return list<TrendEntry> - The kept rows: newest per scope, still ordered oldest-to-newest overall.
     */
    private function trimEntriesPerScope(array $entries): array
    {
        $seenByScope = [];
        $keptIndexes = [];

        for ($index = count($entries) - 1; $index >= 0; $index--) {
            $scope               = $this->entryScope($entries[$index]);
            $seenByScope[$scope] = ($seenByScope[$scope] ?? 0) + 1;

            if ($seenByScope[$scope] <= self::MAX_HISTORY_ENTRIES_PER_SCOPE) {
                $keptIndexes[$index] = true;
            }
        }

        ksort($keptIndexes);

        $boundedEntries = [];
        foreach (array_keys($keptIndexes) as $index) {
            $boundedEntries[] = $entries[$index];
        }

        return $boundedEntries;
    }

    /**
     * Reads an entry's string scope; a missing or non-string value falls back to a full-project run.
     *
     * @param TrendEntry $trendEntry - History row used for delta comparison and retention grouping.
     *
     * @return string - The entry's scope, used for delta and retention comparisons; 'full-project' for rows recorded before scope stamping.
     */
    private function entryScope(array $trendEntry): string
    {
        $entryScope = $trendEntry['scope'] ?? null;

        return is_string($entryScope) ? $entryScope : 'full-project';
    }

    /**
     * Reads and validates the history file into typed rows, so the rest of the recorder can trust the
     * shape even if the file on disk was hand-edited or corrupted.
     *
     * @param string $path - Resolved history file path; an absent or empty file is treated as no history.
     *
     * @return list<TrendEntry> - Snapshot rows in file order; empty when the file is absent or blank.
     */
    private function readEntries(string $path): array
    {
        $contents = $this->readHistoryFile($path);
        // A missing or blank file means no history yet, so start from an empty list.
        if ($contents === null) {
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        // The file must hold a JSON array of rows; anything else is corrupt and fails loudly.
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(sprintf('History file must contain a JSON array: %s', $path));
        }

        return array_map(
            fn (mixed $trendEntry): array => $this->normaliseEntry($trendEntry, $path),
            $decoded,
        );
    }

    /**
     * Reads the raw history bytes, distinguishing "no history yet" from a genuinely unreadable file.
     *
     * @param string $path - Resolved history file path to read.
     *
     * @return string|null - Raw file contents; null when the file is missing or whitespace-only, both meaning "no history".
     */
    private function readHistoryFile(string $path): ?string
    {
        // A missing file is the first-run case, so there is nothing to read yet.
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read history file: %s', $path));
        }

        // A whitespace-only file represents no earlier snapshots.
        if (trim($contents) === '') {
            return null;
        }

        // Defer JSON parsing to the caller; this method only owns presence and readability.
        return $contents;
    }

    /**
     * Validate that a decoded history row has string keys and only scalar or null values.
     *
     * @param mixed  $trendEntry - Decoded JSON row; must be a string-keyed map, else the file is rejected.
     * @param string $path       - History file path, used only to make validation errors point at the file.
     *
     * @return TrendEntry - The row with string keys and scalar or null values, ready to retain in history.
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

        return $normalisedEntry;
    }

    /**
     * Accept scalar or null history values and reject nested structures.
     *
     * @param mixed  $trendValue - Decoded history value to validate; only scalars and null are retained.
     * @param string $path       - History file path, surfaced in the error when a value is rejected.
     *
     * @return bool|float|int|string|null - Accepted scalar or null value, unchanged.
     */
    private function normaliseEntryValue(mixed $trendValue, string $path): bool|float|int|string|null
    {
        if (is_bool($trendValue) || is_float($trendValue) || is_int($trendValue) || is_string($trendValue) || $trendValue === null) {
            return $trendValue;
        }

        throw new RuntimeException(sprintf('History file contains a non-scalar entry value: %s', $path));
    }

    /**
     * Pulls the numeric score out of a history row, or null when there is no usable earlier score to
     * form a delta from.
     *
     * @param TrendEntry|null $trendEntry - Trend-history row to read; null when the history has no previous entry.
     *
     * @return float|null - Numeric score, or null for an absent row or unusable score so the delta remains unknown.
     */
    private function scoreFromEntry(?array $trendEntry): ?float
    {
        $score = $trendEntry['score'] ?? null;

        // A missing or non-numeric score yields null so the delta is reported as unknown, not zero.
        return is_int($score) || is_float($score) ? (float) $score : null;
    }

}
