<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;

/**
 * Proves that a local variable reaching a sink can only hold values its own function assigned in plain sight.
 *
 * Sink rules suppress findings for accepted forms, such as `$id = (int) $_POST['id']` reaching header().
 * Every write in the function, closures included, must be a plain assignment the rule accepts, with one before the sink.
 *
 * Any write the scan cannot follow refuses the proof, so the finding stays:
 * - a compound, by-reference, loop or destructuring write;
 * - a parameter, global, static, catch or closure-use binding;
 * - a variable-variable, extract(), parse_str(), eval() or an include.
 */
final class LocalAssignmentEvidence
{
    /**
     * Superglobals a local name must not shadow, beyond the request-input set.
     *
     * @var list<string> - Additional names excluded from local-variable proofs.
     */
    private const OTHER_SUPERGLOBALS = ['GLOBALS', '_ENV', '_SESSION'];

    /**
     * Calls that can create or overwrite any local by name, such as `extract($_POST)`.
     *
     * @var list<string> - Global function names that invalidate local-assignment proofs.
     */
    private const BY_NAME_WRITERS = ['extract', 'parse_str', 'mb_parse_str'];

    /**
     * Reports whether a sink operand is a local that only ever holds values the calling rule accepts.
     *
     * @param AnalysisUnit         $analysisUnit - Parsed file searched for the local's writes.
     * @param Node                 $sinkOperand - Part of the sink's value, such as `$id` in `header('X-Id: ' . $id)`.
     * @param callable(Expr): bool $acceptsValue - Whether one assigned value is safe for this sink.
     * @param bool                 $mayPassToCalls - Whether the local may be passed to calls; a by-reference callee could write it unseen.
     *
     * @return bool - True when an accepted assignment comes before the sink and nothing else writes the local; false keeps the finding.
     */
    public static function onlyEverHolds(AnalysisUnit $analysisUnit, Node $sinkOperand, callable $acceptsValue, bool $mayPassToCalls): bool
    {
        $owningFunction = SecurityNodeHelper::enclosingFunctionLike($sinkOperand);
        $isPlainLocal   = $sinkOperand instanceof Expr\Variable && is_string($sinkOperand->name) && $sinkOperand->name !== 'this'
            && !in_array($sinkOperand->name, [...SecurityNodeHelper::userInputSuperglobals(), ...self::OTHER_SUPERGLOBALS], true);
        // File-scope code can be reached from anywhere, and extract() or an include can set any local, so neither proves anything.
        if (!$isPlainLocal || !$owningFunction instanceof FunctionLike || self::hasByNameWriter($analysisUnit, $owningFunction)) {
            return false;
        }

        $assignedBeforeSink = false;
        // Every occurrence of the name in the function decides, including ones after the sink that a loop could run first.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Variable::class) as $occurrence) {
            // A variable in another function is a different local.
            if (!self::isWithin($occurrence, $owningFunction)) {
                continue;
            }

            // A variable-variable such as `$$name = $_GET['x']` could write this local too.
            if (!is_string($occurrence->name)) {
                return false;
            }


            if ($occurrence->name !== $sinkOperand->name) {
                continue;
            }

            $parent = $occurrence->getAttribute('parent');
            // A plain assignment is the only write the rule can judge, by its assigned value.
            if ($parent instanceof Expr\Assign && $parent->var === $occurrence) {
                // One unaccepted value, in any branch or loop pass, keeps the finding.
                if (!$acceptsValue($parent->expr)) {
                    return false;
                }

                $assignedBeforeSink = $assignedBeforeSink
                    || (SecurityNodeHelper::enclosingFunctionLike($parent) === $owningFunction && $parent->getEndFilePos() < $sinkOperand->getStartFilePos());
                continue;
            }

            // Any other write, or a call that may take the local by reference, could put a value the rule never saw.
            if (self::isHiddenWrite($occurrence, $parent) || (!$mayPassToCalls && $parent instanceof Node\Arg)) {
                return false;
            }
        }

