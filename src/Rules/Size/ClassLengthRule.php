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
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Reports classes, traits and enums whose substantive line count exceeds the configured budget.
 * Counts each declaration span after masking comment tokens and parsed attribute groups, ignoring blank lines.
 *
 * The default threshold is 1000 lines; findings below 1.5 times the active limit are advisory.
 * See ADR-012 and FAMILY-CONTRACT.md section 12 for the counting and banding contracts.
 */
final readonly class ClassLengthRule implements RuleInterface
{
    /**
     * Stable rule identifier for class length findings.
     */
    public const ID = 'size.class-length';

    /**
     * Describes the class-length rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {

        return new RuleDefinition(
            id:                self::ID,
            name:              'Class length',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            description:       'Class length (substantive lines: blank and comment-only lines are free)',
            severityThreshold: new SeverityThreshold(1000, Severity::Error),
        );
    }

    /**
     * Reports classes, traits and enums whose substantive length exceeds the configured budget.
     *
     * @param AnalysisUnit $analysisUnit - Source and AST containing the declaration spans to measure.
     * @param RuleContext $ruleContext - Supplies the effective thresholds for this rule.
     *
     * @return list<Finding> - One finding per measured class, trait or enum above its limit; empty when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];


        foreach ($nodes as $node) {
            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

            // Skip a synthetic node with no line span to measure.
            if ($startLine < 0 || $endLine < 0) {
                continue;
            }

            $length         = SubstantiveLineCounter::countRange($analysisUnit, $startLine, $endLine);
            $thresholdMatch = $settings->highValueThresholdMatch($length);


            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

            $band = LimitBand::of($length, $settings->lowestHighValueThreshold());

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s is %d substantive lines, above the %s threshold of %s.',
                    $symbol,
                    $length,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $startLine,
                severity:         LimitBand::severity($band, $thresholdMatch->severity),
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $endLine,
                symbol:           $symbol,
                remediation:      LimitBand::advice($band, LimitBand::LOWER_CLASS, LimitBand::SPLIT_CLASS),
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'lines' => $length,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                    LimitBand::KEY => $band,
                ],
            );
        }

        return $findings;
    }

    /**
     * Builds a display name for a class-like node, synthesising a label when it is unnamed.
     *
     * @param Node $node - Class-like node (Class_, Trait_, or Enum_) whose declared name labels the finding.
     *
     * @return string - Declared name, or a line-keyed label when no name is available.
     */
    private function resolveSymbol(Node $node): string
    {
        if ($node instanceof Class_) {
            // Anonymous classes carry no name node, so synthesise a label keyed to the declaration line.
            return $node->name?->toString() ?? sprintf('class@anonymous:%d', $node->getStartLine());
        }

        if ($node instanceof Trait_) {
            // Traits are named in valid PHP; a missing name receives a line-based fallback.
            return $node->name?->toString() ?? sprintf('trait@%d', $node->getStartLine());
        }

        if ($node instanceof Enum_) {
            // Enums are named in valid PHP; a missing name receives a line-based fallback.
            return $node->name?->toString() ?? sprintf('enum@%d', $node->getStartLine());
        }

        // Unreachable for the finder's class-like inputs; a line-keyed label keeps findings traceable if it ever fires.
        return sprintf('unknown@%d', $node->getStartLine());
    }

    /**
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Threshold value to render; whole floats are shown without a trailing decimal.
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
