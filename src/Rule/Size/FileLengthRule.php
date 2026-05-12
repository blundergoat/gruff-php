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

/**
 * Detects source files that exceed the configured line threshold.
 */
final readonly class FileLengthRule implements RuleInterface
{
    /**
     * Stable rule identifier for file length findings.
     */
    public const ID = 'size.file-length';

    /**
     * Describe the file length rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'File length',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Warning,
            confidence:        Confidence::High,
            defaultThresholds: [
                'warning' => 400,
                'error' => 800,
            ],
        );
    }

    /**
     * Find files whose line count exceeds configured thresholds.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for oversized files.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition     = $this->definition();
        $settings       = $context->settingsFor($definition);
        $lineCount      = $unit->lineCount();
        $thresholdMatch = $settings->highValueThresholdMatch($lineCount);

        if ($thresholdMatch === null) {
            return [];
        }

        return [
            new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    'File has %d lines, above the %s threshold of %s.',
                    $lineCount,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $unit->file->displayPath,
                line:             1,
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $lineCount,
                remediation:      'Split oversized files or move responsibilities into smaller units.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'lines' => $lineCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            ),
        ];
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @return string Human-readable threshold value.
     */
    private function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
