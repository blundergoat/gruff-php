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

final readonly class TrivialAssertionRule implements RuleInterface
{
    public const ID = 'test-quality.trivial-assertion';

    /**
     * Describe the trivial assertion rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Trivial assertion',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Find assertions that can pass without checking meaningful behavior.
     *
     * @return list<Finding> Findings for trivial assertions.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                if (!TestQualityNodeHelper::isTrivialAssertion($call)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf('%s contains an assertion that passes by construction.', $scope->symbol),
                    filePath: $unit->file->displayPath,
                    line: $call->getStartLine(),
                    severity: Severity::Warning,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::High,
                    symbol: $scope->symbol,
                    remediation: 'Assert behavior from the system under test instead of comparing known literals.',
                );
            }
        }

        return $findings;
    }
}
