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

final readonly class TestLongerThanSutRule implements RuleInterface
{
    public const ID = 'test-quality.test-longer-than-sut';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Test longer than apparent SUT',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
            defaultThresholds: ['minTestLines' => 12],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $minTestLines = (int) $context->settingsFor($definition)->numericThreshold('minTestLines');
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $sutCalls = $this->sutCallCount($scope);
            if ($scope->lineCount() < $minTestLines || $sutCalls > 1 || TestQualityNodeHelper::assertionCalls($scope) === []) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('%s is long while exercising only %d apparent SUT call.', $scope->symbol, $sutCalls),
                filePath: $unit->file->displayPath,
                line: $scope->line,
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::Low,
                symbol: $scope->symbol,
                remediation: 'Review whether setup and assertions can be simplified or split; this static rule cannot measure the SUT directly.',
                metadata: ['testLines' => $scope->lineCount(), 'sutCalls' => $sutCalls],
            );
        }

        return $findings;
    }

    private function sutCallCount(TestQualityScope $scope): int
    {
        $count = 0;

        foreach (TestQualityNodeHelper::calls($scope) as $call) {
            if (TestQualityNodeHelper::isAssertionCall($call) || TestQualityNodeHelper::isMockCreationCall($call) || TestQualityNodeHelper::isMockVerificationCall($call)) {
                continue;
            }

            $name = TestQualityNodeHelper::callName($call);
            if ($name !== null && !in_array($name, ['sleep', 'usleep'], true)) {
                $count++;
            }
        }

        return $count;
    }
}
