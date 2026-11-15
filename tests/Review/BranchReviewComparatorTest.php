<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Review\BranchReviewComparator;
use GruffPhp\Results\Review\FindingReviewIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Covers duplicate counting and line-insensitive matching in branch reviews.
 * Checks introduced, removed, and unchanged counts for symbol-less findings.
 * Compares review keys, group keys, and stable identities under line and message changes.
 */
final class BranchReviewComparatorTest extends TestCase
{
    /**
     * Verify duplicate review identities are counted instead of collapsed.
     *
     * @return void - Asserts one unchanged finding, one introduced finding, and no removed findings for duplicate identities.
     */
    public function testDuplicateReviewIdentitiesAreCounted(): void
    {
        $base    = [$this->finding('Example finding.')];
        $current = [$this->finding('Example finding.'), $this->finding('Example finding.')];

        $result = (new BranchReviewComparator())->compare(
            current:       $current,
            base:          $base,
            baseRef:       'main',
            isChangedOnly: true,
            deltaScore:    null,
        );

        self::assertCount(1, $result->unchanged);
        self::assertCount(1, $result->introduced);
        self::assertCount(0, $result->removed);
    }

    /**
     * Verify a symbol-less finding that moved lines compares as unchanged.
     *
     * @return void - Asserts one unchanged finding and empty introduced and removed lists after a line shift.
     */
    public function testSymbolLessLineShiftComparesUnchanged(): void
    {
        $baseLine    = 10;
        $shiftedLine = 20;
        $base        = [$this->symbolLessFinding('Example finding.', $baseLine)];
        $current     = [$this->symbolLessFinding('Example finding.', $shiftedLine)];

        $result = (new BranchReviewComparator())->compare(
            current:       $current,
            base:          $base,
            baseRef:       'main',
            isChangedOnly: false,
            deltaScore:    null,
        );

        self::assertCount(1, $result->unchanged);
        self::assertCount(0, $result->introduced);
        self::assertCount(0, $result->removed);
    }

    /**
     * Verify an added duplicate symbol-less occurrence compares as introduced despite line drift.
     *
     * @return void - Asserts one unchanged finding, one introduced finding, and no removed findings after adding a duplicate.
     */
    public function testSymbolLessDuplicateOccurrenceComparesIntroduced(): void
    {
        $base    = [$this->symbolLessFinding('Example finding.', 10)];
        $current = [$this->symbolLessFinding('Example finding.', 12), $this->symbolLessFinding('Example finding.', 40)];

        $result = (new BranchReviewComparator())->compare(
            current:       $current,
            base:          $base,
            baseRef:       'main',
            isChangedOnly: false,
            deltaScore:    null,
        );

        self::assertCount(1, $result->unchanged);
        self::assertCount(1, $result->introduced);
        self::assertCount(0, $result->removed);
    }

    /**
     * Verify a removed duplicate symbol-less occurrence compares as removed despite line drift.
     *
     * @return void - Asserts one unchanged finding, no introduced findings, and one removed finding after removing a duplicate.
     */
    public function testSymbolLessRemovedOccurrenceComparesRemoved(): void
    {
        $base    = [$this->symbolLessFinding('Example finding.', 10), $this->symbolLessFinding('Example finding.', 30)];
        $current = [$this->symbolLessFinding('Example finding.', 14)];

        $result = (new BranchReviewComparator())->compare(
            current:       $current,
            base:          $base,
            baseRef:       'main',
            isChangedOnly: false,
            deltaScore:    null,
        );

        self::assertCount(1, $result->unchanged);
        self::assertCount(0, $result->introduced);
        self::assertCount(1, $result->removed);
    }

    /**
     * Verify line shifts preserve each identity and message changes split them.
     *
     * @return void - Asserts matching review/group keys and stable hashes for shifted findings, with distinct values after rewording.
     */
    public function testSymbolLessIdentityAgreesWithBaselineGroupKeyAndStableIdentity(): void
    {
        $reviewIdentity = new FindingReviewIdentity();
        $lineTen        = 10;
        $lineFifty      = 50;
        $shifted        = [$this->symbolLessFinding('Example finding.', $lineTen), $this->symbolLessFinding('Example finding.', $lineFifty)];
        $reworded       = [$this->symbolLessFinding('Example finding.', $lineTen), $this->symbolLessFinding('Different finding.', $lineTen)];


        self::assertSame($reviewIdentity->key($shifted[0]), $reviewIdentity->key($shifted[1]));
        self::assertSame(FindingReviewIdentity::groupKeyForFinding($shifted[0]), FindingReviewIdentity::groupKeyForFinding($shifted[1]));
        self::assertSame($shifted[0]->stableIdentity(), $shifted[1]->stableIdentity());

        self::assertSame(FindingReviewIdentity::groupKeyForFinding($shifted[0]), $reviewIdentity->key($shifted[0]));


        self::assertNotSame($reviewIdentity->key($reworded[0]), $reviewIdentity->key($reworded[1]));
        self::assertNotSame(FindingReviewIdentity::groupKeyForFinding($reworded[0]), FindingReviewIdentity::groupKeyForFinding($reworded[1]));
        self::assertNotSame($reworded[0]->stableIdentity(), $reworded[1]->stableIdentity());
    }

    /**
     * Build a finding fixture.
     *
     * @param string $message - finding message; pass the same value twice to create two identical-identity findings.
     *
     * @return Finding - an advisory documentation finding whose identity varies only by the given message
     */
    private function finding(string $message): Finding
    {
        return new Finding(
            ruleId:     'docs.example',
            message:    $message,
            filePath:   'src/Example.php',
            line:       12,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            symbol:     'Example::run()',
        );
    }

    /**
     * Build a symbol-less finding fixture at a given line.
     *
     * @param string $message - Finding message; part of the review identity.
     * @param int    $line - Source line varied to exercise line-shift comparisons.
     *
     * @return Finding - an advisory documentation finding with no symbol, so identity rests on file, rule, and message
     */
    private function symbolLessFinding(string $message, int $line): Finding
    {
        return new Finding(
            ruleId:     'docs.example',
            message:    $message,
            filePath:   'src/Example.php',
            line:       $line,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
        );
    }
}
