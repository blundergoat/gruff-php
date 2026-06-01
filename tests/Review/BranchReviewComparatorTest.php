<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Review\BranchReviewComparator;
use PHPUnit\Framework\TestCase;

/**
 * Covers counting of duplicate review finding identities rather than collapsing them.
 */
final class BranchReviewComparatorTest extends TestCase
{
    /**
     * Verify duplicate review identities are counted instead of collapsed.
     *
     * @return void
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
     * Build a finding fixture.
     *
     * @param string $message - finding message; pass the same value twice to mint two identical-identity findings.
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
}
