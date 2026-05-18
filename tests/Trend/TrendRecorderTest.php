<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Trend;

use GruffPhp\Scoring\Grade;
use GruffPhp\Scoring\ScoreReport;
use GruffPhp\Trend\TrendRecorder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers TrendRecorderTest behavior.
 */
final class TrendRecorderTest extends TestCase
{
    /**
     * Verify record appends entry and calculates previous score delta.
     *
     * @return void No return value.
     */
    public function testRecordAppendsEntryAndCalculatesPreviousScoreDelta(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode([
                [
                    'schemaVersion' => 'gruff.analysis.v1',
                    'timestamp' => '2026-05-12T00:00:00+00:00',
                    'score' => 80.0,
                    'grade' => 'B',
                    'scope' => 'full-project',
                    'findings' => 12,
                ],
            ], JSON_THROW_ON_ERROR));

            $report = (new TrendRecorder())->record($root, 'history.json', $this->score(90.25), 3);

            self::assertSame('history.json', $report->path);
            self::assertSame(90.25, $report->currentScore);
            self::assertSame(80.0, $report->previousScore);
            self::assertSame(10.25, $report->delta);
            self::assertCount(2, $report->entries);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify record creates nested history, keeps the newest fifty entries, and persists scalar payloads.
     *
     * @return void No return value.
     */
    public function testRecordCreatesNestedHistoryAndBoundsPersistedEntries(): void
    {
        $root = $this->tempDir();

        try {
            $history = [];
            for ($index = 1; $index <= 55; $index++) {
                $history[] = [
                    'schemaVersion' => 'gruff.analysis.v1',
                    'timestamp' => sprintf('2026-05-12T00:%02d:00+00:00', $index % 60),
                    'score' => (float) $index,
                    'grade' => 'D',
                    'scope' => 'full-project',
                    'findings' => $index,
                ];
            }

            self::assertTrue(mkdir($root . '/var', 0777, true));
            file_put_contents($root . '/var/history.json', json_encode($history, JSON_THROW_ON_ERROR));

            $report = (new TrendRecorder())->record($root, 'var/nested/history.json', $this->score(91.0), 7);
            $persisted = json_decode((string) file_get_contents($root . '/var/nested/history.json'), true);

            self::assertSame('var/nested/history.json', $report->path);
            self::assertNull($report->previousScore);
            self::assertNull($report->delta);
            self::assertCount(1, $report->entries);
            self::assertIsArray($persisted);
            $firstPersisted = $persisted[0] ?? null;
            self::assertIsArray($firstPersisted);
            self::assertCount(1, $persisted);
            self::assertSame('gruff.analysis.v1', $firstPersisted['schemaVersion'] ?? null);
            self::assertSame(91, $firstPersisted['score'] ?? null);
            self::assertSame('A', $firstPersisted['grade'] ?? null);
            self::assertSame('full-project', $firstPersisted['scope'] ?? null);
            self::assertSame(7, $firstPersisted['findings'] ?? null);

            $report = (new TrendRecorder())->record($root, 'var/history.json', $this->score(92.0), 8);
            $persisted = json_decode((string) file_get_contents($root . '/var/history.json'), true);

            self::assertSame(55.0, $report->previousScore);
            self::assertSame(37.0, $report->delta);
            self::assertCount(50, $report->entries);
            self::assertIsArray($persisted);
            $firstPersisted = $persisted[0] ?? null;
            $lastPersisted  = $persisted[49] ?? null;
            self::assertIsArray($firstPersisted);
            self::assertIsArray($lastPersisted);
            self::assertCount(50, $persisted);
            self::assertSame(7, $firstPersisted['score'] ?? null);
            self::assertSame(92, $lastPersisted['score'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify record treats missing and empty history files as no prior score.
     *
     * @return void No return value.
     */
    public function testRecordTreatsMissingAndEmptyHistoryAsNoPriorScore(): void
    {
        $root = $this->tempDir();

        try {
            $missingReport = (new TrendRecorder())->record($root, 'missing.json', $this->score(70.0), 2);
            file_put_contents($root . '/empty.json', " \n\t");
            $emptyReport = (new TrendRecorder())->record($root, 'empty.json', $this->score(71.0), 3);

            self::assertNull($missingReport->previousScore);
            self::assertNull($missingReport->delta);
            self::assertNull($emptyReport->previousScore);
            self::assertNull($emptyReport->delta);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify record rejects non-list history roots and list entries.
     *
     * @return void No return value.
     */
    public function testRecordRejectsInvalidHistoryShape(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode(['score' => 80.0], JSON_THROW_ON_ERROR));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('History file must contain a JSON array');

            (new TrendRecorder())->record($root, 'history.json', $this->score(90.25), 3);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify record rejects list-shaped history entries.
     *
     * @return void No return value.
     */
    public function testRecordRejectsListHistoryEntries(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode([['score', 80.0]], JSON_THROW_ON_ERROR));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('History file contains an invalid entry');

            (new TrendRecorder())->record($root, 'history.json', $this->score(90.25), 3);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify record rejects nested history entry values.
     *
     * @return void No return value.
     */
    public function testRecordRejectsNestedHistoryEntryValues(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode([
                [
                    'score' => 80.0,
                    'nested' => ['not' => 'allowed'],
                ],
            ], JSON_THROW_ON_ERROR));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('History file contains a non-scalar entry value');

            (new TrendRecorder())->record($root, 'history.json', $this->score(90.25), 3);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Build a score report fixture for trend assertions.
     *
     * @param float $score Fixture value.
     * @return ScoreReport Fixture value.
     */
    private function score(float $score): ScoreReport
    {
        return new ScoreReport(
            composite:              Grade::fromScore($score),
            pillars:                [],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  'full-project',
            explanation:            'Example score.',
        );
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string Fixture value.
     */
    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-trend-test-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Filesystem path.
     * @return void No return value.
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }

            $child = $path . '/' . $directoryEntry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
