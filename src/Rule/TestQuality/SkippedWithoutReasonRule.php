<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

final readonly class SkippedWithoutReasonRule implements RuleInterface
{
    public const ID = 'test-quality.skipped-without-reason';

    /**
     * Describe the skipped test without reason rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Skipped test without reason',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Find skipped or incomplete tests without an explanatory reason.
     *
     * @return list<Finding> Findings for unexplained skipped tests.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                if (TestQualityNodeHelper::callName($call) !== 'marktestskipped') {
                    continue;
                }

                $reason = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call));
                if (is_string($reason) && trim($reason) !== '') {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf('%s skips without an explanatory reason.', $scope->symbol),
                    filePath: $unit->file->displayPath,
                    line: $call->getStartLine(),
                    severity: Severity::Warning,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::High,
                    symbol: $scope->symbol,
                    remediation: 'Include the condition or ticket that explains why this test is skipped.',
                );
            }
        }

        return $findings;
    }
}
