<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Size\LimitBand;
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
 * match.
 *
 * Findings below one and a half times the active limit are advisory; the upper band keeps the configured
 * severity, except that a flat guard-clause method stays advisory.
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
            confidence:        Confidence::Low,
            severityThreshold: new SeverityThreshold(20, Severity::Error),
            falsePositiveShapes: [
                [
                    'shape'      => 'A series of shallow `if` checks for requirements, polyfills, or optional output can score high because each check adds to the function\'s total.',
                    'mitigation' => 'Keep each check beside the action it controls so the full sequence can be read in execution order.',
                ],
                [
                    'shape'      => 'A function with explicit branches for types, body modes, failure cases, or null comparisons can score high as branch points and nesting penalties add up.',
                    'mitigation' => 'Keep parallel cases together and trace each branch to its outcome, including early `return` and `continue` paths.',
                ],
            ],
        );
    }

    /**
     * Reports each function-like node whose cognitive complexity exceeds the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context carrying thresholds.
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
            /** @var ClassMethod|Function_ $node - NodeIndex limits this scan to methods and named functions. */
            // An abstract or bodyless declaration has no control flow to score.
            if (!FunctionComplexity::hasExecutableBody($node)) {
                continue;
            }

            $cc             = self::computeCognitiveComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($cc);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = FunctionComplexity::resolveSymbol($node);
            $isFlatGuardFlow = ComplexityShapeClassifier::isFlatGuardClauseFlow($node);
            $band = LimitBand::of($cc, $settings->lowestHighValueThreshold());
            $severity = LimitBand::severity($band, $isFlatGuardFlow ? Severity::Advisory : $thresholdMatch->severity);

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
                remediation:      LimitBand::advice($band, LimitBand::LOWER_METHOD, LimitBand::SIMPLIFY_PATH),
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'complexity' => $cc,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $severity->value,
                    'rawThresholdType' => $thresholdMatch->severity->value,
                    'complexityShape' => $isFlatGuardFlow ? ComplexityShapeClassifier::SHAPE_FLAT_GUARD_CLAUSES : 'branching',
                    LimitBand::KEY => $band,
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
     * @param array<Node> $stmts   - Statements to score in sequence.
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
     * Dispatches one AST node to its dedicated scorer or to structural descent.
     *
     * @param Node $node    - AST node dispatched on its concrete type or traversed structurally.
     * @param int  $nesting - Current nesting depth carried into the chosen handler.
     *
     * @return int - The complexity contribution of this node and its descendants.
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
     * Scores a break or continue with an explicit level as 1 and a plain jump as 0.
     *
     * @param Stmt\Break_|Stmt\Continue_ $node - Jump statement; non-null ->num supplies an explicit level.
     *
     * @return int - 1 for a jump with an explicit level, 0 for a plain jump.
     */
    private static function walkJump(Stmt\Break_|Stmt\Continue_ $node): int
    {
        return $node->num !== null ? 1 : 0;
    }

    /**
     * Scores an if chain: +1 for its head, condition operators, applicable nesting, +1 per elseif or else, and all body scores.
     *
     * @param Stmt\If_ $node    - If construct, including its elseif and else child blocks.
     * @param int      $nesting - Current depth; a head without the single-exit shape adds this nesting penalty.
     *
     * @return int - Combined score of the head, condition operators, nesting, each elseif or else, and recursively scored bodies.
     */
    private static function walkIf(Stmt\If_ $node, int $nesting): int
    {
        // The syntactic single-exit shape gets no nesting penalty under this rule's guard-clause treatment.
        $penalty = self::isEarlyExitGuard($node) ? 0 : $nesting;
        $total   = 1 + $penalty + self::walkBooleanOperators($node->cond);

        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            if ($block->kind === StmtChildBlock::KIND_ELSEIF_BODY) {
                $total += 1;
                /** @var Stmt\ElseIf_ $owner - Block kind discriminator narrows the owner so ->cond is accessible. */
                $owner = $block->owner;
                $total += self::walkBooleanOperators($owner->cond);
            }

            if ($block->kind === StmtChildBlock::KIND_ELSE_BODY) {
                $total += 1;
            }

            $total += self::walkStatements($block->statements, $nesting + 1);
        }

        return $total;
    }

    /**
     * Scores a switch: +1 plus nesting for the construct, plus each case body at the switch's own level.
     *
     * @param Stmt\Switch_ $node    - Switch construct whose case bodies are scored.
     * @param int          $nesting - Current nesting depth; the switch increment grows with it.
     *
     * @return int - The switch increment plus each case body scored at the switch's own nesting level.
     */
    private static function walkSwitch(Stmt\Switch_ $node, int $nesting): int
    {
        $total = 1 + $nesting;

        // Cases are siblings of one dispatch, so their bodies keep the switch's own nesting level.
        foreach (StmtChildVisitor::childBlocks($node) as $block) {
            $total += self::walkStatements($block->statements, $nesting);
        }

        return $total;
    }

    /**
     * Recognizes an if with no elseif or else and exactly one return, continue, break, or throw statement.
     *
     * @param Stmt\If_ $node - If statement being classified.
     *
     * @return bool - True when the if has no alternate branch and its body is one supported exit statement.
     */
    private static function isEarlyExitGuard(Stmt\If_ $node): bool
    {
        if ($node->elseifs !== [] || $node->else !== null || count($node->stmts) !== 1) {
            return false;
        }

        $exit = $node->stmts[0];

        return $exit instanceof Stmt\Return_
            || $exit instanceof Stmt\Continue_
            || $exit instanceof Stmt\Break_
            || ($exit instanceof Stmt\Expression && $exit->expr instanceof Expr\Throw_);
    }

    /**
     * Scores a loop: +1 plus nesting, its condition's boolean cost, and the body one level deeper.
     *
     * @param array<Node> $statements - Loop body statements, scored one level deeper than the loop.
     * @param Expr|null   $condition  - Loop condition whose boolean operators add cost; null is used for for and foreach loops.
     * @param int         $nesting    - Current nesting depth; the loop increment grows with it.
     *
     * @return int - Loop increment, condition cost, and body score at one level deeper.
     */
    private static function walkLoop(array $statements, ?Expr $condition, int $nesting): int
    {
        $total = 1 + $nesting;

        // Only while and do-while conditions contribute boolean-chain cost here.
        if ($condition instanceof Expr) {
            $total += self::walkBooleanOperators($condition);
        }

        return $total + self::walkStatements($statements, $nesting + 1);
    }

    /**
     * Scores a try/catch/finally: each catch adds +1 plus nesting; try and finally keep the outer level.
     *
     * @param Stmt\TryCatch $node    - The try / catch / finally construct.
     * @param int           $nesting - Current nesting depth; each catch increment grows with it.
     *
     * @return int - Catch increments and all bodies, with catch bodies scored one level deeper.
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

        return $total;
    }

    /**
     * Fallback scorer that descends into every child node of a construct with no dedicated handler.
     *
     * @param Node $node    - Node with no dedicated scorer; its children are walked structurally.
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
     * Score expression control flow; ternary arms, match arms, and anonymous-function bodies add nesting.
     *
     * @param Expr $expr    - Expression to score; ternaries, closures, and arrow functions open nesting.
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

            return $total;
        }

        // Match expressions get their own scorer so switch-to-match rewrites cannot dodge the gate.
        if ($expr instanceof Expr\Match_) {
            return self::walkMatch($expr, $nesting);
        }

        if ($expr instanceof Closure) {
            return self::walkStatements($expr->stmts ?? [], $nesting + 1);
        }

        if ($expr instanceof Expr\ArrowFunction) {
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

        return $total;
    }

    /**
     * Score a match once for dispatch, with its subject at the current depth and each arm one level deeper.
     * Like a switch, it adds no per-arm cognitive increment; cyclomatic scoring still counts arm conditions.
     *
     * @param Expr\Match_ $match   - The match expression whose subject, arm conditions, and arm bodies are scored.
     * @param int         $nesting - Current nesting depth; the match increment grows with it.
     *
     * @return int - The match increment plus its subject at the current depth and every arm one level deeper.
     */
    private static function walkMatch(Expr\Match_ $match, int $nesting): int
    {
        $total = 1 + $nesting;
        // Unlike switch subjects, match subjects are expressions inside a value-producing expression tree.
        $total += self::walkExprCognitive($match->cond, $nesting);

        foreach ($match->arms as $arm) {
            foreach ($arm->conds ?? [] as $armCondition) {
                $total += self::walkExprCognitive($armCondition, $nesting + 1);
            }

            $total += self::walkExprCognitive($arm->body, $nesting + 1);
        }

        return $total;
    }

    /**
     * Count runs of equal boolean operators (a && b && c is +1, a && b || c is +2).
     *
     * @param Expr $expr - Condition expression; only boolean-operator chains contribute, anything else scores 0.
     *
     * @return int - One increment per run of one operator kind; changing between "&&", "||", "and", and "or" starts another run.
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

        // The first operator and every later change of operator kind each start a charged run.
        foreach ($flat as $operatorClass) {
            if ($operatorClass !== $lastOperatorClass) {
                $total++;
                $lastOperatorClass = $operatorClass;
            }
        }

        return $total;
    }

    /**
     * Flattens a nested boolean chain into a left-to-right list of operator classes for scoring.
     *
     * @param Expr               $expr   - Expression to flatten; recursion stops at the first non-boolean operand.
     * @param list<class-string> $result - Accumulator, appended in left-to-right order with each operator's class.
     *
     * @return void - Appends operator classes to $result; non-boolean expressions leave it unchanged.
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

        /** @var BinaryOp $expr - The boolean-operator guard narrows the expression before recursion. */
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
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
