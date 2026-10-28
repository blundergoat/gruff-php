<?php

declare(strict_types=1);

namespace GruffPhp\Results\Trend;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Results\Scoring\ScoreReport;
use GruffPhp\Support\PathHelper;
use RuntimeException;

/**
 * Appends each run's score to a bounded per-project history file, so a user can watch their quality
 * trend over time.
 *
 * When a user runs `analyse` with a history file, this records a snapshot - score, grade, scope,
 * finding count, timestamp - and hands back a `TrendReport` showing how the score moved since the last
 * comparable run. It keeps the history honest and bounded: deltas only ever compare like scopes (a diff
 * run against an earlier diff run), history is capped per scope so the file cannot grow forever, and a
 * tampered or half-written file is rejected loudly rather than silently corrupting the trend.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendRecorder
{
    /** Maximum retained snapshots per score scope. */
    private const MAX_HISTORY_ENTRIES_PER_SCOPE = 50;

    /**
     * Records this run's score in the history file and returns how it compares to the last same-scope
     * run, so the user sees "82 (+3)" rather than a bare number.
     *
     * @param string      $projectRoot - Project root used to resolve the history path.
     * @param string      $path - History file path to append to.
     * @param ScoreReport $score - Score report to snapshot.
     * @param int         $findingCount - Total finding count recorded alongside the score.
     * @throws RuntimeException When the history file cannot be read, validated, or written.
     * @throws \JsonException When history JSON cannot be decoded or encoded.
     *
     * @return TrendReport - The current score plus the delta from the previous same-scope run; the delta is null when there is no comparable earlier run.
     */
    public function record(string $projectRoot, string $path, ScoreReport $score, int $findingCount): TrendReport
    {
        $composite = $score->composite;

        // A run that evaluated nothing has no score to record. The caller already filters these out;
        // refusing here as well keeps a scoreless row out of the history every later delta reads from.
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

        // Make sure the history file's directory exists before writing, creating it when the user's path is new.
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create history directory: %s', $directory));
        }

        // Invalid bytes become U+FFFD so a history write can never crash the user's run; history values
        // are never match keys, so substitution cannot desynchronise anything.
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
     * Finds the most recent history entry with the same scope, so the delta compares a diff run only
     * against an earlier diff run and never against a full-project one.
     *
     * Keeps the delta a user sees after `analyse --history-file` honest: a diff-scoped score is only
     * ever compared with an earlier diff-scoped score, never a full run.
     *
     * @param list<TrendEntry> $entries - Validated history rows in file order.
     * @param string           $scope - Current run's score scope ('full-project' or 'diff').
     *
     * @return TrendEntry|null - Latest same-scope row; null when the history holds none. Rows without a scope field predate scope stamping and count as full-project.
     */
    private function latestEntryForScope(array $entries, string $scope): ?array
    {
        // Walk newest-first so the delta compares against the most recent comparable run.
        for ($index = count($entries) - 1; $index >= 0; $index--) {
            // Match same-scope rows only; scope-less rows are legacy history from full runs.
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

        // Scan newest-first, keeping each scope's rows only until it hits its per-scope cap.
        for ($index = count($entries) - 1; $index >= 0; $index--) {
            $scope               = $this->entryScope($entries[$index]);
            // Count how many of this scope we have seen so far in the newest-first scan.
            $seenByScope[$scope] = ($seenByScope[$scope] ?? 0) + 1;

            // Keep this row while its scope is still under the cap; older ones beyond it are dropped.
            if ($seenByScope[$scope] <= self::MAX_HISTORY_ENTRIES_PER_SCOPE) {
                $keptIndexes[$index] = true;
            }
        }

        ksort($keptIndexes);

        $boundedEntries = [];
        // Re-emit the kept rows in their original oldest-to-newest order.
        foreach (array_keys($keptIndexes) as $index) {
            $boundedEntries[] = $entries[$index];
        }

        return $boundedEntries;
    }

    /**
     * Reads an entry's scope, treating a legacy scope-less row as a full-project run.
     *
     * @param TrendEntry $trendEntry - Validated history row.
     *
     * @return string - The entry's scope, used for delta and retention comparisons; 'full-project' for rows recorded before scope stamping.
     */
    private function entryScope(array $trendEntry): string
    {
        // Old history rows had no scope; users should see them as full-project runs.
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

        // Each decoded row is re-validated so a tampered history file fails loudly rather than silently.
        return array_map(
            fn (mixed $trendEntry): array => $this->normaliseEntry($trendEntry, $path),
            $decoded,
        );
    }

    /**
     * Reads the raw history bytes, distinguishing "no history yet" from a genuinely unreadable file.
     *
     * @param string      $path - Resolved history file path to read.
     *
     * @return string|null - Raw file contents; null when the file is missing or whitespace-only, both meaning "no history".
     */
    private function readHistoryFile(string $path): ?string
    {
        // A missing file is the first-run case, so there is nothing to read yet.
        if (!is_file($path)) {
            // A missing file is the first-run case, not an error: caller starts a fresh history.
            return null;
        }

        $contents = file_get_contents($path);
        // The file exists but could not be read, which is a real error to surface.
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read history file: %s', $path));
        }

        // A blank file counts as no history, so a truncated earlier write can't crash this run.
        if (trim($contents) === '') {
            // A blank file is treated like no history so a truncated write cannot crash the next run.
            return null;
        }

        // Defer JSON parsing to the caller; this method only owns presence and readability.
        return $contents;
    }

    /**
     * Validates one decoded history row is a string-keyed map and keeps its scalar values, rejecting a
     * tampered file rather than trusting it.
     *
     * @param mixed  $trendEntry - Decoded JSON row; must be a string-keyed map, else the file is rejected.
     * @param string $path - History file path, used only to make validation errors point at the file.
     *
     * @return TrendEntry - The row with every value confirmed scalar, ready to fold back into history.
     */
    private function normaliseEntry(mixed $trendEntry, string $path): array
    {
        // A row has to be a keyed object; a bare list or a scalar means the file is malformed.
        if (!is_array($trendEntry) || array_is_list($trendEntry)) {
            throw new RuntimeException(sprintf('History file contains an invalid entry: %s', $path));
        }

        $normalisedEntry = [];
        // Vet every key and value in the row before trusting any of it.
        foreach ($trendEntry as $key => $trendValue) {
            // History keys must be strings; a numeric key signals a corrupted file.
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('History file contains a non-string entry key: %s', $path));
            }

            $normalisedEntry[$key] = $this->normaliseEntryValue($trendValue, $path);
        }

        return $normalisedEntry;
    }

    /**
     * Confirms a single history value is a scalar, rejecting the nested arrays or objects that are not
     * valid trend data.
     *
     * @param mixed  $trendValue - Decoded value to vet; non-scalar shapes are not valid trend data.
     * @param string $path - History file path, surfaced in the error when a value is rejected.
     *
     * @return bool|float|int|string|null - The same value once confirmed scalar.
     */
    private function normaliseEntryValue(mixed $trendValue, string $path): bool|float|int|string|null
    {
        // Only plain scalars belong in a history row; a nested structure means the file was tampered with.
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
     * @return float|null - The row's score; null when there is no previous entry or its score is missing or non-numeric, so the delta reads as unknown rather than zero.
     */
    private function scoreFromEntry(?array $trendEntry): ?float
    {
        $score = $trendEntry['score'] ?? null;

        // A missing or non-numeric score yields null so the delta is reported as unknown, not zero.
        return is_int($score) || is_float($score) ? (float) $score : null;
    }

}
