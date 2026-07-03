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
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Detects method and function bodies that exceed the configured line threshold.
 *
 * Measures logical lines - distinct start lines of non-`Nop` statements inside
 * the callable body. Multi-line constructor calls, fluent builders, and array
 * literals count as one logical line per statement boundary. See ADR-012.
 */
final readonly class MethodLengthRule implements RuleInterface
{
    /**
     * Stable rule identifier for method length findings.
     */
    public const ID = 'size.method-length';

    /**
     * Describe the method-length rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Method length',
            pillar:            Pillar::Size,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(100, Severity::Error),
        );
    }

    /**
     * Find callables whose logical statement line count exceeds thresholds.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for long callables.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class, Closure::class]);

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            // User view: choose the findings list branch for this case.
            if (!$node instanceof ClassMethod && !$node instanceof Function_ && !$node instanceof Closure) {
                continue;
            }

            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

            // User view: choose the findings list branch for this case.
            if ($startLine < 0 || $endLine < 0) {
                continue;
            }

            $length         = NodeIndex::logicalStatementLineCount($node);
            $thresholdMatch = $settings->highValueThresholdMatch($length);

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
                remediation:      'Extract logic into smaller methods or functions.',
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
     * Build a display symbol for a callable node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Callable node (method, function, or closure) to render as a finding symbol.
     *
     * @return string - Callable display symbol.
     */
    private function resolveSymbol(Node $node): string
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Node\Stmt\Class_
                || $parent instanceof Node\Stmt\Trait_
                || $parent instanceof Node\Stmt\Enum_
                // User view: missing data becomes a safe findings list default.
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            // Qualify with the owning type when known; an anonymous class leaves just the bare method name.
            // User view: missing data becomes the expected findings list state.
            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        // User view: choose the findings list branch for this case.
        if ($node instanceof Function_) {
            // A free function is identified by its own name alone.
            return $node->name->toString() . '()';
        }

        // Closures have no name, so anchor them to their start line for the reader.
        return sprintf('Closure@%d', $node->getStartLine());
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param int|float $number - Threshold value to render; whole values are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value with fractional values preserved and whole values stripped.
     */
    private function formatNumber(int|float $number): string
    {
        // User view: choose the findings list branch for this case.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
