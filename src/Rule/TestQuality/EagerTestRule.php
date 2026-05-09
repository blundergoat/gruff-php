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

final readonly class EagerTestRule implements RuleInterface
{
    public const ID = 'test-quality.eager-test';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Eager test',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
            defaultThresholds: ['minAssertions' => 3],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $minAssertions = (int) $context->settingsFor($definition)->numericThreshold('minAssertions');
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $assertionCount = count(TestQualityNodeHelper::assertionCalls($scope));
            $sutCalls = $this->distinctSutCalls($scope);

            if ($assertionCount < $minAssertions || count($sutCalls) < 2) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('%s asserts %d times across multiple apparent SUT calls.', $scope->symbol, $assertionCount),
                filePath: $unit->file->displayPath,
                line: $scope->line,
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::Low,
                symbol: $scope->symbol,
                remediation: 'Split unrelated behaviors into focused tests when the assertions cover different responsibilities.',
                metadata: ['assertions' => $assertionCount, 'sutCalls' => array_values($sutCalls)],
            );
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function distinctSutCalls(TestQualityScope $scope): array
    {
        $calls = [];

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            $name = TestQualityNodeHelper::callName($call);
            if ($name === null || TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                continue;
            }

            $calls[$name] = $name;
        }

        return $calls;
    }
}
