<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Carries existing callable evidence to the exact invocation a developer asks Gruff to review.
 *
 * Local copies and PHP's resolved Closure binder qualify only while their source remains proved.
 * Ambiguous writes, borrowed references and unknown receivers keep the dynamic-call warning visible.
 */
final class CallableFlowEvidence
{
    /**
     * Stops cyclic or long alias chains from turning a bounded scan into general data-flow analysis.
     */
    private const MAX_ALIAS_DEPTH = 8;

    /**
     * Checks additional local evidence after the rule's existing invocation checks have failed.
     *
     * @param Expr\FuncCall       $call             - Actual dynamic invocation; named calls and missing bodies receive no extra trust.
     * @param Closure(Expr): bool $declaredCallable - Existing rule proof for a selected declaration or literal.
     * @return bool - True only when the invocation's current value has bounded callable evidence.
     */
    public static function hasInvocationEvidence(Expr\FuncCall $call, Closure $declaredCallable): bool
    {
        $scope = SecurityNodeHelper::enclosingFunctionLike($call);
        // File-level or unavailable bodies have no local sequence from which to prove an alias.
        if (!$call->name instanceof Expr || $scope === null || $scope->getStmts() === null) {
            return false;
        }

        // Jumps or runtime-created locals can bypass the assignment sequence this bounded proof relies on.
        if (self::hasUnboundedLocalWrites($scope)) {
            return false;
        }

        return self::hasValueEvidence($call->name, $call, $scope, $declaredCallable, 0);
    }

    /**
     * Confirms a registered value is an untouched native callable parameter in its own method.
     *
     * @param Expr $registeredCallback - Appended value; aliases, untyped values and request expressions remain unresolved.
     * @param Node $registration       - Store position whose method owns the parameter.
     * @return bool - True only while no observable write or borrowed reference can replace that parameter.
     */
    public static function hasRegisteredParameterEvidence(Expr $registeredCallback, Node $registration): bool
    {
        $scope = SecurityNodeHelper::enclosingFunctionLike($registration);
        // Registration must use a written method parameter, not a captured or dynamically named value.
        if (!$scope instanceof Stmt\ClassMethod || !$registeredCallback instanceof Expr\Variable || !is_string($registeredCallback->name)
            || self::hasUnboundedLocalWrites($scope)) {
            return false;
        }

        return self::isCallableParameter($scope, $registeredCallback->name) && self::variableWrites($registeredCallback->name, $scope) === [];
    }

    /**
     * Keeps registration evidence inside the loop that selected the current callback.
     *
     * @param Expr\FuncCall $call - Actual invocation inside the selected loop.
     * @param Stmt\Foreach_ $loop - Collection iteration; reference iteration cannot establish new evidence.
     * @return bool - True when this loop is the local's only observable write or reference escape.
     */
    public static function hasUnchangedLoopValue(Expr\FuncCall $call, Stmt\Foreach_ $loop): bool
    {
        $scope = SecurityNodeHelper::enclosingFunctionLike($call);
        // Captures, computed names and reference iteration can disconnect the invoked value from this collection.
        if (!$scope instanceof Stmt\ClassMethod || SecurityNodeHelper::enclosingFunctionLike($loop) !== $scope
            || $loop->byRef || !$call->name instanceof Expr\Variable || !is_string($call->name->name)
            || !$loop->valueVar instanceof Expr\Variable || $loop->valueVar->name !== $call->name->name
            || self::hasUnboundedLocalWrites($scope)) {
            return false;
        }

        return self::variableWrites($call->name->name, $scope) === [$loop];
    }

    /**
     * Keeps a returned callback list tied to the one assignment that selected this loop.
     *
     * @param Stmt\Foreach_ $loop       - List iteration; an unbound or changed local keeps its warning.
     * @param Expr\Assign   $assignment - Source of that list, completed before the iteration.
     * @return bool - True when ordinary sequencing or an empty-result return guard makes the assignment unavoidable.
     */
    public static function hasStableCollectionAssignment(Stmt\Foreach_ $loop, Expr\Assign $assignment): bool
    {
        $scope = SecurityNodeHelper::enclosingFunctionLike($loop);
        // The developer must iterate the exact local that received this method's return value.
        if (!$scope instanceof Stmt\ClassMethod || !$loop->expr instanceof Expr\Variable || !is_string($loop->expr->name)
            || !$assignment->var instanceof Expr\Variable || $assignment->var->name !== $loop->expr->name
            || self::hasUnboundedLocalWrites($scope)
            || self::variableWrites($loop->expr->name, $scope) !== [$assignment]) {
            return false;
        }

        // A standalone assignment in the same branch runs before the selected loop.
        if (self::isBeforeReadOnSameBranch($assignment, $loop, $scope)) {
            return true;
        }

        return self::isEmptyResultGuardBeforeLoop($assignment, $loop);
    }

