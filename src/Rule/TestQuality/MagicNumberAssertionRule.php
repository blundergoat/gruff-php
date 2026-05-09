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

final readonly class MagicNumberAssertionRule implements RuleInterface
{
    public const ID = 'test-quality.magic-number-assertion';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Magic number assertion',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
                $number = TestQualityNodeHelper::isAssertionMagicNumber($call);
                if ($number === null) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: self::ID,
                    message: sprintf('%s asserts the unexplained literal %d.', $scope->symbol, $number),
                    filePath: $unit->file->displayPath,
                    line: $call->getStartLine(),
                    severity: Severity::Advisory,
                    pillar: Pillar::TestQuality,
                    tier: RuleTier::V01,
                    confidence: Confidence::Low,
                    symbol: $scope->symbol,
                    remediation: 'Name important constants or derive expected values from arranged data when that improves readability.',
                    metadata: ['number' => $number],
                );
            }
        }

        return $findings;
    }
}
