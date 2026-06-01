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
     * @return RuleDefinition Rule metadata and thresholds.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context carrying thresholds.
     * @return list<Finding> Findings for complex function-like declarations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node NodeIndex query is constrained to function-like classes. */
            if (!self::hasExecutableBody($node)) {
                continue;
            }

            $ccn            = self::computeCyclomaticComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($ccn);

            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = self::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: sprintf(
                    '%s has a cyclomatic complexity of %d, above the %s threshold of %s.',
                    $symbol,
                    $ccn,
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
                remediation:      'Reduce branching by extracting conditions or splitting the method.',
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'complexity' => $ccn,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $thresholdMatch->severity->value,
                ],
            );
        }

        // Hand back one finding per function-like that breached its threshold; empty when all stay under.
        return $findings;
    }

    /**
     * @param ClassMethod|Function_ $node
     *
     * @return int Cyclomatic complexity number.
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
                    if ($arm->conds !== null) {
                        $ccn += count($arm->conds);
                    }
                }
            }
        }

        $cyclomaticCache[$node] = $ccn;

        // One baseline path plus every decision point reached; match arms add one per extra condition.
        return $ccn;
    }

    /**
     * Check whether a node contributes one cyclomatic complexity point.
     *
     * @param Node $child Body descendant being classified as a decision point or not.
     * @return bool True when the node is counted as a decision point.
     */
    private static function isDecisionNode(Node $child): bool
    {
        // Branch statements and short-circuit operators each fork control flow; a `default` case (cond null) does not.
        return self::isInstanceOfAny($child, self::BRANCH_STATEMENT_TYPES)
            || self::isInstanceOfAny($child, self::BRANCH_EXPRESSION_TYPES)
            || ($child instanceof Stmt\Case_ && $child->cond !== null);
    }

    /**
     * Check whether a node is an instance of any configured class.
     *
     * @param Node                      $node    Node whose runtime class is tested against the candidates.
     * @param list<class-string<Node>> $classes Candidate node classes.
     *
     * @return bool True when the node matches one candidate class.
     */
    private static function isInstanceOfAny(Node $node, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($node instanceof $class) {
                // First matching class is enough; the caller only needs membership, not which class matched.
                return true;
            }
        }

        // Node is none of the candidate classes.
        return false;
    }

    /**
     * Build a display symbol for a function-like node.
     *
     * @param ClassMethod|Function_ $node Function-like node to describe.
     * @return string Function or method display symbol.
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

            // Qualify as Class::method() when an owning class is known; fall back to bare method() otherwise.
            return $className !== null
                ? sprintf('%s::%s()', $className, $node->name->toString())
                : $node->name->toString() . '()';
        }

        // A free function has no owning class, so its symbol is just the function name.
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
     * @param ClassMethod|Function_ $node Function-like node under inspection.
     * @return bool True when the node has a statement body the rule can measure.
     */
    public static function hasExecutableBody(ClassMethod|Function_ $node): bool
    {
        // A null statement list marks a bodyless declaration (abstract/interface), which has no control flow to score.
        return $node->stmts !== null;
    }

    /**
     * Format threshold numbers without unnecessary decimal places.
     *
     * @param int|float $number Configured threshold to render; whole floats are shown without a trailing decimal.
     * @return string Human-readable threshold value.
     */
    private static function formatNumber(int|float $number): string
    {
        if (is_float($number) && floor($number) !== $number) {
            // A real fractional threshold keeps its decimals so the message stays faithful.
            return (string) $number;
        }

        // Integers and whole-valued floats render without a trailing `.0` for a cleaner message.
        return (string) (int) $number;
    }
}
