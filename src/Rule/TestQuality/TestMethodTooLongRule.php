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
 * Detects oversized test methods that hide intent behind setup and assertions.
 */
final readonly class TestMethodTooLongRule implements RuleInterface
{
    /**
     * Stable rule identifier for long test method findings.
     */
    public const ID = 'test-quality.test-method-too-long';

    /**
     * Describe the test method too long rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Test method too long',
            pillar: Pillar::TestQuality,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
            defaultThresholds: ['maxMeaningfulLines' => 25],
        );
    }

    /**
     * Find test methods whose line count exceeds configured thresholds.
     *
     * @return list<Finding> Findings for oversized test methods.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $threshold = (int) $context->settingsFor($definition)->numericThreshold('maxMeaningfulLines');
        $sourceLines = explode("\n", $unit->source);
        $findings = [];

        foreach (TestQualityNodeHelper::testScopes($unit) as $scope) {
            if ($scope->endLine === null) {
                continue;
            }

            $count = $this->countMeaningfulLines($sourceLines, $scope->line, $scope->endLine);

            if ($count <= $threshold) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf(
                    '%s spans %d meaningful lines, above the threshold of %d.',
                    $scope->symbol,
                    $count,
                    $threshold,
                ),
                filePath: $unit->file->displayPath,
                line: $scope->line,
                severity: Severity::Advisory,
                pillar: Pillar::TestQuality,
                tier: RuleTier::V01,
                confidence: Confidence::High,
                endLine: $scope->endLine,
                symbol: $scope->symbol,
                remediation: 'Split the scenario into focused tests, extract setup helpers, or move shared arrangement into setUp().',
                metadata: ['meaningfulLines' => $count, 'threshold' => $threshold],
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $sourceLines
     * @return int Count of non-empty, non-comment lines in the method range.
     */
    private function countMeaningfulLines(array $sourceLines, int $startLine, int $endLine): int
    {
        $count = 0;

        for ($lineNumber = $startLine; $lineNumber <= $endLine; $lineNumber++) {
            $index = $lineNumber - 1;
            if (!isset($sourceLines[$index])) {
                continue;
            }

            $line = trim($sourceLines[$index]);

            if ($line === '') {
                continue;
            }

            if (in_array($line, ['{', '}', '},', ');', '];', '),', ',', ');'], true)) {
                continue;
            }

            if (str_starts_with($line, '//') || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '*') || str_starts_with($line, '/*') || str_starts_with($line, '*/')) {
                continue;
            }

            $count++;
        }

        return $count;
    }
}
