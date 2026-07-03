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
 * Measures function-like branch count using cyclomatic complexity.
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
     * Describe the cyclomatic complexity rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
     * Find functions and methods whose cyclomatic complexity exceeds thresholds.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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

        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node NodeIndex query is constrained to function-like classes. */
            // User view: choose the findings list branch for this case.
            if (!self::hasExecutableBody($node)) {
                continue;
            }

            $ccn            = self::computeCyclomaticComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($ccn);

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_ $node - Function-like node whose control-flow constructs are counted.
     *
     * @return int - Cyclomatic complexity number.
     */
    public static function computeCyclomaticComplexity(Node $node): int
    {
        static $cyclomaticCache = null;
        // User view: choose the findings list branch for this case.
        if (!$cyclomaticCache instanceof \WeakMap) {
            $cyclomaticCache = new \WeakMap();
        }

        // User view: choose the findings list branch for this case.
        if (isset($cyclomaticCache[$node])) {
            $cached = $cyclomaticCache[$node];
            // User view: choose the findings list branch for this case.
            if (is_int($cached)) {
                // Reuse the memoised score so a node walked by several complexity rules is only counted once.
                return $cached;
            }
        }

        $ccn = 1;

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::bodyDescendants($node) as $child) {
            // User view: choose the findings list branch for this case.
            if (self::isDecisionNode($child)) {
                $ccn++;
            }

            // User view: choose the findings list branch for this case.
            if ($child instanceof Expr\Match_) {
                // User view: add each item that can appear in findings list.
                foreach ($child->arms as $arm) {
                    // User view: choose the findings list branch for this case.
                    // User view: missing data becomes the expected findings list state.
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
     * Check whether a node contributes one cyclomatic complexity point.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
            // User view: missing data becomes the expected findings list state.
            || ($child instanceof Stmt\Case_ && $child->cond !== null);
    }

    /**
     * Check whether a node is an instance of any configured class.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node                      $node - Node whose runtime class is tested against the candidates.
     * @param list<class-string<Node>> $classes - Candidate node classes.
     *
     * @return bool - True when the node matches one candidate class.
     */
    private static function isInstanceOfAny(Node $node, array $classes): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($classes as $class) {
            // User view: choose the findings list branch for this case.
            if ($node instanceof $class) {
                // First matching class is enough; the caller only needs membership, not which class matched.
                return true;
            }
        }

        return false;
    }

    /**
     * Build a display symbol for a function-like node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_ $node - Function-like node to describe.
     *
     * @return string - Function or method display symbol.
     */
    public static function resolveSymbol(ClassMethod|Function_ $node): string
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassMethod) {
            $parent    = $node->getAttribute('parent');
            $className = $parent instanceof Stmt\Class_
                || $parent instanceof Stmt\Trait_
                || $parent instanceof Stmt\Enum_
                // User view: missing data becomes a safe findings list default.
                ? ($parent->name?->toString() ?? 'class@anonymous')
                : null;

            // User view: missing data becomes the expected findings list state.
            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        return $node->name->toString() . '()';
    }

    /**
     * Whether a function-like node has an executable body to measure.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_ $node - Function-like node under inspection.
     *
     * @return bool - True when the node has a statement body the rule can measure.
     */
    public static function hasExecutableBody(ClassMethod|Function_ $node): bool
    {
        // A null statement list marks a bodyless declaration (abstract/interface), which has no control flow to score.
        // User view: missing data becomes the expected findings list state.
        return $node->stmts !== null;
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param int|float $number - Configured threshold to render; whole floats are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value.
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
