<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Size;

use GruffPhp\Config\SeverityThreshold;
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
 *
 * Measures raw source lines (newline count). File length is a container
 * measure aligned with how reviewers navigate files. See ADR-012.
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
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'File length',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(1000, Severity::Error),
        );
    }

    /**
     * Find files whose line count exceeds configured thresholds.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Empty when the file is within budget; otherwise the single exceeded-length finding.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition     = $this->definition();
        $settings       = $ruleContext->settingsFor($definition);
        $lineCount      = $analysisUnit->lineCount();
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
                filePath:         $analysisUnit->file->displayPath,
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
     * @param int|float $number - Threshold value to render; whole values are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value with fractional values preserved and whole values stripped.
     */
    private function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
