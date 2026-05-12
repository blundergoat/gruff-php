<?php

declare(strict_types=1);

namespace GruffPhp\Trend;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Scoring\ScoreReport;
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
        $resolvedPath  = $this->absolutePath($projectRoot, $path);
        $entries       = $this->readEntries($resolvedPath);
        $previous      = $entries === [] ? null : $entries[array_key_last($entries)];
        $previousScore = $this->scoreFromEntry($previous);
        $entry         = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'timestamp' => gmdate(DATE_ATOM),
            'score' => $score->composite->score,
            'grade' => $score->composite->letter,
            'scope' => $score->scope,
            'findings' => $findingCount,
        ];

        $entries[] = $entry;
        $entries   = array_slice($entries, -50);
        $directory = dirname($resolvedPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create history directory: %s', $directory));
        }

        if (file_put_contents($resolvedPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
            throw new RuntimeException(sprintf('Unable to write history file: %s', $resolvedPath));
        }

        return new TrendReport(
            path:          $this->displayPath($projectRoot, $resolvedPath),
            currentScore:  $score->composite->score,
            previousScore: $previousScore,
            delta:         $previousScore === null ? null : round($score->composite->score - $previousScore, 2),
            entries:       $entries,
        );
    }

    /**
     * @return list<TrendEntry>
     */
    private function readEntries(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(sprintf('History file must contain a JSON array: %s', $path));
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException(sprintf('History file contains an invalid entry: %s', $path));
            }

            $normalisedEntry = [];
            foreach ($entry as $key => $value) {
                if (!is_string($key)) {
                    throw new RuntimeException(sprintf('History file contains a non-string entry key: %s', $path));
                }

                if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value) && $value !== null) {
                    throw new RuntimeException(sprintf('History file contains a non-scalar entry value: %s', $path));
                }

                $normalisedEntry[$key] = $value;
            }

            $entries[] = $normalisedEntry;
        }

        return $entries;
    }

    /**
     * @param TrendEntry|null $entry
     *
     * @return float|null Score value from the entry, or null when absent.
     */
    private function scoreFromEntry(?array $entry): ?float
    {
        $score = $entry['score'] ?? null;

        return is_int($score) || is_float($score) ? (float) $score : null;
    }

    /**
     * Resolve a history path relative to the project root when needed.
     *
     * @return string Absolute history file path.
     */
    private function absolutePath(string $projectRoot, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . $path;
    }

    /**
     * Convert a history path to a project-relative display path when possible.
     *
     * @return string Display path for report output.
     */
    private function displayPath(string $projectRoot, string $path): string
    {
        $root           = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');
        $normalisedPath = str_replace('\\', '/', realpath($path) ?: $path);

        if (str_starts_with($normalisedPath, $root . '/')) {
            return substr($normalisedPath, strlen($root) + 1);
        }

        return $normalisedPath;
    }
}
