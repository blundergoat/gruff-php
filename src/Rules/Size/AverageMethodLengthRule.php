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
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Flags a class, trait, or enum whose methods are long on average, catching a type that is bloated
 * across the board even when no single method is big enough to trip the method-length rule.
 *
 * Runs per file over every class-like scope, averaging logical lines - distinct start lines of non-`Nop`
 * statements - across all its methods, and reporting an average past the threshold (default error above
 * 50). See ADR-012 for the container/content metric split.
 */
final readonly class AverageMethodLengthRule implements RuleInterface
{
    /**
     * Stable rule identifier for average method length findings.
     */
    public const ID = 'size.average-method-length';

    /**
     * Describes the average-method-length rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Error at 50 average logical lines: the catalogue default callers inherit unless .gruff-php.yaml overrides it.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Average method length',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            description:       'Average method length (logical lines: statements only, averaged across the type\'s methods)',
            severityThreshold: new SeverityThreshold(50, Severity::Error),
        );
    }

    /**
     * Reports each class-like scope whose average method length runs over the configured budget.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per class-like scope whose average trips a threshold; empty when none do.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $classLikes = NodeIndex::nodesOfAny($analysisUnit, [Class_::class, Trait_::class, Enum_::class]);

        $findings = [];

        // Measure each class, trait, and enum in the file.
        foreach ($classLikes as $classLike) {
            /** @var Class_|Trait_|Enum_ $classLike Finder predicate restricts results to class-like declarations. */
            $methods = array_filter(
                $classLike->stmts,
                static fn (Node $stmt): bool => $stmt instanceof ClassMethod,
            );

            // A type with no methods has no average to measure.
            if ($methods === []) {
                continue;
            }

            $totalLines = 0;

            // Sum the logical length of every method.
            foreach ($methods as $method) {
                $totalLines += NodeIndex::logicalStatementLineCount($method);
            }

            $average        = $totalLines / count($methods);
            $thresholdMatch = $settings->highValueThresholdMatch($average);

            // An average within budget is fine, so skip it.
            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = $this->resolveSymbol($classLike);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has an average method length of %.1f lines across %d methods, above the %s threshold of %s.',
                    $symbol,
                    $average,
                    count($methods),
                    $thresholdMatch->severity->value,
                    $this->formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $classLike->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $classLike->getEndLine() > 0 ? $classLike->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Refactor long methods into smaller units to reduce average length.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'averageLength' => round($average, 1),
                    'methodCount' => count($methods),
                    'totalLines' => $totalLines,
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
