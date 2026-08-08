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
 * Flags a class, trait, or enum whose substantive length runs past the configured budget - the container
 * measure that tells the user a single type has grown too large to sit comfortably in one file.
 *
 * Runs per file over every class-like scope, counting substantive lines between its declaration and
 * closing brace - blank and comment-only lines are free (family ratification, 2026-08-05) - against
 * the threshold (default error above 1000), so required documentation can never push a type over the
 * size bar. Class-length is a container measure, not a density measure. See ADR-012.
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
        // Error at 1000 substantive lines: the catalogue default callers inherit unless .gruff-php.yaml overrides it.
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
     * Reports each class-like scope whose substantive length runs over the configured budget.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per oversized class-like scope; empty when every type is within budget.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        // Measure each class, trait, and enum in the file.
        foreach ($nodes as $node) {
            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

            // Skip a synthetic node with no line span to measure.
            if ($startLine < 0 || $endLine < 0) {
                continue;
            }

            $length         = SubstantiveLineCounter::countRange($analysisUnit, $startLine, $endLine);
            $thresholdMatch = $settings->highValueThresholdMatch($length);

            // A scope within budget is fine, so skip it.
            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($node);

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

        return $findings;
    }

    /**
     * Builds a display name for a class-like node, synthesising a label when it is unnamed.
     *
     * @param Node $node - Class-like node (Class_, Trait_, or Enum_) whose declared name labels the finding.
     *
     * @return string - Class-like display symbol.
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
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Threshold value to render; whole floats are shown without a trailing decimal.
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
