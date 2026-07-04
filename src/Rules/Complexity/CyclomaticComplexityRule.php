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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a function or method whose cyclomatic complexity - the number of independent branches through
 * it - climbs above the configured threshold, since more branches mean more paths to test and follow.
 *
 * Runs per file over every function-like node with a body, counting decision points (ifs, loops,
 * catches, short-circuit operators, match arms, non-default cases). Anything over the threshold (default
 * warning above 20) is reported; a well-structured flat guard-clause method is softened to advisory so
 * the shape is not over-penalised.
 */
final readonly class CyclomaticComplexityRule implements RuleInterface
{
    /**
     * Statement node classes that increase cyclomatic complexity by one.
     *
     * @var list<class-string<Node>>
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
     * @var list<class-string<Node>>
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
            confidence:        Confidence::High,
            severityThreshold: new SeverityThreshold(20, Severity::Warning),
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

        // Measure every function and method in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node NodeIndex query is constrained to function-like classes. */
            // An abstract or bodyless declaration has no branches to count.
            if (!self::hasExecutableBody($node)) {
                continue;
            }

            $ccn            = self::computeCyclomaticComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($ccn);

            // A count within the threshold is fine, so skip it.
            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = self::resolveSymbol($node);
            $isFlatGuardFlow = ComplexityShapeClassifier::isFlatGuardClauseFlow($node);
            $severity = $isFlatGuardFlow ? Severity::Advisory : $thresholdMatch->severity;

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
                remediation:      'Reduce branching by extracting conditions or splitting the method.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'complexity' => $ccn,
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
     * Computes the cyclomatic complexity of one function-like node, memoised so a shared node counts once.
     *
     * @param ClassMethod|Function_ $node - Function-like node whose control-flow constructs are counted.
     *
     * @return int - Cyclomatic complexity number.
     */
    public static function computeCyclomaticComplexity(Node $node): int
    {
        static $cyclomaticCache = null;
        // Lazily create the shared per-node cache on first use.
        if (!$cyclomaticCache instanceof \WeakMap) {
            $cyclomaticCache = new \WeakMap();
        }

        // Reuse a memoised score when this node was already counted.
        if (isset($cyclomaticCache[$node])) {
            $cached = $cyclomaticCache[$node];
            if (is_int($cached)) {
                // Reuse the memoised score so a node walked by several complexity rules is only counted once.
                return $cached;
            }
        }

        $ccn = 1;

        // Add a point for each decision node in the body.
        foreach (NodeIndex::bodyDescendants($node) as $child) {
            // Each branch statement or short-circuit operator adds one.
            if (self::isDecisionNode($child)) {
                $ccn++;
            }

            // A match adds one point per arm condition.
            if ($child instanceof Expr\Match_) {
                // Count each arm's conditions in turn.
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
     * Reports whether a node forks control flow and so adds one cyclomatic point.
     *
     * @param Node $child - Body descendant being classified as a decision point or not.
     *
     * @return bool - True when the node is counted as a decision point.
     */
    private static function isDecisionNode(Node $child): bool
    {
        // Branch statements and short-circuit operators each fork control flow; a `default` case (cond null) does not.
        return self::isInstanceOfAny($child, self::BRANCH_STATEMENT_TYPES)
            || self::isInstanceOfAny($child, self::BRANCH_EXPRESSION_TYPES)
            || ($child instanceof Stmt\Case_ && $child->cond !== null);
    }

    /**
     * Reports whether a node is an instance of any of the given classes.
     *
     * @param Node                      $node - Node whose runtime class is tested against the candidates.
     * @param list<class-string<Node>> $classes - Candidate node classes.
     *
     * @return bool - True when the node matches one candidate class.
     */
    private static function isInstanceOfAny(Node $node, array $classes): bool
    {
        // Check the node against each candidate class.
        foreach ($classes as $class) {
            if ($node instanceof $class) {
                // First matching class is enough; the caller only needs membership, not which class matched.
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
        // A method is qualified by its owning class name.
        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Stmt\Class_
                || $parent instanceof Stmt\Trait_
                || $parent instanceof Stmt\Enum_
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            // Qualify with the class name when known, otherwise use the bare method name.
            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        return $node->name->toString() . '()';
    }

    /**
     * Reports whether a function-like node has a real body to measure (abstract/interface methods do not).
     *
     * Abstract methods, interface methods, and other bodyless signatures parse as
     * a {@see ClassMethod} with `stmts === null`: they declare a contract but
     * contain no control flow. The executable-complexity rules (cyclomatic,
     * cognitive, nesting depth) skip them so they never score a type-shaped
     * declaration as if it had branches to simplify (DESIGN-PRINCIPLES P6),
     * replacing the previous reliance on "no body folds to baseline complexity".
     * A free {@see Function_} always carries a body, so this only ever filters
     * bodyless methods.
     *
     * @param ClassMethod|Function_ $node - Function-like node under inspection.
     *
     * @return bool - True when the node has a statement body the rule can measure.
     */
    public static function hasExecutableBody(ClassMethod|Function_ $node): bool
    {
        // A null statement list marks a bodyless declaration (abstract/interface), which has no control flow to score.
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
        // A genuine fraction keeps its decimals; a whole value is shown without them.
        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
