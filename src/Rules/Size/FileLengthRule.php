<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;

/**
 * Flags a source file whose substantive line count runs past the configured budget, the simplest signal
 * that a file has taken on too much and is getting hard to navigate.
 *
 * Runs once per file, comparing its substantive line count - blank lines and comment-only lines are
 * free (family ratification, 2026-08-05) - against the threshold (default error above 1000), so
 * required documentation can never push a file over the size bar. See ADR-012.
 */
final readonly class FileLengthRule implements RuleInterface
{
    /**
     * Stable rule identifier for file length findings.
     */
    public const ID = 'size.file-length';

    /**
     * Describes the file-length rule for the registry and reports.
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
     * Reports the file when its line count runs over the configured budget.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - Empty when the file is within budget; otherwise the single exceeded-length finding.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition     = $this->definition();
        $settings       = $ruleContext->settingsFor($definition);
        $lineCount      = SubstantiveLineCounter::countAll($analysisUnit);
        $thresholdMatch = $settings->highValueThresholdMatch($lineCount);

        // A file within budget produces no finding.
        if ($thresholdMatch === null) {
            return [];
        }

        return [
            new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    'File has %d substantive lines, above the %s threshold of %s.',
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
                endLine:          $analysisUnit->lineCount(),
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
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Threshold value to render; whole values are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value with fractional values preserved and whole values stripped.
     */
    private function formatNumber(int|float $number): string
    {
        // A genuine fraction keeps its decimals; a whole value is shown without them.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
