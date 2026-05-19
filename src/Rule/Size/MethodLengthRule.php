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
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeFinder;

/**
 * Detects method and function bodies that exceed the configured line threshold.
 *
 * Measures logical lines — distinct start lines of non-`Nop` statements inside
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
     * @return RuleDefinition Rule metadata and thresholds.
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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for long callables.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodeFinder = new NodeFinder();
        $nodes      = $nodeFinder->find($analysisUnit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_
                || $node instanceof Closure;
        });

        $findings = [];

        foreach ($nodes as $node) {
            if (!$node instanceof ClassMethod && !$node instanceof Function_ && !$node instanceof Closure) {
                continue;
            }

            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

            if ($startLine < 0 || $endLine < 0) {
                continue;
            }

            $length         = $this->logicalLineCount($node);
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
     * Count non-empty statement lines inside a callable body.
     *
     * @return int Logical statement line count.
     */
    private function logicalLineCount(ClassMethod|Function_|Closure $node): int
    {
        $nodeFinder = new NodeFinder();
        $lines      = [];

        foreach ($nodeFinder->find($node->stmts ?? [], static fn (Node $child): bool => $child instanceof Stmt && !$child instanceof Nop) as $statement) {
            $line = $statement->getStartLine();

            if ($line > 0) {
                $lines[$line] = true;
            }
        }

        return count($lines);
    }

    /**
     * Build a display symbol for a callable node.
     *
     * @return string Callable display symbol.
     */
    private function resolveSymbol(Node $node): string
    {
        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Node\Stmt\Class_
                || $parent instanceof Node\Stmt\Trait_
                || $parent instanceof Node\Stmt\Enum_
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        if ($node instanceof Function_) {
            return $node->name->toString() . '()';
        }

        return sprintf('Closure@%d', $node->getStartLine());
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @return string Human-readable threshold value.
     */
    private function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
