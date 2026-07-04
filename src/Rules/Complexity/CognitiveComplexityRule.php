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
 * Flags a function or method whose cognitive complexity - how hard its control flow is for a human to
 * follow - crosses the configured threshold, penalising nesting and mixed boolean logic the way a reader
 * actually experiences them.
 *
 * Runs per file over every function-like node with a body. Unlike cyclomatic complexity, it charges more
 * for deeply nested branches and for switching between && and ||, but only once for a whole switch or
 * match. Anything over the threshold (default error above 20) is reported; a flat guard-clause method is
 * softened to advisory so the shape is not over-penalised.
 */
final readonly class CognitiveComplexityRule implements RuleInterface
{
    /**
     * Stable rule identifier for cognitive complexity findings.
     */
    public const ID = 'complexity.cognitive';

    /**
     * Describes the cognitive-complexity rule for the registry and reports.
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
     * Reports each function-like node whose cognitive complexity exceeds the configured threshold.
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

        // Measure every function and method in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // An abstract or bodyless declaration has no control flow to score.
            if (!CyclomaticComplexityRule::hasExecutableBody($node)) {
                continue;
            }

            $cc             = self::computeCognitiveComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($cc);

            // A score within the threshold is fine, so skip it.
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
     * Computes the cognitive complexity of one function-like node from its body statements.
     *
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
     * Sums the cognitive score of every statement in a list at the given nesting depth.
     *
     * @param array<Node> $stmts - Statements to score in sequence.
     * @param int         $nesting - Current nesting depth; deeper levels make each branch cost more.
     *
     * @return int - Cognitive complexity score for the statement list.
     */
    private static function walkStatements(array $stmts, int $nesting): int
    {
        $total = 0;

        // Add up the score of each statement in this list.
        foreach ($stmts as $stmt) {
            $total += self::walkNode($stmt, $nesting);
        }

        return $total;
    }

    /**
     * Dispatches one statement node to the scorer for its construct, or a structural descent.
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
     * Scores a break/continue: a labelled jump costs 1, a plain one costs 0.
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
     * Scores an if chain: +1 plus nesting for the head, +1 per elseif/else, plus the recursive bodies.
     *
     * @param Stmt\If_ $node - The `if` construct, including its elseif / else child blocks.
     * @param int      $nesting - Current nesting depth; the head increment grows with it.
     *
     * @return int - Combined score of the head, each elseif / else, and the recursively scored bodies.
     */
    private static function walkIf(Stmt\If_ $node, int $nesting): int
    {
        $total = 1 + $nesting + self::walkBooleanOperators($node->cond);

        // Score each branch of the if/elseif/else chain.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            // An elseif adds one, with no nesting penalty of its own.
            if ($block->kind === StmtChildBlock::KIND_ELSEIF_BODY) {
                $total += 1; // elseif: +1, no nesting penalty
                /** @var Stmt\ElseIf_ $owner Block kind discriminator narrows the owner so ->cond is accessible. */
                $owner = $block->owner;
                $total += self::walkBooleanOperators($owner->cond);
            }

            // An else adds one, with no nesting penalty.
            if ($block->kind === StmtChildBlock::KIND_ELSE_BODY) {
                $total += 1; // else: +1, no nesting penalty
            }

            $total += self::walkStatements($block->statements, $nesting + 1);
        }

        // Head increment plus every elseif / else branch and their recursively scored bodies.
        return $total;
    }

    /**
     * Scores a switch: +1 plus nesting for the construct, plus each case body one level deeper.
     *
     * @param Stmt\Switch_ $node - The `switch` construct whose case bodies are scored.
     * @param int          $nesting - Current nesting depth; the switch increment grows with it.
     *
     * @return int - The switch increment plus each case body scored one level deeper.
     */
    private static function walkSwitch(Stmt\Switch_ $node, int $nesting): int
    {
        $total = 1 + $nesting;

        // Score each case body one nesting level deeper.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $total += self::walkStatements($block->statements, $nesting + 1);
        }

        // The switch's own increment plus each case body scored one level deeper.
        return $total;
    }

    /**
     * Scores a loop: +1 plus nesting, its condition's boolean cost, and the body one level deeper.
     *
     * @param array<Node> $statements - Loop body statements, scored one level deeper than the loop.
     * @param Expr|null   $condition - Loop condition whose boolean operators add cost, or null for `for` / `foreach`.
     * @param int         $nesting - Current nesting depth; the loop increment grows with it.
     *
     * @return int - Cognitive complexity score for the loop body and condition.
     */
    private static function walkLoop(array $statements, ?Expr $condition, int $nesting): int
    {
        $total = 1 + $nesting;

        // A while/do condition's boolean operators add cost; for/foreach have none.
        if ($condition instanceof Expr) {
            $total += self::walkBooleanOperators($condition);
        }

        // Loop increment and condition cost, plus the body scored one nesting level deeper.
        return $total + self::walkStatements($statements, $nesting + 1);
    }

    /**
     * Scores a try/catch/finally: each catch adds +1 plus nesting; try and finally keep the outer level.
     *
     * @param Stmt\TryCatch $node - The try / catch / finally construct.
     * @param int           $nesting - Current nesting depth; each catch increment grows with it.
     *
     * @return int - Accumulated catch penalties plus the try and finally bodies scored at their nesting.
     */
    private static function walkTryCatch(Stmt\TryCatch $node, int $nesting): int
    {
        $total = 0;

        // Score each block of the try construct.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            // Each catch adds one plus nesting, and its body scores one level deeper.
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
     * Fallback scorer that descends into every child node of a construct with no dedicated handler.
     *
     * @param Node $node - Node with no dedicated scorer; its children are walked structurally.
     * @param int  $nesting - Current nesting depth carried unchanged into each child.
     *
     * @return int - Combined score of every child node reached by the descent.
     */
    private static function walkChildNodes(Node $node, int $nesting): int
    {
        $total = 0;

        // Descend into each child node or array of children.
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            // A single child node is scored directly; an array of them is scored element by element.
            if ($subNode instanceof Node) {
                $total += self::walkNode($subNode, $nesting);
            } elseif (is_array($subNode)) {
                // Score each real child node in the array.
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
     * Scores an expression's contribution: ternaries, match, closures, and arrow fns open nesting.
     *
     * @param Expr $expr - Expression to score; ternaries, closures, and arrow functions open nesting.
     * @param int  $nesting - Current nesting depth; ternary arms and closures are scored one level deeper.
     *
     * @return int - The expression's own increments plus those of its scored sub-expressions.
     */
    private static function walkExprCognitive(Expr $expr, int $nesting): int
    {
        // A ternary adds one plus nesting; both arms are scored one level deeper.
        if ($expr instanceof Expr\Ternary) {
            $total = 1 + $nesting;
            $total += self::walkExprCognitive($expr->cond, $nesting);

            // The 'then' arm is optional in a short ternary (?:), so score it only when present.
            if ($expr->if !== null) {
                $total += self::walkExprCognitive($expr->if, $nesting + 1);
            }

            $total += self::walkExprCognitive($expr->else, $nesting + 1);

            // Ternary increment plus its condition and both arms, the arms counted one level deeper.
            return $total;
        }

        // Match expressions get their own scorer so switch-to-match rewrites cannot dodge the gate.
        if ($expr instanceof Expr\Match_) {
            return self::walkMatch($expr, $nesting);
        }

        // A closure opens a new nesting level, so its body is scored one deeper.
        if ($expr instanceof Closure) {
            return self::walkStatements($expr->stmts ?? [], $nesting + 1);
        }

        // An arrow function also nests, so its single expression is scored one level deeper.
        if ($expr instanceof Expr\ArrowFunction) {
            return self::walkExprCognitive($expr->expr, $nesting + 1);
        }

        $total = 0;

        // Descend into each sub-expression or array of them.
        foreach ($expr->getSubNodeNames() as $name) {
            $subNode = $expr->$name;

            // A single sub-expression is scored directly; an array of them is scored element by element.
            if ($subNode instanceof Expr) {
                $total += self::walkExprCognitive($subNode, $nesting);
            } elseif (is_array($subNode)) {
                // Score each real sub-expression in the array.
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
     * Scores a match: +1 plus nesting for the construct, its subject, and every arm one level deeper.
     *
     * Mirrors cognitive `switch`: one increment for the construct, never per arm, so a small match
     * stays cheap while a match stuffed with nested logic costs what the reader actually pays.
     * Cyclomatic complexity intentionally differs by charging every arm as a branch.
     *
     * @param Expr\Match_ $match - The `match` expression whose subject, arm conditions, and arm bodies are scored.
     * @param int         $nesting - Current nesting depth; the match increment grows with it.
     *
     * @return int - The match increment plus its subject at the current depth and every arm one level deeper.
     */
    private static function walkMatch(Expr\Match_ $match, int $nesting): int
    {
        $total = 1 + $nesting;
        // Unlike switch subjects, match subjects are expressions inside a value-producing expression tree.
        $total += self::walkExprCognitive($match->cond, $nesting);

        // Every arm's conditions and body are real reading work, so each is scored one level deeper.
        foreach ($match->arms as $arm) {
            // Each arm condition is scored one level deeper (the default arm has none).
            foreach ($arm->conds ?? [] as $armCondition) {
                $total += self::walkExprCognitive($armCondition, $nesting + 1);
            }

            $total += self::walkExprCognitive($arm->body, $nesting + 1);
        }

        return $total;
    }

    /**
     * Counts boolean-operator transitions in a chain (`a && b && c` is +1, `a && b || c` is +2).
     *
     * @param Expr $expr - Condition expression; only boolean-operator chains contribute, anything else scores 0.
     *
     * @return int - One increment per run of like operators - mixing && and || is what costs.
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

        // Add one each time the operator kind changes along the flattened chain.
        foreach ($flat as $operatorClass) {
            // A switch between && and || starts a new run and costs one.
            if ($operatorClass !== $lastOperatorClass) {
                $total++;
                $lastOperatorClass = $operatorClass;
            }
        }

        // One increment per run of like operators; switching between && and || is what adds cost.
        return $total;
    }

    /**
     * Flattens a nested boolean chain into a left-to-right list of operator classes for scoring.
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
     * Formats a numeric threshold as a display string, preserving fractional values that are not whole.
     *
     * @param int|float $number - Threshold to render; a genuine fraction is kept, a whole value loses its ".0".
     *
     * @return string - The threshold as a display string, e.g. "20" or "2.5".
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