    /**
     * Accepts the one guard that exits before an empty callback result can reach the loop.
     *
     * @param Expr\Assign   $assignment - Result binding inside the guard condition.
     * @param Stmt\Foreach_ $loop       - Later callback iteration; another branch cannot borrow this guard.
     * @return bool - True when one shared body places the unconditional exit before the loop.
     */
    private static function isEmptyResultGuardBeforeLoop(Expr\Assign $assignment, Stmt\Foreach_ $loop): bool
    {
        $negation = $assignment->getAttribute('parent');
        $guard    = $negation instanceof Expr\BooleanNot ? $negation->getAttribute('parent') : null;
        // A failed, empty result exits before the loop; the condition always performs this assignment first.
        if (!$negation instanceof Expr\BooleanNot || $negation->expr !== $assignment
            || !$guard instanceof Stmt\If_ || $guard->cond !== $negation
            || count($guard->stmts) !== 1 || !$guard->stmts[0] instanceof Stmt\Return_
            || $guard->else !== null || $guard->elseifs !== []) {
            return false;
        }
        $body = $guard->getAttribute('parent');
        // Both statements must share one ordered body; an unrelated branch cannot supply this local.
        if (!$body instanceof Node || !property_exists($body, 'stmts') || !is_array($body->stmts)) {
            return false;
        }
        $guardPosition = array_search($guard, $body->stmts, true);
        $loopPosition  = array_search($loop, $body->stmts, true);

        return $guardPosition !== false && $loopPosition !== false && $guardPosition < $loopPosition;
    }

    /**
     * Rejects control flow or runtime imports that can replace locals without an ordinary assignment.
     *
     * @param FunctionLike $scope - Function being inspected; an empty body contains no hidden local writes.
     * @return bool - True means local provenance needs a broader analysis and must keep its warning.
     */
    private static function hasUnboundedLocalWrites(FunctionLike $scope): bool
    {
        return (new NodeFinder())->findFirst($scope->getStmts() ?? [], static function (Node $node) use ($scope): bool {
            // Other functions own their locals and cannot jump across this function's boundary.
            if (SecurityNodeHelper::enclosingFunctionLike($node) !== $scope) {
                return false;
            }
            // These constructs can skip the proved assignment or introduce replacement locals at runtime.
            if ($node instanceof Stmt\Goto_ || $node instanceof Expr\Eval_ || $node instanceof Expr\Include_) {
                return true;
            }

            return $node instanceof Expr\FuncCall && $node->name instanceof Name
                && strtolower($node->name->getLast()) === 'extract' && !$node->isFirstClassCallable();
        }) !== null;
    }

    /**
     * Follows one local value without inheriting trust from a later assignment or another receiver.
     *
     * @param Expr                $callbackValue    - Value used at this step; unknown expression forms keep their warning.
     * @param Node                $before           - Read position; only assignments completed before it can supply the value.
     * @param FunctionLike        $scope            - Owning function; captured values from other scopes are unresolved here.
     * @param Closure(Expr): bool $declaredCallable - Existing callable-policy proof for selected leaf values.
     * @param int                 $depth            - Followed alias count; reaching the bound leaves the invocation unresolved.
     * @return bool - Whether every supported source path preserves callable evidence.
     */
    private static function hasValueEvidence(Expr $callbackValue, Node $before, FunctionLike $scope, Closure $declaredCallable, int $depth): bool
    {
        // Excessive aliases or request-origin values need human review instead of an inferred exception.
        if ($depth >= self::MAX_ALIAS_DEPTH || SecurityNodeHelper::containsUserInput($callbackValue)) {
            return false;
        }

        // A written closure supplies its own target; its body retains the ordinary security-rule checks.
        if ($callbackValue instanceof Expr\Closure || $callbackValue instanceof Expr\ArrowFunction) {
            return true;
        }

        // Either non-null source of a fallback must already have callable evidence.
        if ($callbackValue instanceof Expr\BinaryOp\Coalesce) {
            return self::hasValueEvidence($callbackValue->left, $before, $scope, $declaredCallable, $depth + 1)
                && self::hasValueEvidence($callbackValue->right, $before, $scope, $declaredCallable, $depth + 1);
        }

        // Binding changes a proved closure's receiver; a same-named user class cannot claim PHP's guarantee.
        if ($callbackValue instanceof Expr\StaticCall && self::isBuiltinBinder($callbackValue)) {
            $arguments = $callbackValue->getArgs();
            return self::hasValueEvidence($arguments[0]->value, $before, $scope, $declaredCallable, $depth + 1);
        }

        // Only this object's declared property can supply the rule's existing property proof.
        if ($callbackValue instanceof Expr\PropertyFetch) {
            return $callbackValue->var instanceof Expr\Variable && $callbackValue->var->name === 'this' && $declaredCallable($callbackValue);
        }

        // Written first-class targets retain the existing rule's restrictions on dynamic dispatch.
        if ($callbackValue instanceof Expr\CallLike && $callbackValue->isFirstClassCallable()) {
            return $declaredCallable($callbackValue);
        }

        // Other returned values, dynamic names and collection selections remain outside this local proof.
        if (!$callbackValue instanceof Expr\Variable || !is_string($callbackValue->name)) {
            return false;
        }

        return self::hasLocalVariableEvidence($callbackValue, $before, $scope, $declaredCallable, $depth);
    }

