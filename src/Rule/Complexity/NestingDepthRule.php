<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Complexity;

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
use GruffPhp\Rule\StmtChildBlock;
use GruffPhp\Rule\StmtChildVisitor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Measures the deepest nested control-flow level inside function-like bodies.
 */
final readonly class NestingDepthRule implements RuleInterface
{
    /**
     * Stable rule identifier for nesting depth findings.
     */
    public const ID = 'complexity.nesting-depth';

    /**
     * Describe the nesting-depth rule for the registry and reports.
     *
     * @return RuleDefinition Rule metadata and default thresholds.
     */
    public function definition(): RuleDefinition
    {
        // The rule's registry identity: an error-tier gate that fires once nesting passes a depth of 4.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Maximum nesting depth',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(4, Severity::Error),
        );
    }

    /**
     * Detect functions and methods whose control flow nests too deeply.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Nesting-depth findings for the analysed unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            if (!CyclomaticComplexityRule::hasExecutableBody($node)) {
                continue;
            }

            $maxDepth       = self::computeMaximumNestingDepth($node);
            $thresholdMatch = $settings->highValueThresholdMatch($maxDepth);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has a maximum nesting depth of %d, above the %s threshold of %s.',
                    $symbol,
                    $maxDepth,
                    $thresholdMatch->severity->value,
                    self::formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Reduce nesting by using early returns, guard clauses, or extracting nested logic.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'depth' => $maxDepth,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        // Every function-like node whose deepest nesting crossed the configured threshold.
        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node Function-like node whose body is measured.
     * @return int The maximum nesting depth inside the function-like node.
     */
    public static function computeMaximumNestingDepth(Node $node): int
    {
        // Measure the whole body starting from depth zero.
        return self::walkStatements($node->stmts ?? [], 0);
    }

    /**
     * @param array<Node> $stmts Statements to measure in sequence.
     * @param int         $depth Nesting depth this statement list sits at.
     * @return int The maximum nesting depth inside the statement list.
     */
    private static function walkStatements(array $stmts, int $depth): int
    {
        $maximumDepth = $depth;

        foreach ($stmts as $stmt) {
            $maximumDepth = max($maximumDepth, self::walkNode($stmt, $depth));
        }

        // The deepest level reached by any statement in the list.
        return $maximumDepth;
    }

    /**
     * Measure nesting contribution for a statement node.
     *
     * @param Node $node  Statement node to measure.
     * @param int  $depth Nesting depth this node sits at.
     * @return int The maximum nested depth reached from this node.
     */
    private static function walkNode(Node $node, int $depth): int
    {
        if ($node instanceof Stmt\Expression) {
            // An expression statement only deepens nesting through a closure it may contain.
            return self::walkExprNesting($node->expr, $depth);
        }

        if (!StmtChildVisitor::isControlFlowStmt($node)) {
            // A non-control-flow statement adds no nesting, so the depth is unchanged.
            return $depth;
        }

        // A switch construct contributes one nesting level even when it has no cases.
        $maximumDepth = $node instanceof Stmt\Switch_ ? $depth + 1 : $depth;

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $blockDepth = match ($block->kind) {
                StmtChildBlock::KIND_TRY_BODY, StmtChildBlock::KIND_FINALLY_BODY => $depth,
                default => $depth + 1,
            };
            $maximumDepth = max($maximumDepth, self::walkStatements($block->statements, $blockDepth));
        }

        // The deepest level reached across every child block of this construct.
        return $maximumDepth;
    }

    /**
     * Measure nested closures inside expression statements.
     *
     * @param Expr $expr  Expression to measure; only a closure body adds nesting.
     * @param int  $depth Nesting depth this expression sits at.
     * @return int The maximum expression nesting depth.
     */
    private static function walkExprNesting(Expr $expr, int $depth): int
    {
        if ($expr instanceof Closure) {
            // A closure body nests one level below the expression that holds it.
            return self::walkStatements($expr->stmts ?? [], $depth + 1);
        }

        $maximumDepth = $depth;

        foreach ($expr->getSubNodeNames() as $name) {
            $subExpression = $expr->$name;

            if ($subExpression instanceof Expr) {
                $maximumDepth = max($maximumDepth, self::walkExprNesting($subExpression, $depth));
            }
        }

        // The deepest level reached by any nested sub-expression.
        return $maximumDepth;
    }

    /**
     * Render a configured numeric threshold for finding messages.
     *
     * @param int|float $number Threshold to render; a genuine fraction is kept, a whole value loses its ".0".
     * @return string The threshold without unnecessary decimal places.
     */
    private static function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            // Keep a genuine fraction visible (e.g. "2.5") instead of truncating it to an int.
            return (string) $number;
        }

        // Whole numbers, including whole-valued floats, render without a trailing ".0".
        return (string) (int) $number;
    }
}
