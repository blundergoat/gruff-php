<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Shared reading of a function or method: the symbol a finding names, whether it has a body to measure,
 * and its full cyclomatic count.
 *
 * Documentation, naming and complexity rules use it to name the callable a finding points at; the
 * maintainability index reads the count, which no longer has a rule of its own.
 */
final class FunctionComplexity
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
}