    /**
     * Proves a copied local only while its earlier assignment remains valid at this read.
     *
     * @param Expr\Variable       $callbackValue    - Plain callback local; dynamic variable names remain unresolved.
     * @param Node                $before           - Read position used to exclude later or unfinished assignments.
     * @param FunctionLike        $scope            - Owning function; other scopes cannot supply new alias evidence.
     * @param Closure(Expr): bool $declaredCallable - Existing proof for a typed parameter or property.
     * @param int                 $depth            - Alias steps already followed; the shared bound prevents cycles.
     * @return bool - True when the local's current value is proved without ambiguous writes or references.
     */
    private static function hasLocalVariableEvidence(
        Expr\Variable $callbackValue,
        Node $before,
        FunctionLike $scope,
        Closure $declaredCallable,
        int $depth,
    ): bool
    {
        // Computed variable names cannot bind a specific declaration or assignment.
        if (!is_string($callbackValue->name)) {
            return false;
        }
        $writes = self::variableWrites($callbackValue->name, $scope);
        $latest = null;
        // Any possible write before the read must be understood; a later loop write may reach the next iteration.
        foreach ($writes as $write) {
            // A mutation after this read can still replace the callback before a subsequent loop iteration.
            if ($write->getStartFilePos() >= $before->getStartFilePos()) {
                // Shared loops make the later write observable at this source position.
                if (self::hasSharedLoop($write, $before, $scope)) {
                    return false;
                }
                continue;
            }
            // A borrowed reference can survive later assignments, so a fresh value does not restore local ownership.
            if (self::hasPersistentReference($write)) {
                return false;
            }
            // Retain the nearest earlier mutation, including conditional or unsupported writes.
            if ($latest === null || $write->getStartFilePos() > $latest->getStartFilePos()) {
                $latest = $write;
            }
        }

        // An untouched typed parameter is the only local name that needs no assignment evidence.
        if ($latest === null) {
            return self::isCallableParameter($scope, $callbackValue->name) && $declaredCallable($callbackValue);
        }

        // References, partial writes and branch-only assignments cannot establish a value on every path to this read.
        if (!$latest instanceof Expr\Assign || !$latest->var instanceof Expr\Variable || $latest->var->name !== $callbackValue->name
            || $latest->getEndFilePos() >= $before->getStartFilePos()
            || !self::isBeforeReadOnSameBranch($latest, $before, $scope)) {
            return false;
        }

        return self::hasValueEvidence($latest->expr, $latest, $scope, $declaredCallable, $depth + 1);
    }

    /**
     * Identifies writes whose borrowed reference may survive later assignments.
     *
     * @param Node $write - Earlier mutation or escape already matched to this local.
     * @return bool - True means a later assignment cannot restore sole ownership of the callback.
     */
    private static function hasPersistentReference(Node $write): bool
    {
        return $write instanceof Expr\AssignRef || $write instanceof Expr\Closure || $write instanceof Expr\CallLike
            || $write instanceof Stmt\Global_ || $write instanceof Node\ArrayItem || ($write instanceof Stmt\Foreach_ && $write->byRef);
    }

