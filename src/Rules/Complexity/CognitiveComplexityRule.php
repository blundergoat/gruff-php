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
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

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
     * @return RuleDefinition - The cognitive-complexity rule's identity and its error-at-20 threshold.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                self::ID,
            name:              'Cognitive complexity',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Error,
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(20, Severity::Error),
        );
    }

    /**
     * Flag methods whose cognitive complexity exceeds the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying thresholds.
     *
     * @return list<Finding> - One finding per function-like node whose score crossed the threshold.
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

            $cc             = self::computeCognitiveComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($cc);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);
            $isFlatGuardFlow = ComplexityShapeClassifier::isFlatGuardClauseFlow($node);
            $severity = $isFlatGuardFlow ? Severity::Advisory : $thresholdMatch->severity;

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: $isFlatGuardFlow
                    ? sprintf(
                        '%s has a cognitive complexity of %d from flat guard clauses, above the %s threshold of %s.',
                        $symbol,
                        $cc,
                        $thresholdMatch->severity->value,
                        self::formatNumber($thresholdMatch->threshold),
                    )
                    : sprintf(
                        '%s has a cognitive complexity of %d, above the %s threshold of %s.',
                        $symbol,
                        $cc,
                        $thresholdMatch->severity->value,
                        self::formatNumber($thresholdMatch->threshold),
                    ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         $severity,
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
                    'thresholdType' => $severity->value,
                    'rawThresholdType' => $thresholdMatch->severity->value,
                    'complexityShape' => $isFlatGuardFlow ? ComplexityShapeClassifier::SHAPE_FLAT_GUARD_CLAUSES : 'branching',
                ],
            );
        }

        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node - Function-like node whose body statements are scored.
     *
     * @return int - Cognitive complexity score for the function-like node.
     */
    public static function computeCognitiveComplexity(Node $node): int
    {
        $body = $node->stmts ?? [];

        return self::walkStatements($body, 0);
    }

    /**
     * @param array<Node> $stmts - Statements to score in sequence.
     * @param int         $nesting - Current nesting depth; deeper levels make each branch cost more.
     *
     * @return int - Cognitive complexity score for the statement list.
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
     * @param Node $node - Statement node dispatched on its concrete type.
     * @param int  $nesting - Current nesting depth carried into the chosen handler.
     *
     * @return int - The complexity contribution of this node and its descendants.
     */
    private static function walkNode(Node $node, int $nesting): int
    {
        // Dispatch each construct to its scorer; an unhandled node falls through to a structural descent.
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
     * @param Stmt\Break_|Stmt\Continue_ $node - Jump statement; a non-null ->num marks a labelled jump.
     *
     * @return int - 1 for a labelled jump, 0 for a plain one.
     */
    private static function walkJump(Stmt\Break_|Stmt\Continue_ $node): int
    {
        // A labelled break/continue (e.g. "break 2") is a real jump worth 1; a plain one is free.
        return $node->num !== null ? 1 : 0;
    }

    /**
     * Score an `if` chain: +1 + nesting for the head, +1 per elseif / else, plus recursive child scoring.
     *
     * @param Stmt\If_ $node - The `if` construct, including its elseif / else child blocks.
     * @param int      $nesting - Current nesting depth; the head increment grows with it.
     *
     * @return int - Combined score of the head, each elseif / else, and the recursively scored bodies.
     */
    private static function walkIf(Stmt\If_ $node, int $nesting): int
    {
        $total = 1 + $nesting + self::walkBooleanOperators($node->cond);

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            if ($block->kind === StmtChildBlock::KIND_ELSEIF_BODY) {
                $total += 1; // elseif: +1, no nesting penalty
                /** @var Stmt\ElseIf_ $owner Block kind discriminator narrows the owner so ->cond is accessible. */
                $owner = $block->owner;
                $total += self::walkBooleanOperators($owner->cond);
            }

            if ($block->kind === StmtChildBlock::KIND_ELSE_BODY) {
                $total += 1; // else: +1, no nesting penalty
            }

            $total += self::walkStatements($block->statements, $nesting + 1);
        }

        // Head increment plus every elseif / else branch and their recursively scored bodies.
        return $total;
    }

    /**
     * Score a `switch` statement: +1 + nesting for the switch, plus recursive scoring of each case body.
     *
     * @param Stmt\Switch_ $node - The `switch` construct whose case bodies are scored.
     * @param int          $nesting - Current nesting depth; the switch increment grows with it.
     *
     * @return int - The switch increment plus each case body scored one level deeper.
     */
    private static function walkSwitch(Stmt\Switch_ $node, int $nesting): int
    {
        $total = 1 + $nesting;

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $total += self::walkStatements($block->statements, $nesting + 1);
        }

        // The switch's own increment plus each case body scored one level deeper.
        return $total;
    }

    /**
     * @param array<Node> $statements - Loop body statements, scored one level deeper than the loop.
     * @param Expr|null   $condition - Loop condition whose boolean operators add cost, or null for `for` / `foreach`.
     * @param int         $nesting - Current nesting depth; the loop increment grows with it.
     *
     * @return int - Cognitive complexity score for the loop body and condition.
     */
    private static function walkLoop(array $statements, ?Expr $condition, int $nesting): int
    {
        $total = 1 + $nesting;

        if ($condition instanceof Expr) {
            $total += self::walkBooleanOperators($condition);
        }

        // Loop increment and condition cost, plus the body scored one nesting level deeper.
        return $total + self::walkStatements($statements, $nesting + 1);
    }

    /**
     * Score a try/catch/finally block; catches add +1 + nesting each, finally inherits the outer nesting level.
     *
     * @param Stmt\TryCatch $node - The try / catch / finally construct.
     * @param int           $nesting - Current nesting depth; each catch increment grows with it.
     *
     * @return int - Accumulated catch penalties plus the try and finally bodies scored at their nesting.
     */
    private static function walkTryCatch(Stmt\TryCatch $node, int $nesting): int
    {
        $total = 0;

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            if ($block->kind === StmtChildBlock::KIND_CATCH_BODY) {
                $total += 1 + $nesting;
                $total += self::walkStatements($block->statements, $nesting + 1);

                continue;
            }

            // try-body and finally-body both score at the outer nesting level.
            $total += self::walkStatements($block->statements, $nesting);
        }

        // Accumulated catch penalties plus the try and finally bodies scored at their proper nesting.
        return $total;
    }

    /**
     * Fallback walker that descends into every child Node / array-of-Node sub-property.
     *
     * @param Node $node - Node with no dedicated scorer; its children are walked structurally.
     * @param int  $nesting - Current nesting depth carried unchanged into each child.
     *
     * @return int - Combined score of every child node reached by the descent.
     */
    private static function walkChildNodes(Node $node, int $nesting): int
    {
        $total = 0;

        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            if ($subNode instanceof Node) {
                $total += self::walkNode($subNode, $nesting);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $child) {
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
     * @param Expr $expr - Expression to score; ternaries, closures, and arrow functions open nesting.
     * @param int  $nesting - Current nesting depth; ternary arms and closures are scored one level deeper.
     *
     * @return int - The expression's own increments plus those of its scored sub-expressions.
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

            // Ternary increment plus its condition and both arms, the arms counted one level deeper.
            return $total;
        }

        if ($expr instanceof Closure) {
            // A closure opens a new nesting level, so its body is scored one deeper.
            return self::walkStatements($expr->stmts ?? [], $nesting + 1);
        }

        if ($expr instanceof Expr\ArrowFunction) {
            // An arrow function also nests, so its single expression is scored one level deeper.
            return self::walkExprCognitive($expr->expr, $nesting + 1);
        }

        $total = 0;

        foreach ($expr->getSubNodeNames() as $name) {
            $subNode = $expr->$name;

            if ($subNode instanceof Expr) {
                $total += self::walkExprCognitive($subNode, $nesting);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $child) {
                    if ($child instanceof Expr) {
                        $total += self::walkExprCognitive($child, $nesting);
                    }
                }
            }
        }

        // Combined score of every sub-expression reached by the generic descent.
        return $total;
    }

    /**
     * Count boolean-operator transitions in a flattened chain (`a && b && c` is +1, `a && b || c` is +2).
     *
     * @param Expr $expr - Condition expression; only boolean-operator chains contribute, anything else scores 0.
     *
     * @return int - One increment per run of like operators — mixing && and || is what costs.
     */
    private static function walkBooleanOperators(Expr $expr): int
    {
        if (!$expr instanceof BinaryOp\BooleanAnd
            && !$expr instanceof BinaryOp\BooleanOr
            && !$expr instanceof BinaryOp\LogicalAnd
            && !$expr instanceof BinaryOp\LogicalOr
        ) {
            // Not a boolean chain, so it contributes no operator-transition cost.
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

        // One increment per run of like operators; switching between && and || is what adds cost.
        return $total;
    }

    /**
     * Flatten nested boolean operators into one chain for scoring.
     *
     * @param Expr               $expr - Expression to flatten; recursion stops at the first non-boolean operand.
     * @param list<class-string> $result - Accumulator, appended in left-to-right order with each operator's class.
     *
     * @return void
     */
    private static function flattenBooleanChain(Expr $expr, array &$result): void
    {
        $isBoolOp = $expr instanceof BinaryOp\BooleanAnd
            || $expr instanceof BinaryOp\BooleanOr
            || $expr instanceof BinaryOp\LogicalAnd
            || $expr instanceof BinaryOp\LogicalOr;

        if (!$isBoolOp) {
            // Reached a non-boolean operand, so this branch of the chain ends here.
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
     * @param int|float $number - Threshold to render; a genuine fraction is kept, a whole value loses its ".0".
     *
     * @return string - The threshold as a display string, e.g. "20" or "2.5".
     */
    private static function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
