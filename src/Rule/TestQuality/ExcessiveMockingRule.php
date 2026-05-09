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

final readonly class ExcessiveMockingRule implements RuleInterface
{
    public const ID = 'test-quality.excessive-mocking';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Excessive mocking',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
            defaultThresholds: ['maxMocks' => 3],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $maxMocks = (int) $context->settingsFor($definition)->numericThreshold('maxMocks');
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            $mockCount = 0;
            foreach (TestQualityNodeHelper::calls($scope) as $call) {
                if (TestQualityNodeHelper::isMockCreationCall($call)) {
                    $mockCount++;
                }
            }

            if ($mockCount <= $maxMocks) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('%s creates %d mocks; more than %d usually signals over-specified collaborators.', $scope->symbol, $mockCount, $maxMocks),
                filePath: $unit->file->displayPath,
                line: $scope->line,
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::Medium,
                symbol: $scope->symbol,
                remediation: 'Prefer fewer collaborators, a higher-level test, or a purpose-built fake.',
                metadata: ['mockCount' => $mockCount, 'maxMocks' => $maxMocks],
            );
        }

        return $findings;
    }
}