    /**
     * Finds mutations and calls that may borrow a local by reference.
     *
     * @param string       $name  - Case-sensitive local name being read.
     * @param FunctionLike $scope - Function whose writes matter; nested function bodies cannot replace its local.
     * @return list<Node> - Possible writes; an empty list leaves only a typed parameter as evidence.
     */
    private static function variableWrites(string $name, FunctionLike $scope): array
    {
        return array_values((new NodeFinder())->find($scope->getStmts() ?? [], static function (Node $node) use ($name, $scope): bool {
            // Nested functions own different locals, even when their spelling matches this callback.
            $owner = $node instanceof Expr\Closure ? $node->getAttribute('parent') : $node;
            if (!$owner instanceof Node || SecurityNodeHelper::enclosingFunctionLike($owner) !== $scope) {
                return false;
            }
            // Assignment references can change either side, so neither local keeps new flow evidence.
            if ($node instanceof Expr\AssignRef) {
                return self::hasVariableReference($node, $name);
            }
            // Ordinary writes replace the target; array-element writes can alter a callable array too.
            if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp
                || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc
                || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
                return self::hasVariableReference($node->var, $name);
            }
            // Unsetting or rebinding a loop/catch variable invalidates the previous callback.
            if ($node instanceof Stmt\Unset_ || $node instanceof Stmt\Global_) {
                return self::hasVariableReference($node, $name);
            }
            // A loop's value or key replaces that local even when its body contains no assignment.
            if ($node instanceof Stmt\Foreach_) {
                return self::hasVariableReference($node->valueVar, $name)
                    || ($node->keyVar !== null && self::hasVariableReference($node->keyVar, $name));
            }
            // Catch variables contain exceptions, not the callable that previously used their name.
            if ($node instanceof Stmt\Catch_) {
                return $node->var !== null && self::hasVariableReference($node->var, $name);
            }
            return self::hasReferenceEscape($node, $name);
        }));
    }

    /**
     * Detects a borrowed reference that may outlive an ordinary assignment.
     *
     * @param Node   $node - Same-scope closure creation or call to inspect.
     * @param string $name - Local callback whose ownership must remain known.
     * @return bool - True when another callable may retain or replace this local.
     */
    private static function hasReferenceEscape(Node $node, string $name): bool
    {
        // Array reference elements can replace the local after later ordinary assignments.
        if ($node instanceof Node\ArrayItem && $node->byRef) {
            return self::hasVariableReference($node->value, $name);
        }
        // A closure can retain a reference and replace the callback whenever it is invoked.
        if ($node instanceof Expr\Closure) {
            // By-value captures cannot replace the outer variable.
            foreach ($node->uses as $capture) {
                // This reference prevents later assignments from restoring local ownership.
                if ($capture->byRef && $capture->var->name === $name) {
                    return true;
                }
            }
        }
        // Unknown signatures may borrow arguments by reference; creating a first-class callable passes no arguments.
        if ($node instanceof Expr\CallLike && !$node->isFirstClassCallable()) {
            // Inspect arguments only: invoking this callback does not by itself reassign its variable.
            foreach ($node->getRawArgs() as $argument) {
                // Only real variable arguments may borrow this local; partial-call placeholders reserve future values.
                if ($argument instanceof Node\Arg && ($argument->value instanceof Expr\Variable || $argument->value instanceof Expr\ArrayDimFetch)
                    && self::hasVariableReference($argument->value, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks whether a candidate mutation touches a particular local.
     *
     * @param Node   $node - Assignment target or argument to inspect.
     * @param string $name - Local name; matching remains case-sensitive as PHP requires.
     * @return bool - True for this local or a computed variable that could resolve to it.
     */
    private static function hasVariableReference(Node $node, string $name): bool
    {
        return (new NodeFinder())->findFirst([$node], static fn (Node $child): bool =>
            $child instanceof Expr\Variable && (!is_string($child->name) || $child->name === $name)) !== null;
    }

    /**
     * Requires the assignment's statement to run on the same branch before the read.
     *
     * @param Expr\Assign  $assignment - Earlier assignment; assignments inside expressions are deliberately unresolved.
     * @param Node         $read       - Later read whose enclosing statement lists establish control-flow containment.
     * @param FunctionLike $scope      - Shared function boundary; crossing it would mix unrelated executions.
     * @return bool - True when every enclosing statement body also contains the read.
     */
    private static function isBeforeReadOnSameBranch(Expr\Assign $assignment, Node $read, FunctionLike $scope): bool
    {
        $child = $assignment->getAttribute('parent');
        // Only an ordinary completed assignment statement establishes the supported local sequence.
        if (!$child instanceof Stmt\Expression) {
            return false;
        }
        $parent = $child->getAttribute('parent');
        // Every nested body must be the same body that contains the read, including then/else and try/catch.
        while ($parent instanceof Node && $parent !== $scope) {
            $readChild = $read;
            // Find which direct child of this ancestor contains the read.
            while ($readChild instanceof Node && $readChild->getAttribute('parent') !== $parent) {
                $readChild = $readChild->getAttribute('parent');
            }
            // A missing or different branch leaves an execution path without the assignment.
            if (!property_exists($parent, 'stmts') || !is_array($parent->stmts)
                || !in_array($child, $parent->stmts, true) || !in_array($readChild, $parent->stmts, true)) {
                return false;
            }
            $child  = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return $parent === $scope;
    }

    /**
     * Detects a later mutation that can reach the same read on another loop iteration.
     *
     * @param Node         $write - Mutation after the read in source order.
     * @param Node         $read  - Read whose enclosing loops are examined.
     * @param FunctionLike $scope - Local function boundary; outer scopes do not participate.
     * @return bool - True for a shared enclosing loop; false means the later write cannot invalidate this read here.
     */
    private static function hasSharedLoop(Node $write, Node $read, FunctionLike $scope): bool
    {
        $parent = $read->getAttribute('parent');
        // Check only loops surrounding this invocation.
        while ($parent instanceof Node && $parent !== $scope) {
            // Repeated bodies can carry a later write back to this invocation.
            if ($parent instanceof Stmt\For_ || $parent instanceof Stmt\Foreach_ || $parent instanceof Stmt\While_ || $parent instanceof Stmt\Do_) {
                $ancestor = $write;
                // A write belongs to the loop only when its parent chain reaches that exact loop node.
                while ($ancestor instanceof Node && $ancestor !== $scope) {
                    // The shared loop can execute this write before the next read.
                    if ($ancestor === $parent) {
                        return true;
                    }
                    $ancestor = $ancestor->getAttribute('parent');
                }
            }
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Resolves PHP's binder and its supported positional arguments.
     *
     * @param Expr $callbackValue - Expression to inspect; named/unpacked arguments and unresolved class bindings grant no evidence.
     * @return bool - True only for a call to the built-in Closure::bind with two or three ordinary arguments.
     */
    private static function isBuiltinBinder(Expr $callbackValue): bool
    {
        // A first-class method reference creates a callback; it does not bind its first argument.
        if (!$callbackValue instanceof Expr\StaticCall || $callbackValue->isFirstClassCallable()
            || !$callbackValue->class instanceof Name || !$callbackValue->name instanceof Identifier
            || strtolower($callbackValue->name->toString()) !== 'bind') {
            return false;
        }
        $resolved  = $callbackValue->class->getAttribute('resolvedName', $callbackValue->class);
        $arguments = $callbackValue->getRawArgs();
        // Exact resolution prevents a user-defined namespaced Closure class from acquiring built-in trust.
        if (!$resolved instanceof Name || strtolower($resolved->toString()) !== 'closure' || count($arguments) < 2 || count($arguments) > 3) {
            return false;
        }
        // Positional arguments have a known callback slot; spreads and names require a separate resolver.
        foreach ($arguments as $argument) {
            // Unknown binding order cannot prove which value PHP binds.
            if (!$argument instanceof Node\Arg || $argument->name !== null || $argument->unpack) {
                return false;
            }
        }

        return true;
    }

    /**
     * Restricts new parameter aliases to written callable or resolved Closure declarations.
     *
     * @param FunctionLike $scope - Function declaring the parameter.
     * @param string       $name  - Local name to match; absent or untyped parameters grant no new evidence.
     * @return bool - True for the bounded callable type, including its nullable form.
     */
    private static function isCallableParameter(FunctionLike $scope, string $name): bool
    {
        // Each parameter is local to this function; a same-named outer parameter cannot supply its type.
        foreach ($scope->getParams() as $parameter) {
            // Only the exact local declaration can vouch for an untouched parameter.
            if ($parameter->byRef || !$parameter->var instanceof Expr\Variable || $parameter->var->name !== $name) {
                continue;
            }
            $type = $parameter->type;
            // Nullable declarations still prove the non-null callable target under the existing rule policy.
            if ($type instanceof Node\NullableType) {
                $type = $type->type;
            }
            $resolved = $type instanceof Name ? $type->getAttribute('resolvedName', $type) : $type;
            return ($resolved instanceof Identifier && strtolower($resolved->toString()) === 'callable')
                || ($resolved instanceof Name && strtolower($resolved->toString()) === 'closure');
        }

        return false;
    }
}
