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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a function or method whose control-flow decision count climbs above the configured threshold.
 *
 * Runs per file over every function-like node with a body, counting ifs, loops, catches and short-circuit
 * operators; a switch or match with non-default choices contributes one dispatch decision.
 *
 * Findings below one and a half times the active limit are advisory; the upper band keeps the configured
 * severity, except that a flat guard-clause method stays advisory.
 */
final readonly class CyclomaticComplexityRule implements RuleInterface
{
    /**
     * Statement node classes that increase cyclomatic complexity by one.
     *
     * @var list<class-string<Node>> - Statement kinds that each add one point to the shared score.
     */
    private const BRANCH_STATEMENT_TYPES = [
        Stmt\If_::class,
        Stmt\ElseIf_::class,
        Stmt\For_::class,
        Stmt\Foreach_::class,
        Stmt\While_::class,
        Stmt\Do_::class,
        Stmt\Catch_::class,
    ];

    /**
     * Expression node classes that increase cyclomatic complexity by one.
     *
     * @var list<class-string<Node>> - Expression kinds that each add one point to the shared score.
     */
    private const BRANCH_EXPRESSION_TYPES = [
        BinaryOp\BooleanAnd::class,
        BinaryOp\BooleanOr::class,
        BinaryOp\LogicalAnd::class,
        BinaryOp\LogicalOr::class,
        BinaryOp\LogicalXor::class,
        Expr\Ternary::class,
        BinaryOp\Coalesce::class,
    ];

    /**
     * Stable rule identifier for cyclomatic complexity findings.
     */
    public const ID = 'complexity.cyclomatic';

    /**
     * Describes the cyclomatic-complexity rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Default threshold of 20 is the CCN above which a function-like is flagged; .gruff-php.yaml can override it.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Cyclomatic complexity',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Warning,
            confidence:        Confidence::Medium,
            severityThreshold: new SeverityThreshold(20, Severity::Warning),
            falsePositiveShapes: [
                [
                    'shape'      => 'A sequence of independent `if` checks for options, fallbacks, validation, or cleanup can score high because each check and any enclosing loop adds a decision.',
                    'mitigation' => 'Keep each check beside the action or diagnostic it controls so each path can be verified in execution order.',
                ],
                [
                    'shape'      => 'A validator can exceed the cyclomatic complexity threshold through a series of independent `if` checks, each with a clear diagnostic.',
                    'mitigation' => 'For adjacent checks that use the same lower-bound comparison, put each value, minimum, and diagnostic in an ordered array and apply the comparison in one `foreach` loop.',
                ],
            ],
        );
    }

    /**
     * Reports each function-like node whose cyclomatic complexity exceeds the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying thresholds.
     *
     * @return list<Finding> - Findings for complex function-like declarations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node - NodeIndex query is constrained to function-like classes. */
            // An abstract or bodyless declaration has no branches to count.
            if (!self::hasExecutableBody($node)) {
                continue;
            }

            $ccn            = self::dispatchOnceComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($ccn);


            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = self::resolveSymbol($node);
            $isFlatGuardFlow = ComplexityShapeClassifier::isFlatGuardClauseFlow($node);
            $band = LimitBand::of($ccn, $settings->lowestHighValueThreshold());
            $severity = LimitBand::severity($band, $isFlatGuardFlow ? Severity::Advisory : $thresholdMatch->severity);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: $isFlatGuardFlow
                    ? sprintf(
                        '%s has a cyclomatic complexity of %d from flat guard clauses, above the %s threshold of %s.',
                        $symbol,
                        $ccn,
                        $thresholdMatch->severity->value,
                        self::formatNumber($thresholdMatch->threshold),
                    )
                    : sprintf(
                        '%s has a cyclomatic complexity of %d, above the %s threshold of %s.',
                        $symbol,
                        $ccn,
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
                    'complexity' => $ccn,
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
     * Counts cyclomatic decisions with one point per switch or match that has non-default choices.
     * Flat dispatches contribute one decision; the maintainability index uses computeCyclomaticComplexity
     * to retain one point per non-default case or match-arm condition.
     *
     * @param ClassMethod|Function_ $node - Function-like node whose decisions are counted.
     *
     * @return int - Complexity score; default-only dispatches add no points.
     */
    private static function dispatchOnceComplexity(Node $node): int
    {
        $ccn = self::computeCyclomaticComplexity($node);

        // Collapse multiple non-default choices to one decision; default-only dispatches contribute none.
        foreach (NodeIndex::bodyDescendants($node) as $child) {
            // A switch's non-default cases were each counted once.
            if ($child instanceof Stmt\Switch_) {
                $cases = count(array_filter($child->cases, static fn (Stmt\Case_ $case): bool => $case->cond !== null));
                $ccn  -= max($cases - 1, 0);
            }

            // A match's arm conditions were each counted once.
            if ($child instanceof Expr\Match_) {
                $conditions = 0;
                // The default arm has no conditions.
                foreach ($child->arms as $arm) {
                    $conditions += $arm->conds === null ? 0 : count($arm->conds);
                }

                $ccn -= max($conditions - 1, 0);
            }
        }

        return $ccn;
    }

    /**
     * Computes the cyclomatic complexity of one function-like node, memoised so a shared node counts once.
     *
     * @param ClassMethod|Function_ $node - Function-like node whose control-flow constructs are counted.
     *
     * @return int - Cyclomatic complexity number.
     */
    public static function computeCyclomaticComplexity(Node $node): int
    {
        static $cyclomaticCache = null;

        if (!$cyclomaticCache instanceof \WeakMap) {
            $cyclomaticCache = new \WeakMap();
        }


        if (isset($cyclomaticCache[$node])) {
            $cached = $cyclomaticCache[$node];
            if (is_int($cached)) {
                // Reuse the memoised score so a node walked by several complexity rules is only counted once.
                return $cached;
            }
        }

        $ccn = 1;


        foreach (NodeIndex::bodyDescendants($node) as $child) {

            if (self::isDecisionNode($child)) {
                $ccn++;
            }


            if ($child instanceof Expr\Match_) {

                foreach ($child->arms as $arm) {
                    // The default arm has no conditions, so it adds nothing.
                    if ($arm->conds !== null) {
                        $ccn += count($arm->conds);
                    }
                }
            }
        }

        $cyclomaticCache[$node] = $ccn;

        return $ccn;
    }

    /**
     * Identifies the statement, expression and non-default case nodes counted as one decision.
     *
     * @param Node $child - Body descendant being classified as a decision point or not.
     *
     * @return bool - True when the node is counted as a decision point.
     */
    private static function isDecisionNode(Node $child): bool
    {
        // A null case condition marks the default branch, which adds no decision.
        return self::isInstanceOfAny($child, self::BRANCH_STATEMENT_TYPES)
            || self::isInstanceOfAny($child, self::BRANCH_EXPRESSION_TYPES)
            || ($child instanceof Stmt\Case_ && $child->cond !== null);
    }

    /**
     * Reports whether a node is an instance of any of the given classes.
     *
     * @param Node                      $node - Node whose runtime class is tested against the candidates.
     * @param list<class-string<Node>> $classes - Classes checked in order until one matches the node.
     *
     * @return bool - True when the node matches one candidate class.
     */
    private static function isInstanceOfAny(Node $node, array $classes): bool
    {

        foreach ($classes as $class) {
            if ($node instanceof $class) {

                return true;
            }
        }

        return false;
    }

    /**
     * Builds the display symbol for a function-like node (Class::method() or function()).
     *
     * @param ClassMethod|Function_ $node - Function-like node to describe.
     *
     * @return string - Function or method display symbol.
     */
    public static function resolveSymbol(ClassMethod|Function_ $node): string
    {

        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Stmt\Class_
                || $parent instanceof Stmt\Trait_
                || $parent instanceof Stmt\Enum_
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;


            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        return $node->name->toString() . '()';
    }

    /**
     * Reports whether a function or method has a statement body for complexity measurement.
     * A null statement list means a bodyless method; an empty list is still an executable body.
     *
     * @param ClassMethod|Function_ $node - Function-like node under inspection.
     *
     * @return bool - True when the node has a statement body the rule can measure.
     */
    public static function hasExecutableBody(ClassMethod|Function_ $node): bool
    {

        return $node->stmts !== null;
    }

    /**
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Configured threshold to render; whole floats are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value.
     */
    private static function formatNumber(int|float $number): string
    {

        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
