<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Trend;

use GruffPhp\Results\Scoring\Grade;
use GruffPhp\Results\Scoring\ScoreReport;
use GruffPhp\Results\Trend\TrendRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers trend history recording: previous-score delta computation, nested history bounding, missing-history handling, and rejection of invalid
 * payloads.
 *
 * @phpstan-type InvalidHistoryScalar bool|float|int|string
 * @phpstan-type InvalidHistoryNested array<array-key, InvalidHistoryScalar>
 * @phpstan-type InvalidHistoryPayload array<array-key, InvalidHistoryScalar|array<array-key, InvalidHistoryScalar|InvalidHistoryNested>>
 */
final class TrendRecorderTest extends TestCase
{
    /**
     * Verify record appends entry and calculates previous score delta.
     *
     * @return void
     */
    public function testRecordAppendsEntryAndCalculatesPreviousScoreDelta(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode([
                                                                       [
                                                                           'schemaVersion' => 'gruff.analysis.v2',
                                                                           'timestamp'     => '2026-05-12T00:00:00+00:00',
                                                                           'score'         => 80.0,
                                                                           'grade'         => 'B',
                                                                           'scope'         => 'full-project',
                                                                           'findings'      => 12,
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
     * Verify deltas compare only like-for-like scopes across mixed history entries.
     *
     * @return void
     */
    public function testRecordComparesOnlySameScopeEntries(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode([
                                                                       [
                                                                           'schemaVersion' => 'gruff.analysis.v2',
                                                                           'timestamp'     => '2026-05-12T00:00:00+00:00',
                                                                           'score'         => 80.0,
                                                                           'grade'         => 'B',
                                                                           'scope'         => 'full-project',
                                                                           'findings'      => 12,
                                                                       ],
                                                                       [
                                                                           'schemaVersion' => 'gruff.analysis.v2',
                                                                           'timestamp'     => '2026-05-13T00:00:00+00:00',
                                                                           'score'         => 60.0,
                                                                           'grade'         => 'D',
                                                                           'scope'         => 'diff',
                                                                           'findings'      => 4,
                                                                       ],
                                                                   ], JSON_THROW_ON_ERROR));

            // A full-project run skips the interleaved diff entry and compares against the full-project one.
            $fullReport = (new TrendRecorder())->record($root, 'history.json', $this->score(90.0), 3);

            self::assertSame('full-project', $fullReport->scope);
            self::assertSame(80.0, $fullReport->previousScore);
            self::assertSame(10.0, $fullReport->delta);

            // A diff-scoped run compares against the latest diff entry, never the full-project scores.
            $diffReport = (new TrendRecorder())->record($root, 'history.json', $this->score(70.0, 'diff'), 2);

            self::assertSame('diff', $diffReport->scope);
            self::assertSame(60.0, $diffReport->previousScore);
            self::assertSame(10.0, $diffReport->delta);
            self::assertCount(4, $diffReport->entries);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify a diff run against full-project-only history records its entry but reports no delta.
     *
     * @return void
     */
    public function testDiffRunAgainstFullProjectHistoryReportsNoDelta(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode([
                                                                       [
                                                                           'schemaVersion' => 'gruff.analysis.v2',
                                                                           'timestamp'     => '2026-05-12T00:00:00+00:00',
                                                                           'score'         => 80.0,
                                                                           'grade'         => 'B',
                                                                           'scope'         => 'full-project',
                                                                           'findings'      => 12,
                                                                       ],
                                                                   ], JSON_THROW_ON_ERROR));

            $report = (new TrendRecorder())->record($root, 'history.json', $this->score(40.0, 'diff'), 9);

            self::assertSame('diff', $report->scope);
            self::assertNull($report->previousScore);
            self::assertNull($report->delta);
            self::assertCount(2, $report->entries);
            self::assertSame('diff', $report->entries[1]['scope'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify legacy entries without a scope field compare as full-project history.
     *
     * @return void
     */
    public function testLegacyEntriesWithoutScopeCompareAsFullProject(): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode([
                                                                       [
                                                                           'schemaVersion' => 'gruff.analysis.v2',
                                                                           'timestamp'     => '2026-05-12T00:00:00+00:00',
                                                                           'score'         => 75.0,
                                                                           'grade'         => 'C',
                                                                           'findings'      => 20,
                                                                       ],
                                                                   ], JSON_THROW_ON_ERROR));

            $fullReport = (new TrendRecorder())->record($root, 'history.json', $this->score(85.0), 5);

            self::assertSame(75.0, $fullReport->previousScore);
            self::assertSame(10.0, $fullReport->delta);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Verify record creates nested history, keeps the newest fifty entries, and persists scalar payloads.
     *
     * @return void
     */
    public function testRecordCreatesNestedHistoryAndBoundsPersistedEntries(): void
    {
        $root = $this->tempDir();

        try {
            $history = [];
            for ($index = 1; $index <= 55; $index++) {
                $history[] = [
                    'schemaVersion' => 'gruff.analysis.v2',
                    'timestamp'     => sprintf('2026-05-12T00:%02d:00+00:00', $index % 60),
                    'score'         => (float)$index,
                    'grade'         => 'D',
                    'scope'         => 'full-project',
                    'findings'      => $index,
                ];
            }

            self::assertTrue(mkdir($root . '/var', 0777, true));
            file_put_contents($root . '/var/history.json', json_encode($history, JSON_THROW_ON_ERROR));

            $report    = (new TrendRecorder())->record($root, 'var/nested/history.json', $this->score(91.0), 7);
            $persisted = json_decode((string)file_get_contents($root . '/var/nested/history.json'), true);

            self::assertSame('var/nested/history.json', $report->path);
            self::assertNull($report->previousScore);
            self::assertNull($report->delta);
            self::assertCount(1, $report->entries);
            self::assertIsArray($persisted);
            $firstPersisted = $persisted[0] ?? null;
            self::assertIsArray($firstPersisted);
            self::assertCount(1, $persisted);
            self::assertSame('gruff.analysis.v2', $firstPersisted['schemaVersion'] ?? null);
            self::assertSame(91, $firstPersisted['score'] ?? null);
            self::assertSame('A', $firstPersisted['grade'] ?? null);
            self::assertSame('full-project', $firstPersisted['scope'] ?? null);
            self::assertSame(7, $firstPersisted['findings'] ?? null);

            $report    = (new TrendRecorder())->record($root, 'var/history.json', $this->score(92.0), 8);
            $persisted = json_decode((string)file_get_contents($root . '/var/history.json'), true);

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
     * @return void
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
     * Verify record rejects invalid history JSON shapes.
     *
     * @param InvalidHistoryPayload $historyPayload - Invalid history payload.
     * @param string                $message - Expected exception message.
     *
     * @return void
     */
    #[DataProvider('invalidHistoryProvider')]
    public function testRecordRejectsInvalidHistoryPayloads(array $historyPayload, string $message): void
    {
        $root = $this->tempDir();

        try {
            file_put_contents($root . '/history.json', json_encode($historyPayload, JSON_THROW_ON_ERROR));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($message);

            (new TrendRecorder())->record($root, 'history.json', $this->score(90.25), 3);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * Provide invalid persisted history payloads.
     *
     * @return iterable<string, array{0: InvalidHistoryPayload, 1: string}> - data-provider cases keyed by name, each pairing a malformed history
     *                          payload with the exception message it must trigger
     */
    public static function invalidHistoryProvider(): iterable
    {
        yield 'object root' => [
            ['score' => 80.0],
            'History file must contain a JSON array',
        ];
        yield 'list entry' => [
            [['score', 80.0]],
            'History file contains an invalid entry',
        ];
        yield 'nested entry value' => [
            [
                [
                    'score'  => 80.0,
                    'nested' => ['not' => 'allowed'],
                ],
            ],
            'History file contains a non-scalar entry value',
        ];
    }

    /**
     * Build a score report fixture for trend assertions.
     *
     * @param float  $score - Composite score value to place in the trend fixture.
     * @param string $scope - Score scope stamped on the report; varied to prove scope-aware delta selection.
     *
     * @return ScoreReport - a minimal report graded from the given score, with empty pillars and offenders
     */
    private function score(float $score, string $scope = 'full-project'): ScoreReport
    {
        return new ScoreReport(
            composite:              Grade::fromScore($score),
            pillars:                [],
            topOffenders:           [],
            complexityDistribution: [],
            scope:                  $scope,
            explanation:            'Example score.',
        );
    }

    /**
     * Create a temporary directory for filesystem assertions.
     *
     * @return string - absolute path to the freshly created, unique, empty temp directory
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
     * @param string $path - Filesystem path.
     *
     * @return void
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            // Nothing to clean up when the path is absent, so stop the recursion here.
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
