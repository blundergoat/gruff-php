<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

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
use GruffPhp\Rules\Shared\StmtChildBlock;
use GruffPhp\Rules\Shared\StmtChildVisitor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a function or method whose control flow nests too deeply, since deep nesting is one of the
 * clearest signals that logic has become hard to follow.
 *
 * Runs per file over every function-like node with a body. It measures the deepest level of nested ifs,
 * loops, switches, and closures (try/finally bodies do not add a level), and reports anything past the
 * configured maximum (default error above 4). The finding names the depth and suggests early returns or
 * extraction.
 */
final readonly class NestingDepthRule implements RuleInterface
{
    /**
     * Stable rule identifier for nesting depth findings.
     */
    public const ID = 'complexity.nesting-depth';

    /**
     * Describes the nesting-depth rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and default thresholds.
     */
    public function definition(): RuleDefinition
    {
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
     * Reports each function-like node whose deepest nesting exceeds the configured maximum.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Nesting-depth findings for the analysed unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // Measure every function and method in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // An abstract or bodyless declaration has nothing to measure.
            if (!CyclomaticComplexityRule::hasExecutableBody($node)) {
                continue;
            }

            $maxDepth       = self::computeMaximumNestingDepth($node);
            $thresholdMatch = $settings->highValueThresholdMatch($maxDepth);

            // A depth within the limit is fine, so skip it.
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

        return $findings;
    }

    /**
     * Computes the deepest control-flow nesting level inside one function-like node.
     *
     * @param ClassMethod|Function_ $node - Function-like node whose body is measured.
     *
     * @return int - The maximum nesting depth inside the function-like node.
     */
    public static function computeMaximumNestingDepth(Node $node): int
    {
        return self::walkStatements($node->stmts ?? [], 0);
    }

    /**
     * Returns the deepest nesting any statement in a list reaches, from the list's own depth.
     *
     * @param array<Node> $stmts - Statements to measure in sequence.
     * @param int         $depth - Nesting depth this statement list sits at.
     *
     * @return int - The maximum nesting depth inside the statement list.
     */
    private static function walkStatements(array $stmts, int $depth): int
    {
        $maximumDepth = $depth;

        // Take the deepest level any statement in this list reaches.
        foreach ($stmts as $stmt) {
            $maximumDepth = max($maximumDepth, self::walkNode($stmt, $depth));
        }

        return $maximumDepth;
    }

    /**
     * Returns the deepest nesting reachable from one statement node.
     *
     * @param Node $node - Statement node to measure.
     * @param int  $depth - Nesting depth this node sits at.
     *
     * @return int - The maximum nested depth reached from this node.
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

        // Each control block adds a level, except try and finally bodies.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $blockDepth = match ($block->kind) {
                StmtChildBlock::KIND_TRY_BODY, StmtChildBlock::KIND_FINALLY_BODY => $depth,
                default => $depth + 1,
            };
            $maximumDepth = max($maximumDepth, self::walkStatements($block->statements, $blockDepth));
        }

        return $maximumDepth;
    }

    /**
     * Returns the deepest nesting a closure inside an expression contributes (other expressions add none).
     *
     * @param Expr $expr - Expression to measure; only a closure body adds nesting.
     * @param int  $depth - Nesting depth this expression sits at.
     *
     * @return int - The maximum expression nesting depth.
     */
    private static function walkExprNesting(Expr $expr, int $depth): int
    {
        if ($expr instanceof Closure) {
            // A closure body nests one level below the expression that holds it.
            return self::walkStatements($expr->stmts ?? [], $depth + 1);
        }

        $maximumDepth = $depth;

        // Descend into sub-expressions to find any nested closure.
        foreach ($expr->getSubNodeNames() as $name) {
            $subExpression = $expr->$name;

            // Only recurse into actual expression children.
            if ($subExpression instanceof Expr) {
                $maximumDepth = max($maximumDepth, self::walkExprNesting($subExpression, $depth));
            }
        }

        return $maximumDepth;
    }

    /**
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Threshold to render; a genuine fraction is kept, a whole value loses its ".0".
     *
     * @return string - The threshold without unnecessary decimal places.
     */
    private static function formatNumber(int|float $number): string
    {
        // A genuine fraction keeps its decimals; a whole value is shown without them.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
