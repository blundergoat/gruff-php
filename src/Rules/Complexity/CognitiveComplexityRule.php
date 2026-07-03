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
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // User view: choose the findings list branch for this case.
            if (!CyclomaticComplexityRule::hasExecutableBody($node)) {
                continue;
            }

            $cc             = self::computeCognitiveComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($cc);

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_ $node - Function-like node whose body statements are scored.
     *
     * @return int - Cognitive complexity score for the function-like node.
     */
    public static function computeCognitiveComplexity(Node $node): int
    {
        // User view: missing data becomes a safe findings list default.
        $body = $node->stmts ?? [];

        return self::walkStatements($body, 0);
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param array<Node> $stmts - Statements to score in sequence.
     * @param int         $nesting - Current nesting depth; deeper levels make each branch cost more.
     *
     * @return int - Cognitive complexity score for the statement list.
     */
    private static function walkStatements(array $stmts, int $nesting): int
    {
        $total = 0;

        // User view: add each item that can appear in findings list.
        foreach ($stmts as $stmt) {
            $total += self::walkNode($stmt, $nesting);
        }

        return $total;
    }

    /**
     * Dispatch a statement node to the matching cognitive-complexity handler.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Break_|Stmt\Continue_ $node - Jump statement; a non-null ->num marks a labelled jump.
     *
     * @return int - 1 for a labelled jump, 0 for a plain one.
     */
    private static function walkJump(Stmt\Break_|Stmt\Continue_ $node): int
    {
        // A labelled break/continue (e.g. "break 2") is a real jump worth 1; a plain one is free.
        // User view: missing data becomes the expected findings list state.
        return $node->num !== null ? 1 : 0;
    }

    /**
     * Score an `if` chain: +1 + nesting for the head, +1 per elseif / else, plus recursive child scoring.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\If_ $node - The `if` construct, including its elseif / else child blocks.
     * @param int      $nesting - Current nesting depth; the head increment grows with it.
     *
     * @return int - Combined score of the head, each elseif / else, and the recursively scored bodies.
     */
    private static function walkIf(Stmt\If_ $node, int $nesting): int
    {
        $total = 1 + $nesting + self::walkBooleanOperators($node->cond);

        // User view: add each item that can appear in findings list.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            // User view: choose the findings list branch for this case.
            if ($block->kind === StmtChildBlock::KIND_ELSEIF_BODY) {
                $total += 1; // elseif: +1, no nesting penalty
                /** @var Stmt\ElseIf_ $owner Block kind discriminator narrows the owner so ->cond is accessible. */
                $owner = $block->owner;
                $total += self::walkBooleanOperators($owner->cond);
            }

            // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Switch_ $node - The `switch` construct whose case bodies are scored.
     * @param int          $nesting - Current nesting depth; the switch increment grows with it.
     *
     * @return int - The switch increment plus each case body scored one level deeper.
     */
    private static function walkSwitch(Stmt\Switch_ $node, int $nesting): int
    {
        $total = 1 + $nesting;

        // User view: add each item that can appear in findings list.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $total += self::walkStatements($block->statements, $nesting + 1);
        }

        // The switch's own increment plus each case body scored one level deeper.
        return $total;
    }

    /**
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: choose the findings list branch for this case.
        if ($condition instanceof Expr) {
            $total += self::walkBooleanOperators($condition);
        }

        // Loop increment and condition cost, plus the body scored one nesting level deeper.
        return $total + self::walkStatements($statements, $nesting + 1);
    }

    /**
     * Score a try/catch/finally block; catches add +1 + nesting each, finally inherits the outer nesting level.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\TryCatch $node - The try / catch / finally construct.
     * @param int           $nesting - Current nesting depth; each catch increment grows with it.
     *
     * @return int - Accumulated catch penalties plus the try and finally bodies scored at their nesting.
     */
    private static function walkTryCatch(Stmt\TryCatch $node, int $nesting): int
    {
        $total = 0;

        // User view: add each item that can appear in findings list.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Node with no dedicated scorer; its children are walked structurally.
     * @param int  $nesting - Current nesting depth carried unchanged into each child.
     *
     * @return int - Combined score of every child node reached by the descent.
     */
    private static function walkChildNodes(Node $node, int $nesting): int
    {
        $total = 0;

        // User view: add each item that can appear in findings list.
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            // User view: choose the findings list branch for this case.
            if ($subNode instanceof Node) {
                $total += self::walkNode($subNode, $nesting);
            }
            // User view: choose the next findings list branch for this case.
            elseif (is_array($subNode)) {
                // User view: add each item that can appear in findings list.
                foreach ($subNode as $child) {
                    // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $expr - Expression to score; ternaries, closures, and arrow functions open nesting.
     * @param int  $nesting - Current nesting depth; ternary arms and closures are scored one level deeper.
     *
     * @return int - The expression's own increments plus those of its scored sub-expressions.
     */
    private static function walkExprCognitive(Expr $expr, int $nesting): int
    {
        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\Ternary) {
            $total = 1 + $nesting;
            $total += self::walkExprCognitive($expr->cond, $nesting);

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($expr->if !== null) {
                $total += self::walkExprCognitive($expr->if, $nesting + 1);
            }

            $total += self::walkExprCognitive($expr->else, $nesting + 1);

            // Ternary increment plus its condition and both arms, the arms counted one level deeper.
            return $total;
        }

        // Match expressions get their own scorer so switch-to-match rewrites cannot dodge the gate.
        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\Match_) {
            return self::walkMatch($expr, $nesting);
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Closure) {
            // A closure opens a new nesting level, so its body is scored one deeper.
            // User view: missing data becomes a safe findings list default.
            return self::walkStatements($expr->stmts ?? [], $nesting + 1);
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\ArrowFunction) {
            // An arrow function also nests, so its single expression is scored one level deeper.
            return self::walkExprCognitive($expr->expr, $nesting + 1);
        }

        $total = 0;

        // User view: add each item that can appear in findings list.
        foreach ($expr->getSubNodeNames() as $name) {
            $subNode = $expr->$name;

            // User view: choose the findings list branch for this case.
            if ($subNode instanceof Expr) {
                $total += self::walkExprCognitive($subNode, $nesting);
            }
            // User view: choose the next findings list branch for this case.
            elseif (is_array($subNode)) {
                // User view: add each item that can appear in findings list.
                foreach ($subNode as $child) {
                    // User view: choose the findings list branch for this case.
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
     * Score a `match` expression: +1 + nesting for the construct, plus arms scored one level deeper.
     *
     * Mirrors cognitive `switch`: one increment for the construct, never per arm, so a small match
     * stays cheap while a match stuffed with nested logic costs what the reader actually pays.
     * Cyclomatic complexity intentionally differs by charging every arm as a branch.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: add each item that can appear in findings list.
        foreach ($match->arms as $arm) {
            // User view: add each item that can appear in findings list.
            // User view: missing data becomes a safe findings list default.
            foreach ($arm->conds ?? [] as $armCondition) {
                $total += self::walkExprCognitive($armCondition, $nesting + 1);
            }

            $total += self::walkExprCognitive($arm->body, $nesting + 1);
        }

        return $total;
    }

    /**
     * Count boolean-operator transitions in a flattened chain (`a && b && c` is +1, `a && b || c` is +2).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $expr - Condition expression; only boolean-operator chains contribute, anything else scores 0.
     *
     * @return int - One increment per run of like operators — mixing && and || is what costs.
     */
    private static function walkBooleanOperators(Expr $expr): int
    {
        // User view: choose the findings list branch for this case.
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

        // User view: add each item that can appear in findings list.
        foreach ($flat as $operatorClass) {
            // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param int|float $number - Threshold to render; a genuine fraction is kept, a whole value loses its ".0".
     *
     * @return string - The threshold as a display string, e.g. "20" or "2.5".
     */
    private static function formatNumber(int|float $number): string
    {
        // User view: choose the findings list branch for this case.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
