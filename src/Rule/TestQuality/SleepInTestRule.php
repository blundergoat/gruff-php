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

final readonly class SleepInTestRule implements RuleInterface
{
    public const ID = 'test-quality.sleep-in-test';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Sleep in test',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                $name = TestQualityNodeHelper::callName($call);
                if ($name === null || !in_array($name, ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'], true)) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf('%s sleeps during the test run, which is a flakiness and latency smell.', $scope->symbol),
                    filePath: $unit->file->displayPath,
                    line: $call->getStartLine(),
                    severity: Severity::Warning,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::High,
                    symbol: $scope->symbol,
                    remediation: 'Replace sleeps with explicit clocks, retries with deadlines, or observable synchronization points.',
                    metadata: ['function' => $name],
                );
            }
        }

        return $findings;
    }
}
