<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Review\BranchReviewResult;
use PHPUnit\Framework\TestCase;

/**
 * Covers per-rule delta counts for introduced and removed findings.
 * Checks ascending net changes and rule-ID tie-breaking.
 * Checks that equal introduced and removed counts omit the rule from the result.
 */
final class BranchReviewResultTest extends TestCase
{
    /**
     * Verify perRuleDelta groups introduced and removed findings by rule id and ranks by net.
     *
     * @return void - Asserts exact per-rule counts and order for net deltas -3, 1, and 1.
     */
    public function testPerRuleDeltaGroupsByRuleAndSortsByNet(): void
    {
        $result = new BranchReviewResult(
            base:          'main',
            isChangedOnly: false,
            introduced:    [
                $this->finding('docs.missing-phpdoc'),
                $this->finding('docs.missing-phpdoc'),
                $this->finding('modernisation.public-property'),
            ],
            removed:       [
                $this->finding('size.method-length'),
                $this->finding('size.method-length'),
                $this->finding('size.method-length'),
                $this->finding('docs.missing-phpdoc'),
            ],
            unchanged:     [],
            deltaScore:    null,
        );

        $delta = $result->perRuleDelta();

        self::assertSame(
            [
                ['ruleId' => 'size.method-length', 'introduced' => 0, 'removed' => 3, 'net' => -3],
                ['ruleId' => 'docs.missing-phpdoc', 'introduced' => 2, 'removed' => 1, 'net' => 1],
                ['ruleId' => 'modernisation.public-property', 'introduced' => 1, 'removed' => 0, 'net' => 1],
            ],
            $delta,
        );
    }

    /**
     * Verify zero-net rules are omitted from the delta output.
     *
     * @return void - Asserts an empty result when a rule's introduced and removed counts are equal.
     */
    public function testPerRuleDeltaOmitsZeroNetRules(): void
    {
        $result = new BranchReviewResult(
            base:          'main',
            isChangedOnly: false,
            introduced:    [$this->finding('docs.missing-phpdoc')],
            removed:       [$this->finding('docs.missing-phpdoc')],
            unchanged:     [],
            deltaScore:    null,
        );

        self::assertSame([], $result->perRuleDelta());
    }

    /**
     * Verify ties at the same net delta order by rule id ascending.
     *
     * @return void - Asserts alphabetical rule-ID order for three equal positive net deltas.
     */
    public function testPerRuleDeltaSortsTiedNetByRuleId(): void
    {
        $result = new BranchReviewResult(
            base:          'main',
            isChangedOnly: false,
            introduced:    [
                $this->finding('zeta.rule'),
                $this->finding('alpha.rule'),
                $this->finding('beta.rule'),
            ],
            removed:       [],
            unchanged:     [],
            deltaScore:    null,
        );

        $ruleIds = array_map(static fn(array $deltaRow): string => $deltaRow['ruleId'], $result->perRuleDelta());
        self::assertSame(['alpha.rule', 'beta.rule', 'zeta.rule'], $ruleIds);
    }

    /**
     * Build a finding fixture for delta tests.
     *
     * @param string $ruleId - Rule ID used to group this fixture in perRuleDelta().
     *
     * @return Finding - Advisory documentation finding with all fields except ruleId fixed.
     */
    private function finding(string $ruleId): Finding
    {
        // Hold all non-rule fields fixed so each call differs only by ruleId, isolating the bucketing logic.
        return new Finding(
            ruleId:     $ruleId,
            message:    'Example finding.',
            filePath:   'src/Example.php',
            line:       1,
            severity:   Severity::Advisory,
            pillar:     Pillar::Documentation,
            tier:       RuleTier::V01,
            confidence: Confidence::High,
            symbol:     'Example::doWork()',
        );
    }
}
