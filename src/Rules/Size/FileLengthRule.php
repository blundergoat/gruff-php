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
 * Reports source files whose substantive line count exceeds the configured budget.
 * Counts non-blank source lines after masking available comment tokens and parsed attribute groups.
 *
 * The default threshold is 1000 lines; findings below 1.5 times the active limit are advisory.
 * See ADR-012 and FAMILY-CONTRACT.md section 12 for the counting and banding contracts.
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
            description:       'File length (substantive lines: blank and comment-only lines are free)',
            severityThreshold: new SeverityThreshold(1000, Severity::Error),
        );
    }

    /**
     * Reports the file when its substantive line count runs over the configured budget.
     *
     * @param AnalysisUnit $analysisUnit - Source, tokens and AST used to count substantive lines.
     * @param RuleContext $ruleContext - Supplies the effective thresholds for this rule.
     *
     * @return list<Finding> - Empty when the file is within budget; otherwise the single exceeded-length finding.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition     = $this->definition();
        $settings       = $ruleContext->settingsFor($definition);
        $lineCount      = SubstantiveLineCounter::countAll($analysisUnit);
        $thresholdMatch = $settings->highValueThresholdMatch($lineCount);


        if ($thresholdMatch === null) {
            return [];
        }

        $band = LimitBand::of($lineCount, $settings->lowestHighValueThreshold());

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
                severity:         LimitBand::severity($band, $thresholdMatch->severity),
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $analysisUnit->lineCount(),
                remediation:      LimitBand::advice($band, LimitBand::LOWER_FILE, LimitBand::SPLIT_FILE),
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'lines' => $lineCount,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                    LimitBand::KEY => $band,
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

        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
