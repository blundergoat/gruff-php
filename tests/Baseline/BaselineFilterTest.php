<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Baseline;

use GruffPhp\Results\Baseline\BaselineData;
use GruffPhp\Results\Baseline\BaselineEntry;
use GruffPhp\Results\Baseline\BaselineFilter;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Covers count-based group matching between live findings and baseline entries.
 */
final class BaselineFilterTest extends TestCase
{
    /**
     * Verify a line-shifted finding still matches its baseline group.
     *
     * @return void
     */
    public function testLineShiftedFindingStaysUnchanged(): void
    {
        $baseline = new BaselineData('gruff-baseline.json', [
            $this->acceptedGroup(count: 1),
        ]);
        $shiftedLine = 20;

        $application = (new BaselineFilter())->apply($baseline, [$this->finding(line: $shiftedLine)], false);

        self::assertSame([], $application['new']);
        self::assertCount(1, $application['unchanged']);
        self::assertSame(0, $application['report']->newCount);
        self::assertSame(1, $application['report']->unchangedCount);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Verify an empty baseline reports every finding as new.
     *
     * @return void
     */
    public function testEmptyBaselineReportsAllFindingsNew(): void
    {
        $baseline         = new BaselineData('gruff-baseline.json', []);
        $liveFindingCount = 2;

        $application = (new BaselineFilter())->apply($baseline, [$this->finding(line: 5), $this->finding(line: 9)], false);

        self::assertCount($liveFindingCount, $application['new']);
        self::assertSame([], $application['unchanged']);
        self::assertSame($liveFindingCount, $application['report']->newCount);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Verify excess instances beyond the accepted count surface as new, selected in line order.
     *
     * @return void
     */
    public function testExcessInstancesBeyondAcceptedCountAreNew(): void
    {
        $baseline = new BaselineData('gruff-baseline.json', [
            $this->acceptedGroup(count: 2),
        ]);
        $lineTen = 10;
        $lineTwenty = 20;
        $lineThirty = 30;
        // Deliberately unsorted input: selection must order by line before spending the accepted count.
        $findings = [$this->finding(line: $lineThirty), $this->finding(line: $lineTen), $this->finding(line: $lineTwenty)];

        $application = (new BaselineFilter())->apply($baseline, $findings, false);

        self::assertCount(1, $application['new']);
        self::assertSame($lineThirty, $application['new'][0]->line);
        self::assertCount(2, $application['unchanged']);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Verify a shortfall of live instances reports the group absent with its resolved count.
     *
     * @return void
     */
    public function testShortfallReportsAbsentGroupWithResolvedCount(): void
    {
        $acceptedCount = 3;
        $liveCount     = 1;
        $resolvedCount = $acceptedCount - $liveCount;
        $baseline      = new BaselineData('gruff-baseline.json', [
            $this->acceptedGroup(count: $acceptedCount),
        ]);

        $application = (new BaselineFilter())->apply($baseline, [$this->finding(line: 4)], false);

        $report = $application['report'];
        self::assertSame([], $application['new']);
        self::assertCount($liveCount, $application['unchanged']);
        self::assertSame($resolvedCount, $report->absentCount);
        self::assertCount(1, $report->staleEntries);
        self::assertSame($resolvedCount, $report->staleEntries[0]->count);
        self::assertSame('full-project', $report->staleEvaluation);
    }

    /**
     * Verify mixed groups partition independently by their own counts.
     *
     * @return void
     */
    public function testMixedGroupsPartitionIndependently(): void
    {
        $baseline = new BaselineData('gruff-baseline.json', [
            $this->acceptedGroup(count: 1),
            $this->acceptedGroup(ruleId: 'docs.missing-public-phpdoc', message: 'Method render needs a description.', count: 2),
        ]);
        $findings = [
            // Group one: two live vs one accepted, so one is new.
            $this->finding(line: 3),
            $this->finding(line: 8),
            // Group two: one live vs two accepted, so one instance resolved.
            $this->finding(ruleId: 'docs.missing-public-phpdoc', message: 'Method render needs a description.', line: 15),
        ];

        $application = (new BaselineFilter())->apply($baseline, $findings, false);

        // One instance matched in each group: min(1, 2) for group one plus min(2, 1) for group two.
        $expectedUnchangedCount = 2;

        $report = $application['report'];
        self::assertSame(1, $report->newCount);
        self::assertSame($expectedUnchangedCount, $report->unchangedCount);
        self::assertSame(1, $report->absentCount);
        self::assertCount(1, $report->staleEntries);
        self::assertSame('docs.missing-public-phpdoc', $report->staleEntries[0]->ruleId);
    }

    /**
     * Verify diff scope skips absent evaluation entirely.
     *
     * @return void
     */
    public function testDiffScopeSkipsAbsentEvaluation(): void
    {
        $baseline = new BaselineData('gruff-baseline.json', [
            $this->acceptedGroup(count: 2),
            $this->acceptedGroup(ruleId: 'docs.missing-public-phpdoc', message: 'Method render needs a description.', count: 1),
        ]);

        $application = (new BaselineFilter())->apply($baseline, [$this->finding(line: 7)], true);

        $report = $application['report'];
        self::assertSame('not-evaluated-diff-scope', $report->staleEvaluation);
        self::assertSame([], $report->staleEntries);
        self::assertSame(0, $report->absentCount);
        self::assertSame(1, $report->unchangedCount);
    }

    /**
     * Verify duplicate findings sharing one group key consume one shared count budget.
     *
     * @return void
     */
    public function testDuplicateFindingsShareOneGroupBudget(): void
    {
        $baseline = new BaselineData('gruff-baseline.json', [
            $this->acceptedGroup(count: 2),
        ]);
        $lineFive = 5;
        $lineNine = 9;

        $application = (new BaselineFilter())->apply(
            $baseline,
            [$this->finding(line: $lineFive), $this->finding(line: $lineNine)],
            false,
        );

        self::assertSame([], $application['new']);
        self::assertCount(2, $application['unchanged']);
        self::assertSame(0, $application['report']->absentCount);
    }

    /**
     * Build a baseline group entry sharing the default finding identity.
     *
     * @param string $ruleId - Rule identifier for the group.
     * @param string $message - Finding message for the group.
     * @param int    $count - Accepted instance count.
     *
     * @return BaselineEntry - group row keyed to match findings built by finding() with the same identity fields
     */
    private function acceptedGroup(
        string $ruleId = 'security.dangerous-function-call',
        string $message = 'Dangerous PHP execution pattern detected: eval.',
        int    $count = 1,
    ): BaselineEntry {
        return new BaselineEntry(
            filePath: 'src/App.php',
            ruleId:   $ruleId,
            message:  $message,
            count:    $count,
        );
    }

    /**
     * Build a live finding for group-matching assertions.
     *
     * @param string   $ruleId - Rule identifier emitted for the finding.
     * @param string   $message - Finding message; part of the group key.
     * @param int|null $line - Source line; varied to prove lines never affect matching.
     *
     * @return Finding - one warning finding on src/App.php with the given identity and location
     */
    private function finding(
        string $ruleId = 'security.dangerous-function-call',
        string $message = 'Dangerous PHP execution pattern detected: eval.',
        ?int   $line = 1,
    ): Finding {
        return new Finding(
            ruleId:     $ruleId,
            message:    $message,
            filePath:   'src/App.php',
            line:       $line,
            severity:   Severity::Warning,
            pillar:     Pillar::Security,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }
}
