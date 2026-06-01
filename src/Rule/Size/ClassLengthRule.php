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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Detects class-like declarations that exceed the configured line threshold.
 *
 * Measures raw lines between the class declaration and its closing brace,
 * inclusive - whitespace and comments count. Class-length is a container
 * measure, not a density measure. See ADR-012.
 */
final readonly class ClassLengthRule implements RuleInterface
{
    /**
     * Stable rule identifier for class length findings.
     */
    public const ID = 'size.class-length';

    /**
     * Describe the class-length rule.
     *
     * @return RuleDefinition Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Error at 1000 physical lines: the catalogue default callers inherit unless .gruff-php.yaml overrides it.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Class length',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(1000, Severity::Error),
        );
    }

    /**
     * Find class-like scopes whose physical line length exceeds thresholds.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for oversized classes, traits, or enums.
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

            if ($startLine < 0 || $endLine < 0) {
                continue;
            }

            $length         = $endLine - $startLine + 1;
            $thresholdMatch = $settings->highValueThresholdMatch($length);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s is %d lines, above the %s threshold of %s.',
                    $symbol,
                    $length,
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $startLine,
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $endLine,
                symbol:           $symbol,
                remediation:      'Split large classes into smaller, focused units.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'lines' => $length,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        // One finding per class-like scope that exceeded its threshold; empty when every type stayed within budget.
        return $findings;
    }

    /**
     * Build a display symbol for a class-like node.
     *
     * @param Node $node Class-like node (Class_, Trait_, or Enum_) whose declared name labels the finding.
     *
     * @return string Class-like display symbol.
     */
    private function resolveSymbol(Node $node): string
    {
        if ($node instanceof Class_) {
            // Anonymous classes carry no name node, so synthesise a label keyed to the declaration line.
            return $node->name?->toString() ?? sprintf('class@anonymous:%d', $node->getStartLine());
        }

        if ($node instanceof Trait_) {
            // Traits are always named in valid PHP; the fallback only guards against an unparsed name node.
            return $node->name?->toString() ?? sprintf('trait@%d', $node->getStartLine());
        }

        if ($node instanceof Enum_) {
            // Enums are always named in valid PHP; the fallback only guards against an unparsed name node.
            return $node->name?->toString() ?? sprintf('enum@%d', $node->getStartLine());
        }

        // Unreachable for the finder's class-like inputs; a line-keyed label keeps findings traceable if it ever fires.
        return sprintf('unknown@%d', $node->getStartLine());
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @param int|float $number Threshold value to render; whole floats are shown without a trailing decimal.
     *
     * @return string Human-readable threshold value.
     */
    private function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            // Genuine fractional thresholds keep their decimals so the message stays precise.
            return (string) $number;
        }

        // Whole values render without a ".0" so an integer threshold reads as a plain integer.
        return (string) (int) $number;
    }
}
