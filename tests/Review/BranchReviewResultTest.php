<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Review;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Review\BranchReviewResult;
use PHPUnit\Framework\TestCase;

/**
 * Covers per-rule delta bucketing on branch-review results: rule-grouped counts,
 * net direction sort, zero-net omission, deterministic tie-breaking by rule id.
 */
final class BranchReviewResultTest extends TestCase
{
    /**
     * Verify perRuleDelta groups introduced and removed findings by rule id and ranks by net.
     *
     * @return void
     */
    public function testPerRuleDeltaGroupsByRuleAndSortsByNet(): void
    {
        $result = new BranchReviewResult(
            base:          'main',
            isChangedOnly: false,
            introduced:    [
                $this->finding('docs.missing-public-phpdoc'),
                $this->finding('docs.missing-public-phpdoc'),
                $this->finding('modernisation.phpdoc-mixed-overuse'),
            ],
            removed:       [
                $this->finding('size.method-length'),
                $this->finding('size.method-length'),
                $this->finding('size.method-length'),
                $this->finding('docs.missing-public-phpdoc'),
            ],
            unchanged:     [],
            deltaScore:    null,
        );

        $delta = $result->perRuleDelta();

        self::assertSame(
            [
                ['ruleId' => 'size.method-length', 'introduced' => 0, 'removed' => 3, 'net' => -3],
                ['ruleId' => 'docs.missing-public-phpdoc', 'introduced' => 2, 'removed' => 1, 'net' => 1],
                ['ruleId' => 'modernisation.phpdoc-mixed-overuse', 'introduced' => 1, 'removed' => 0, 'net' => 1],
            ],
            $delta,
        );
    }

    /**
     * Verify zero-net rules are omitted from the delta output.
     *
     * @return void
     */
    public function testPerRuleDeltaOmitsZeroNetRules(): void
    {
        $result = new BranchReviewResult(
            base:          'main',
            isChangedOnly: false,
            introduced:    [$this->finding('docs.missing-public-phpdoc')],
            removed:       [$this->finding('docs.missing-public-phpdoc')],
            unchanged:     [],
            deltaScore:    null,
        );

        self::assertSame([], $result->perRuleDelta());
    }

    /**
     * Verify ties at the same net delta order by rule id ascending.
     *
     * @return void
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
     * @param string $ruleId - rule id to stamp on the fixture; the only field that varies across
     *   cases, since perRuleDelta() buckets and sorts purely on this value.
     * @return Finding - finding whose every other field is held constant so delta grouping and
     *   tie-breaking depend on $ruleId alone.
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