        return $assignedBeforeSink;
    }

    /**
     * Reports whether a function subtree contains syntax treated as capable of writing locals by name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file to search.
     * @param FunctionLike $owningFunction - Function whose body is checked, closures inside it included.
     *
     * @return bool - True when a listed by-name call, eval expression, or include expression appears in the scanned function subtree.
     */
    private static function hasByNameWriter(AnalysisUnit $analysisUnit, FunctionLike $owningFunction): bool
    {
        // eval() and include hide writes from this scan; the listed calls are conservatively treated as by-name writers.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [Expr\FuncCall::class, Expr\Eval_::class, Expr\Include_::class]) as $candidate) {
            $writesByName = !$candidate instanceof Expr\FuncCall
                || in_array(SecurityNodeHelper::globalFunctionName($candidate), self::BY_NAME_WRITERS, true);
            // One candidate anywhere in the scanned subtree is enough to stop the local-value proof.
            if ($writesByName && self::isWithin($candidate, $owningFunction)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a local occurrence invalidates the scan's plain-assignment evidence.
     *
     * @param Expr\Variable $occurrence - Occurrence of the local.
     * @param mixed         $parent - Parent node used to distinguish assignments, bindings, and ordinary reads.
     *
     * @return bool - True for non-plain assignments and bindings that invalidate the caller's local-value evidence.
     */
    private static function isHiddenWrite(Expr\Variable $occurrence, mixed $parent): bool
    {
        // $alias = &$id lets a later $alias = $_GET['x'] change the local, whichever side it is on.
        if ($parent instanceof Expr\AssignRef) {
            return true;
        }

        // Compound assignments change the local outside the plain-assignment sequence this scan accepts.
        if ($parent instanceof Expr\AssignOp) {
            return $parent->var === $occurrence;
        }

        // foreach ($_GET['ids'] as $id) binds the local to each element.
        if ($parent instanceof Stmt\Foreach_) {
            return $parent->keyVar === $occurrence || $parent->valueVar === $occurrence;
        }

        // [&$id] aliases the local, and [$id] = $_GET['pair'] binds it to an element.
        if ($parent instanceof Node\ArrayItem) {
            return $parent->byRef || self::isDestructuringTarget($parent);
        }

        // These bindings are initialized or captured outside the plain-assignment path accepted by this evidence check.
        return $parent instanceof Stmt\Global_ || $parent instanceof Node\StaticVar || $parent instanceof Stmt\Catch_
            || $parent instanceof Node\ClosureUse || $parent instanceof Node\Param;
    }

    /**
     * Reports whether an array item sits in a list that is being assigned to, rather than an array being read.
     *
     * @param Node\ArrayItem $arrayItem - Array entry holding the local.
     *
     * @return bool - True for `[$id] = ...` or `foreach (... as [$id])`; false for `['id' => $id]` built from the local.
     */
    private static function isDestructuringTarget(Node\ArrayItem $arrayItem): bool
    {
        $container = $arrayItem->getAttribute('parent');
        // Nested destructuring such as `[[$id]] = ...` climbs through the inner lists to the outermost one.
        while ($container instanceof Expr\Array_ || $container instanceof Expr\List_ || $container instanceof Node\ArrayItem) {
            $parent = $container->getAttribute('parent');

            if (($parent instanceof Expr\Assign || $parent instanceof Expr\AssignRef) && $parent->var === $container) {
                return true;
            }


            if ($parent instanceof Stmt\Foreach_ && ($parent->valueVar === $container || $parent->keyVar === $container)) {
                return true;
            }

            $container = $parent;
        }

        return false;
    }

    /**
     * Reports whether a node sits inside a function, closures nested in it included.
     *
     * @param Node         $node - Node to place.
     * @param FunctionLike $owningFunction - Function to look for among the node's ancestors.
     *
     * @return bool - True when the given function is an ancestor; false for nodes outside its subtree.
     */
    private static function isWithin(Node $node, FunctionLike $owningFunction): bool
    {
        $ancestor = $node->getAttribute('parent');

        while ($ancestor instanceof Node) {

            if ($ancestor === $owningFunction) {
                return true;
            }

            $ancestor = $ancestor->getAttribute('parent');
        }

        return false;
    }
}
