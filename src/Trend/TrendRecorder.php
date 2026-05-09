<?php

declare(strict_types=1);

namespace GruffPhp\Trend;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Scoring\ScoreReport;
use RuntimeException;

final readonly class TrendRecorder
{
    public function record(string $projectRoot, string $path, ScoreReport $score, int $findingCount): TrendReport
    {
        $resolvedPath = $this->absolutePath($projectRoot, $path);
        $entries = $this->readEntries($resolvedPath);
        $previous = $entries === [] ? null : $entries[array_key_last($entries)];
        $previousScore = $this->scoreFromEntry($previous);
        $entry = [
            'schemaVersion' => AnalysisReport::SCHEMA_VERSION,
            'timestamp' => gmdate(DATE_ATOM),
            'score' => $score->composite->score,
            'grade' => $score->composite->letter,
            'scope' => $score->scope,
            'findings' => $findingCount,
        ];

        $entries[] = $entry;
        $entries = array_slice($entries, -50);
        $directory = dirname($resolvedPath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create history directory: %s', $directory));
        }

        file_put_contents($resolvedPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return new TrendReport(
            path: $this->displayPath($projectRoot, $resolvedPath),
            currentScore: $score->composite->score,
            previousScore: $previousScore,
            delta: $previousScore === null ? null : round($score->composite->score - $previousScore, 2),
            entries: $entries,
        );
    }

    /**
     * @return list<array<string, mixed>>
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

                $normalisedEntry[$key] = $value;
            }

            $entries[] = $normalisedEntry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed>|null $entry
     */
    private function scoreFromEntry(?array $entry): ?float
    {
        $score = $entry['score'] ?? null;

        return is_int($score) || is_float($score) ? (float) $score : null;
    }

    private function absolutePath(string $projectRoot, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . $path;
    }

    private function displayPath(string $projectRoot, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');
        $normalisedPath = str_replace('\\', '/', realpath($path) ?: $path);

        if (str_starts_with($normalisedPath, $root . '/')) {
            return substr($normalisedPath, strlen($root) + 1);
        }

        return $normalisedPath;
    }
}
