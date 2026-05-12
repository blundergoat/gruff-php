<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Complexity;

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
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Measures how hard function-like control flow is to understand.
 */
final readonly class CognitiveComplexityRule implements RuleInterface
{
    /**
     * Stable rule identifier for cognitive complexity findings.
     */
    public const ID = 'complexity.cognitive';

    /**
     * Describe the rule for the registry and reports.
     *
     * @return RuleDefinition
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Cognitive complexity',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Warning,
            confidence:        Confidence::High,
            defaultThresholds: [
                'warning' => 15,
                'error' => 30,
            ],
        );
    }

    /**
     * Flag methods whose cognitive complexity exceeds the configured threshold.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context carrying thresholds.
     * @return list<Finding>
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $settings   = $context->settingsFor($definition);

        $finder = new NodeFinder();
        $nodes  = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod
                || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $cc             = self::compute($node);
            $thresholdMatch = $settings->highValueThresholdMatch($cc);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has a cognitive complexity of %d, above the %s threshold of %s.',
                    $symbol,
                    $cc,
                    $thresholdMatch->severity->value,
                    self::formatNumber($thresholdMatch->threshold),
                ),
                filePath:         $unit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         $thresholdMatch->severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      'Reduce nesting and extract complex conditions into named methods.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'complexity' => $cc,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node
     * @return int Cognitive complexity score for the function-like node.
     */
    public static function compute(Node $node): int
    {
        $body = $node->stmts ?? [];

        return self::walkStatements($body, 0);
    }

    /**
     * @param array<Node> $stmts
     * @return int Cognitive complexity score for the statement list.
     */
    private static function walkStatements(array $stmts, int $nesting): int
    {
        $total = 0;

        foreach ($stmts as $stmt) {
            $total += self::walkNode($stmt, $nesting);
        }

        return $total;
    }

    /**
     * Dispatch a statement node to the matching cognitive-complexity handler.
     *
     * @return int The complexity contribution of this node and its descendants.
     */
    private static function walkNode(Node $node, int $nesting): int
    {
        return match (true) {
            $node instanceof Stmt\If_ => self::walkIf($node, $nesting),
            $node instanceof Stmt\Switch_ => self::walkSwitch($node, $nesting),
            $node instanceof Stmt\For_ => self::walkLoop($node->stmts, null, $nesting),
            $node instanceof Stmt\Foreach_ => self::walkLoop($node->stmts, null, $nesting),
            $node instanceof Stmt\While_ => self::walkLoop($node->stmts, $node->cond, $nesting),
            $node instanceof Stmt\Do_ => self::walkLoop($node->stmts, $node->cond, $nesting),
            $node instanceof Stmt\TryCatch => self::walkTryCatch($node, $nesting),
            $node instanceof Stmt\Break_ => self::walkJump($node),
            $node instanceof Stmt\Continue_ => self::walkJump($node),
            $node instanceof Stmt\Goto_ => 1,
            $node instanceof Stmt\Expression => self::walkExprCognitive($node->expr, $nesting),
            $node instanceof Stmt\Return_ => $node->expr instanceof Expr ? self::walkExprCognitive($node->expr, $nesting) : 0,
            default => self::walkChildNodes($node, $nesting),
        };
    }

    /**
     * Score a `break` / `continue` statement; labelled jumps add 1, plain jumps add 0.
     *
     * @return int
     */
    private static function walkJump(Stmt\Break_|Stmt\Continue_ $node): int
    {
        return $node->num !== null ? 1 : 0;
    }

    /**
     * Score an `if` chain: +1 + nesting for the head, +1 per elseif / else, plus recursive child scoring.
     *
     * @return int
     */
    private static function walkIf(Stmt\If_ $node, int $nesting): int
    {
        $total = 1 + $nesting + self::walkBooleanOperators($node->cond);
        $total += self::walkStatements($node->stmts, $nesting + 1);

        foreach ($node->elseifs as $elseif) {
            $total += 1; // elseif: +1, no nesting penalty
            $total += self::walkBooleanOperators($elseif->cond);
            $total += self::walkStatements($elseif->stmts, $nesting + 1);
        }

        if ($node->else !== null) {
            $total += 1; // else: +1, no nesting penalty
            $total += self::walkStatements($node->else->stmts, $nesting + 1);
        }

        return $total;
    }

    /**
     * Score a `switch` statement: +1 + nesting for the switch, plus recursive scoring of each case body.
     *
     * @return int
     */
    private static function walkSwitch(Stmt\Switch_ $node, int $nesting): int
    {
        $total = 1 + $nesting;

        foreach ($node->cases as $case) {
            $total += self::walkStatements($case->stmts, $nesting + 1);
        }

        return $total;
    }

    /**
     * @param array<Node> $statements
     * @return int Cognitive complexity score for the loop body and condition.
     */
    private static function walkLoop(array $statements, ?Expr $condition, int $nesting): int
    {
        $total = 1 + $nesting;

        if ($condition instanceof Expr) {
            $total += self::walkBooleanOperators($condition);
        }

        return $total + self::walkStatements($statements, $nesting + 1);
    }

    /**
     * Score a try/catch/finally block; catches add +1 + nesting each, finally inherits the outer nesting level.
     *
     * @return int
     */
    private static function walkTryCatch(Stmt\TryCatch $node, int $nesting): int
    {
        $total = self::walkStatements($node->stmts, $nesting);

        foreach ($node->catches as $catch) {
            $total += 1 + $nesting;
            $total += self::walkStatements($catch->stmts, $nesting + 1);
        }

        if ($node->finally !== null) {
            $total += self::walkStatements($node->finally->stmts, $nesting);
        }

        return $total;
    }

    /**
     * Fallback walker that descends into every child Node / array-of-Node sub-property.
     *
     * @return int
     */
    private static function walkChildNodes(Node $node, int $nesting): int
    {
        $total = 0;

        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;

            if ($sub instanceof Node) {
                $total += self::walkNode($sub, $nesting);
            } elseif (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child instanceof Node) {
                        $total += self::walkNode($child, $nesting);
                    }
                }
            }
        }

        return $total;
    }

    /**
     * Score an expression's contribution to cognitive complexity (ternaries, closures, arrow fns, nested expressions).
     *
     * @return int
     */
    private static function walkExprCognitive(Expr $expr, int $nesting): int
    {
        if ($expr instanceof Expr\Ternary) {
            $total = 1 + $nesting;
            $total += self::walkExprCognitive($expr->cond, $nesting);

            if ($expr->if !== null) {
                $total += self::walkExprCognitive($expr->if, $nesting + 1);
            }

            $total += self::walkExprCognitive($expr->else, $nesting + 1);

            return $total;
        }

        if ($expr instanceof Closure) {
            return self::walkStatements($expr->stmts ?? [], $nesting + 1);
        }

        if ($expr instanceof Expr\ArrowFunction) {
            return self::walkExprCognitive($expr->expr, $nesting + 1);
        }

        $total = 0;

        foreach ($expr->getSubNodeNames() as $name) {
            $sub = $expr->$name;

            if ($sub instanceof Expr) {
                $total += self::walkExprCognitive($sub, $nesting);
            } elseif (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child instanceof Expr) {
                        $total += self::walkExprCognitive($child, $nesting);
                    }
                }
            }
        }

        return $total;
    }

    /**
     * Count boolean-operator transitions in a flattened chain (`a && b && c` is +1, `a && b || c` is +2).
     *
     * @return int
     */
    private static function walkBooleanOperators(Expr $expr): int
    {
        if (!$expr instanceof BinaryOp\BooleanAnd
            && !$expr instanceof BinaryOp\BooleanOr
            && !$expr instanceof BinaryOp\LogicalAnd
            && !$expr instanceof BinaryOp\LogicalOr
        ) {
            return 0;
        }

        $total = 0;
        $flat  = [];
        self::flattenBooleanChain($expr, $flat);

        $lastOperatorClass = null;

        foreach ($flat as $operatorClass) {
            if ($operatorClass !== $lastOperatorClass) {
                $total++;
                $lastOperatorClass = $operatorClass;
            }
        }

        return $total;
    }

    /**
     * @param list<class-string> $result
     * @return void
     */
    private static function flattenBooleanChain(Expr $expr, array &$result): void
    {
        $isBoolOp = $expr instanceof BinaryOp\BooleanAnd
            || $expr instanceof BinaryOp\BooleanOr
            || $expr instanceof BinaryOp\LogicalAnd
            || $expr instanceof BinaryOp\LogicalOr;

        if (!$isBoolOp) {
            return;
        }

        /** @var BinaryOp $expr The boolean-operator guard narrows the expression before recursion. */
        self::flattenBooleanChain($expr->left, $result);
        $result[] = $expr::class;
        self::flattenBooleanChain($expr->right, $result);
    }

    /**
     * Format a numeric threshold as a string, preserving fractional values that are not whole.
     *
     * @return string
     */
    private static function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return (string) $value;
        }

        return (string) (int) $value;
    }
}
