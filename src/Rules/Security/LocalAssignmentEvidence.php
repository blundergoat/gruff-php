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
 * Sink rules use it to stay quiet on values a user cannot influence, such as `$id = (int) $_POST['id']` reaching header().
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
     * @var list<string>
     */
    private const OTHER_SUPERGLOBALS = ['GLOBALS', '_ENV', '_SESSION'];

    /**
     * Calls that can create or overwrite any local by name, such as `extract($_POST)`.
     *
     * @var list<string>
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

            // Other locals in the same function do not matter.
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
     * Reports whether a function calls something that can write locals by name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file to search.
     * @param FunctionLike $owningFunction - Function whose body is checked, closures inside it included.
     *
     * @return bool - True for extract(), parse_str(), mb_parse_str(), eval() or an include inside the function.
     */
    private static function hasByNameWriter(AnalysisUnit $analysisUnit, FunctionLike $owningFunction): bool
    {
        // eval() and include run code the scan never sees; the listed calls write locals named by their data.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [Expr\FuncCall::class, Expr\Eval_::class, Expr\Include_::class]) as $candidate) {
            $writesByName = !$candidate instanceof Expr\FuncCall
                || in_array(SecurityNodeHelper::globalFunctionName($candidate), self::BY_NAME_WRITERS, true);
            // One by-name writer anywhere in the function can set the local to anything.
            if ($writesByName && self::isWithin($candidate, $owningFunction)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether an occurrence of a local writes it in a way the scan cannot judge by value.
     *
     * @param Expr\Variable $occurrence - Occurrence of the local.
     * @param mixed         $parent - The occurrence's parent node; null at the top of a file.
     *
     * @return bool - True for a compound, by-reference, loop, destructuring, global, static, catch, use or parameter binding.
     */
    private static function isHiddenWrite(Expr\Variable $occurrence, mixed $parent): bool
    {
        // `$alias = &$id` lets a later `$alias = $_GET['x']` change the local, whichever side it is on.
        if ($parent instanceof Expr\AssignRef) {
            return true;
        }

        // `$id .= $_GET['x']` appends text to an integer.
        if ($parent instanceof Expr\AssignOp) {
            return $parent->var === $occurrence;
        }

        // `foreach ($_GET['ids'] as $id)` binds the local to each element.
        if ($parent instanceof Stmt\Foreach_) {
            return $parent->keyVar === $occurrence || $parent->valueVar === $occurrence;
        }

        // `[&$id]` aliases the local, and `[$id] = $_GET['pair']` binds it to an element.
        if ($parent instanceof Node\ArrayItem) {
            return $parent->byRef || self::isDestructuringTarget($parent);
        }

        // A global, static, catch, closure use or parameter binding brings in a value from outside the function body.
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
            // The list is the left side of an assignment, so the local receives an element.
            if (($parent instanceof Expr\Assign || $parent instanceof Expr\AssignRef) && $parent->var === $container) {
                return true;
            }

            // The list is a foreach binding, so the local receives part of each element.
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
     * @return bool - True when the function encloses the node; false for code in another function or at file scope.
     */
    private static function isWithin(Node $node, FunctionLike $owningFunction): bool
    {
        $ancestor = $node->getAttribute('parent');
        // Climb until the function is found or the top of the file is reached.
        while ($ancestor instanceof Node) {
            // Found the owning function among the ancestors.
            if ($ancestor === $owningFunction) {
                return true;
            }

            $ancestor = $ancestor->getAttribute('parent');
        }

        return false;
    }
}
