<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Size;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;

final readonly class FileLengthRule implements RuleInterface
{
    public const ID = 'size.file-length';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'File length',
            pillar: Pillar::Size,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
            defaultThresholds: [
                'warning' => 400,
                'error' => 800,
            ],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings = $context->settingsFor($definition);
        $warningThreshold = $settings->numericThreshold('warning');
        $errorThreshold = $settings->numericThreshold('error');
        $lineCount = $unit->lineCount();

        if ($lineCount <= $warningThreshold) {
            return [];
        }

        $severity = $lineCount > $errorThreshold ? Severity::Error : Severity::Warning;
        $threshold = $severity === Severity::Error ? $errorThreshold : $warningThreshold;

        return [
            new Finding(
                ruleId: $definition->id,
                message: sprintf(
                    'File has %d lines, above the %s threshold of %s.',
                    $lineCount,
                    $severity->value,
                    $this->formatNumber($threshold),
                ),
                filePath: $unit->file->displayPath,
                line: 1,
                severity: $severity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $lineCount,
                remediation: 'Split oversized files or move responsibilities into smaller units.',
                secondaryPillars: $definition->secondaryPillars,
                metadata: [
                    'lines' => $lineCount,
                    'threshold' => $threshold,
                    'thresholdType' => $severity->value,
                ],
            ),
        ];
    }

    private function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
