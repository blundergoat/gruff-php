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

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
