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
 * Flags a method, function, or closure whose logical body runs long, since a callable that spans many
 * statements is usually doing more than one job and is hard to read top to bottom.
 *
 * Runs per file over every callable, counting logical lines - distinct start lines of non-`Nop`
 * statements inside the body - against the threshold (default error above 100). Multi-line constructor
 * calls, fluent builders, and array literals count as one logical line per statement boundary. See ADR-012.
 */
final readonly class MethodLengthRule implements RuleInterface
{
    /**
     * Stable rule identifier for method length findings.
     */
    public const ID = 'size.method-length';

    /**
     * Describes the method-length rule for the registry and reports.
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
            description:       'Method length (logical lines: statements, not blank or comment lines)',
            severityThreshold: new SeverityThreshold(100, Severity::Error),
        );
    }

    /**
     * Reports each callable whose logical line count runs over the configured budget.
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

        // Measure each method, function, and closure in the file.
        foreach ($nodes as $node) {
            // Only real callables are measured.
            if (!$node instanceof ClassMethod && !$node instanceof Function_ && !$node instanceof Closure) {
                continue;
            }

            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

            // Skip a synthetic node with no line span.
            if ($startLine < 0 || $endLine < 0) {
                continue;
            }

            $length         = NodeIndex::logicalStatementLineCount($node);
            $thresholdMatch = $settings->highValueThresholdMatch($length);

            // A callable within budget is fine, so skip it.
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
     * Builds a display symbol for a callable node (Class::method(), function(), or Closure@line).
     *
     * @param Node $node - Callable node (method, function, or closure) to render as a finding symbol.
     *
     * @return string - Callable display symbol.
     */
    private function resolveSymbol(Node $node): string
    {
        // A method is qualified by its owning type name.
        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Node\Stmt\Class_
                || $parent instanceof Node\Stmt\Trait_
                || $parent instanceof Node\Stmt\Enum_
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            // Qualify with the owning type when known; an anonymous class leaves just the bare method name.
            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        if ($node instanceof Function_) {
            // A free function is identified by its own name alone.
            return $node->name->toString() . '()';
        }

        // Closures have no name, so anchor them to their start line for the reader.
        return sprintf('Closure@%d', $node->getStartLine());
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
