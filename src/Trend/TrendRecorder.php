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

        return new TrendReport(
            path:          PathHelper::relativeToRoot($resolvedPath, $projectRoot) ?? PathHelper::canonical($resolvedPath),
            currentScore:  $score->composite->score,
            previousScore: $previousScore,
            delta:         $previousScore === null ? null : round($score->composite->score - $previousScore, 2),
            entries:       $entries,
        );
    }

    /**
     * Read entries for the component.
     *
     * @return list<TrendEntry>
     */
    private function readEntries(string $path): array
    {
        $contents = $this->readHistoryFile($path);
        if ($contents === null) {
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(sprintf('History file must contain a JSON array: %s', $path));
        }

        return array_map(
            fn (mixed $trendEntry): array => $this->normaliseEntry($trendEntry, $path),
            $decoded,
        );
    }

    /**
     * @return string|null History file contents, or null when absent/empty.
     */
    private function readHistoryFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read history file: %s', $path));
        }

        if (trim($contents) === '') {
            return null;
        }

        return $contents;
    }

    /**
     * Validate one decoded history row and preserve its scalar values.
     *
     * @return TrendEntry
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
     * @return bool|float|int|string|null Scalar trend entry value.
     */
    private function normaliseEntryValue(mixed $trendValue, string $path): bool|float|int|string|null
    {
        if (is_bool($trendValue) || is_float($trendValue) || is_int($trendValue) || is_string($trendValue) || $trendValue === null) {
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

        return is_int($score) || is_float($score) ? (float) $score : null;
    }

}
