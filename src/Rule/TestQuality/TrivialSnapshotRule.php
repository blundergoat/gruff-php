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

/**
 * Detects snapshot assertions that only lock down trivial values.
 */
final readonly class TrivialSnapshotRule implements RuleInterface
{
    /**
     * Stable rule identifier for trivial snapshot findings.
     */
    public const ID = 'test-quality.trivial-snapshot';

    /**
     * Describe the trivial snapshot rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Trivial snapshot',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
            defaultThresholds: ['maxLiteralLength' => 5],
        );
    }

    /**
     * Find snapshot assertions that lack supporting behavioral assertions.
     *
     * @return list<Finding> Findings for trivial snapshot tests.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $maxLength = (int) $context->settingsFor($definition)->numericThreshold('maxLiteralLength');
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);
                if ($name === null || !str_contains($name, 'snapshot')) {
                    continue;
                }

                $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call));
                if (!is_string($literal) && $call instanceof \PhpParser\Node\Expr\MethodCall) {
                    $literal = TestQualityNodeHelper::literalValue(TestQualityNodeHelper::pestExpectationValue($call));
                }

                if (!is_string($literal) || strlen($literal) > $maxLength) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf('%s snapshots a tiny literal; a direct assertion is clearer.', $scope->symbol),
                    filePath: $unit->file->displayPath,
                    line: $call->getStartLine(),
                    severity: Severity::Advisory,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::Medium,
                    symbol: $scope->symbol,
                    remediation: 'Use snapshots for rich structured output, not short scalar values.',
                    metadata: ['length' => strlen($literal)],
                );
            }
        }

        return $findings;
    }
}
