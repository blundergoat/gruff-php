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

final readonly class NoAssertionsRule implements RuleInterface
{
    public const ID = 'test-quality.no-assertions';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Test without assertions',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            if (TestQualityNodeHelper::assertionCalls($scope) !== []) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('%s has no detected PHPUnit or Pest assertions.', $scope->symbol),
                filePath: $unit->file->displayPath,
                line: $scope->line,
                severity: Severity::Warning,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::Medium,
                symbol: $scope->symbol,
                remediation: 'Add an assertion or expectation that proves observable behavior, or disable this rule for custom assertion wrappers.',
                metadata: ['framework' => $scope->isPest ? 'pest' : 'phpunit'],
            );
        }

        return $findings;
    }
}
