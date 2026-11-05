<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Checks callbacks selected through one visible return method before a developer invokes them.
 *
 * Only an empty, owned property filled by typed registration can supply a trusted list.
 * The return method must pass a bounded bucket or merge proof; unknown code keeps the warning.
 */
final class CallableCollectionReturnEvidence
{
    /**
     * Checks the returned collection behind the current, unchanged callback loop.
     *
     * @param Expr\FuncCall $call - Dynamic invocation; no selecting loop means no return evidence.
     * @return bool - True only when the call, local assignment, return and all property uses are proved.
     */
    public static function hasInvocationEvidence(Expr\FuncCall $call): bool
    {
        $parent = $call->getAttribute('parent');
        // A nested closure or method owns a different callback value.
        while ($parent instanceof Node && !$parent instanceof Node\FunctionLike) {
            // The invocation must use the exact loop value without a later write or borrowed reference.
            if ($parent instanceof Stmt\Foreach_ && CallableFlowEvidence::hasUnchangedLoopValue($call, $parent)) {
                return self::hasReturnedCollectionEvidence($parent);
            }
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Resolves one local assignment and one method in the same written declaration.
     *
     * @param Stmt\Foreach_ $loop - Iteration over a local result; other expressions keep their warning.
     * @return bool - True when either supported return form comes from a proved callback property.
     */
    private static function hasReturnedCollectionEvidence(Stmt\Foreach_ $loop): bool
    {
        $scope       = SecurityNodeHelper::enclosingFunctionLike($loop);
        $declaration = self::owningDeclaration($loop);
        // A local list must belong to a visible method and one unchanged assignment.
        if (!$scope instanceof Stmt\ClassMethod || $declaration === null) {
            return false;
        }
        $assignment = self::uniqueCollectionAssignment($loop, $scope);
        // No proved assignment means the returned collection may have another source.
        if ($assignment === null) {
            return false;
        }

        $returnedCall = $assignment->expr;
        // A foreign receiver or computed method name cannot be tied to this declaration's body.
        if (!$returnedCall instanceof Expr\MethodCall || !$returnedCall->var instanceof Expr\Variable
            || $returnedCall->var->name !== 'this' || !$returnedCall->name instanceof Identifier
            || $returnedCall->isFirstClassCallable()) {
            return false;
        }

        $method = self::declaredMethod($declaration, $returnedCall->name->toString());
        // Positional binding must identify every argument, including the one that carries a collection.
        if ($method === null || !self::hasPositionalArguments($returnedCall, $method)) {
            return false;
        }

        $bucketProperty = self::returnedBucketProperty($method);
        // An empty fallback may return one keyed bucket, with no extra statement that can replace it.
        if ($bucketProperty !== null) {
            return self::hasOnlyRegisteredPropertyUses($declaration, $bucketProperty);
        }

        $mergedProperty = self::mergedPropertyArgument($returnedCall, $method);
        // A merged result qualifies only when its source argument is the owned registered property.
        return $mergedProperty !== null && self::hasOnlyRegisteredPropertyUses($declaration, $mergedProperty);
    }

    /**
     * Finds the sole completed assignment supplying the callback loop.
     *
     * @param Stmt\Foreach_    $loop  - Iteration over a local; other expressions have no assignment to bind.
     * @param Stmt\ClassMethod $scope - Method whose local writes must remain known.
     * @return Expr\Assign|null - Stable assignment, or null for absent, repeated or unsafe writes.
     */
    private static function uniqueCollectionAssignment(Stmt\Foreach_ $loop, Stmt\ClassMethod $scope): ?Expr\Assign
    {
        // The developer must iterate a written local in this method.
        if (!$loop->expr instanceof Expr\Variable || !is_string($loop->expr->name) || $scope->stmts === null) {
            return null;
        }
        $assignments = (new NodeFinder())->find($scope->stmts, static fn (Node $node): bool =>
            $node instanceof Expr\Assign && $node->var instanceof Expr\Variable && $node->var->name === $loop->expr->name
            && SecurityNodeHelper::enclosingFunctionLike($node) === $scope);
        // More than one candidate or another kind of write makes the result ambiguous.
        if (count($assignments) !== 1 || !$assignments[0] instanceof Expr\Assign
            || !CallableFlowEvidence::hasStableCollectionAssignment($loop, $assignments[0])) {
            return null;
        }

        return $assignments[0];
    }

    /**
     * Finds the exact method written on this class or trait.
     *
     * @param Stmt\ClassLike $declaration - Owner of the invocation; inherited implementations are unavailable.
     * @param string         $methodName  - Called name; PHP compares method names without case.
     * @return Stmt\ClassMethod|null - One visible body, or null for missing and duplicate declarations.
     */
    private static function declaredMethod(Stmt\ClassLike $declaration, string $methodName): ?Stmt\ClassMethod
    {
        $match = null;
        // Only methods written on this declaration can justify suppressing the user's warning.
        foreach ($declaration->getMethods() as $method) {
            // A different method does not describe this call's return.
            if (strcasecmp($method->name->toString(), $methodName) !== 0) {
                continue;
            }
            // Duplicate spellings or a reference return can expose a bucket outside this proof.
            if ($match !== null || $method->stmts === null || $method->byRef) {
                return null;
            }
            $match = $method;
        }

        return $match;
    }

    /**
     * Binds each written parameter to one ordinary call argument.
     *
     * @param Expr\MethodCall  $call   - Exact call whose arguments the developer supplied.
     * @param Stmt\ClassMethod $method - Visible implementation; defaults, references and spreads grant no evidence.
     * @return bool - True when every parameter has one complete positional value.
     */
    private static function hasPositionalArguments(Expr\MethodCall $call, Stmt\ClassMethod $method): bool
    {
        $arguments = $call->getRawArgs();
        // Partial applications and missing values cannot identify the property argument.
        if (count($arguments) !== count($method->params)) {
            return false;
        }
        // A named, unpacked or reference argument can change which parameter receives the list.
        foreach ($arguments as $index => $argument) {
            $parameter = $method->params[$index];
            // Only ordinary by-value positions keep their source and order.
            if (!$argument instanceof Node\Arg || $argument->name !== null || $argument->unpack || $argument->byRef
                || $parameter->byRef || $parameter->variadic || !$parameter->var instanceof Expr\Variable
                || !is_string($parameter->var->name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recognizes a property bucket returned with an empty-list fallback.
     *
     * @param Stmt\ClassMethod $method - Visible method; an empty or extra body cannot prove this return.
     * @return Expr\PropertyFetch|null - Selected property read, or null for another return form.
     */
    private static function returnedBucketProperty(Stmt\ClassMethod $method): ?Expr\PropertyFetch
    {
        $statements = $method->stmts ?? [];
        // The actual returned expression must be the whole bucket or an empty list.
        if (count($statements) !== 1 || !$statements[0] instanceof Stmt\Return_
            || !$statements[0]->expr instanceof Expr\BinaryOp\Coalesce) {
            return null;
        }

        $fallback = $statements[0]->expr;
        $bucket   = $fallback->left;
        // An arbitrary fallback or nested selection could introduce an unproved callback.
        if (!self::isEmptyArray($fallback->right) || !$bucket instanceof Expr\ArrayDimFetch
            || !$bucket->var instanceof Expr\PropertyFetch || !$bucket->dim instanceof Expr\Variable
            || !is_string($bucket->dim->name)) {
            return null;
        }
        // The key must be an unchanged, bound parameter; its value only chooses among proved buckets.
        foreach ($method->params as $parameter) {
            // A different parameter does not select the bucket returned here.
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $bucket->dim->name) {
                return $bucket->var;
            }
        }

        return null;
    }

    /**
     * Recognizes the exact empty-result, selected-bucket, array_merge return form.
     *
     * @param Expr\MethodCall  $call   - Call binding the source array parameter to a property.
     * @param Stmt\ClassMethod $method - Visible method whose result the developer iterates.
     * @return Expr\PropertyFetch|null - Bound property argument, or null when any merge source is unknown.
     */
    private static function mergedPropertyArgument(Expr\MethodCall $call, Stmt\ClassMethod $method): ?Expr\PropertyFetch
    {
        $statements = $method->stmts ?? [];
        // An extra statement can replace a local or return another list.
        if (count($statements) !== 3 || !$statements[0] instanceof Stmt\Expression
            || !$statements[0]->expr instanceof Expr\Assign || !$statements[1] instanceof Stmt\Foreach_
            || !$statements[2] instanceof Stmt\Return_) {
            return null;
        }

        $selection  = $statements[1];
        $localNames = self::mergeLocalNames($statements[0]->expr, $selection, $statements[2]->expr);
        // Each named local must preserve its one role through selection and return.
        if ($localNames === null) {
            return null;
        }
        [$resultName, $sourceName, $keyName, $callbackName] = $localNames;

        $sourceIndex = self::arrayParameterIndex($method, $sourceName);
        // A method-local or untyped array source cannot inherit evidence from the call site.
        if ($sourceIndex === null || !self::hasMergeBody($selection, $method, $resultName, $keyName, $callbackName)) {
            return null;
        }

        $argument = $call->getRawArgs()[$sourceIndex];

        return $argument instanceof Node\Arg && $argument->value instanceof Expr\PropertyFetch ? $argument->value : null;
    }

    /**
     * Names the four distinct locals in the empty-result merge form.
     *
     * @param Expr\Assign   $initialization - Empty accumulator assignment.
     * @param Stmt\Foreach_ $selection      - Source and bucket locals.
     * @param Expr|null     $returned       - Value returned to the caller; absence keeps the warning.
     * @return array{string, string, string, string}|null - Result, source, key and callbacks, or null for an unsafe form.
     */
    private static function mergeLocalNames(Expr\Assign $initialization, Stmt\Foreach_ $selection, ?Expr $returned): ?array
    {
        // The accumulator starts empty and the final return must be that same plain local.
        if (!$initialization->var instanceof Expr\Variable || !is_string($initialization->var->name)
            || !self::isEmptyArray($initialization->expr)
            || !self::isVariable($returned, $initialization->var->name)
            || !$selection->expr instanceof Expr\Variable || !is_string($selection->expr->name)
            || !$selection->keyVar instanceof Expr\Variable || !is_string($selection->keyVar->name)
            || !$selection->valueVar instanceof Expr\Variable || !is_string($selection->valueVar->name)
            || $selection->byRef) {
            return null;
        }
        $names = [$initialization->var->name, $selection->expr->name, $selection->keyVar->name, $selection->valueVar->name];

        // Reusing a name could replace the source or returned callbacks during iteration.
        return count(array_unique($names)) === 4 ? $names : null;
    }

    /**
     * Finds the by-value array parameter that supplies all selected buckets.
     *
     * @param Stmt\ClassMethod $method - Resolved return method.
     * @param string           $name   - Foreach source name; absent or untyped names grant no evidence.
     * @return int|null - Positional parameter index, or null when the source is not a written array parameter.
     */
    private static function arrayParameterIndex(Stmt\ClassMethod $method, string $name): ?int
    {
        // The call's actual argument is checked only after this parameter is found.
        foreach ($method->params as $index => $parameter) {
            // Other arguments may choose buckets but cannot supply callback values.
            if (is_int($index) && $parameter->var instanceof Expr\Variable && $parameter->var->name === $name) {
                return $parameter->type instanceof Identifier && strtolower($parameter->type->toString()) === 'array' ? $index : null;
            }
        }

        return null;
    }

    /**
     * Proves the loop can only merge one selected callback bucket into the empty accumulator.
     *
     * @param Stmt\Foreach_    $selection    - Bucket iteration; by-reference selection remains unsupported.
     * @param Stmt\ClassMethod $method       - Resolved method used to check the built-in merge name.
     * @param string           $resultName   - Local returned to the developer's callback loop.
     * @param string           $keyName      - Selected bucket key.
     * @param string           $callbackName - Selected callback list.
     * @return bool - True for the one bounded merge body; mixed or replaced lists retain their warning.
     */
    private static function hasMergeBody(
        Stmt\Foreach_ $selection,
        Stmt\ClassMethod $method,
        string $resultName,
        string $keyName,
        string $callbackName,
    ): bool {
        // A condition may choose a bucket, but it cannot contribute another callback source.
        if (count($selection->stmts) !== 1 || !$selection->stmts[0] instanceof Stmt\If_
            || !self::isBucketCondition($selection->stmts[0]->cond, $method, $keyName)) {
            return false;
        }
        $branch = $selection->stmts[0];
        // Else paths or additional statements could replace the accumulator.
        if ($branch->else !== null || $branch->elseifs !== [] || count($branch->stmts) !== 1
            || !$branch->stmts[0] instanceof Stmt\Expression || !$branch->stmts[0]->expr instanceof Expr\Assign) {
            return false;
        }
        $assignment = $branch->stmts[0]->expr;
        $merge      = $assignment->expr;
        // The merge must write the same accumulator and call PHP's known array_merge function.
        if (!self::isVariable($assignment->var, $resultName) || !$merge instanceof Expr\FuncCall
            || !self::isBuiltinArrayMerge($merge, $method) || $merge->isFirstClassCallable()) {
            return false;
        }
        return self::hasExactMergeArguments($merge, $resultName, $callbackName);
    }

    /**
     * Keeps both merge operands tied to the selected list and prior result.
     *
     * @param Expr\FuncCall $merge        - Known built-in call whose arguments are inspected.
     * @param string        $resultName   - Existing result list; an absent or altered operand is unsafe.
     * @param string        $callbackName - Selected callback bucket; another value keeps the warning.
     * @return bool - True for two ordinary by-value positional local arguments.
     */
    private static function hasExactMergeArguments(Expr\FuncCall $merge, string $resultName, string $callbackName): bool
    {
        $arguments = $merge->getRawArgs();
        // Both sources must be intact plain locals: the current result and the selected callback bucket.
        return count($arguments) === 2 && $arguments[0] instanceof Node\Arg && $arguments[1] instanceof Node\Arg
            && !$arguments[0]->unpack && !$arguments[1]->unpack
            && $arguments[0]->name === null && $arguments[1]->name === null
            && !$arguments[0]->byRef && !$arguments[1]->byRef
            && self::isVariable($arguments[0]->value, $resultName)
            && self::isVariable($arguments[1]->value, $callbackName);
    }

    /**
     * Allows only the written key comparison and object-type test to choose buckets.
     *
     * @param Expr             $condition - If condition; calls and assignments can mutate the chosen list.
     * @param Stmt\ClassMethod $method    - Resolved method whose first two parameters describe this choice.
     * @param string           $keyName   - Current bucket key.
     * @return bool - True for the bounded selection used by the inspected declaration.
     */
    private static function isBucketCondition(Expr $condition, Stmt\ClassMethod $method, string $keyName): bool
    {
        $first  = $method->params[0]->var ?? null;
        $second = $method->params[1]->var ?? null;
        // A different condition may mutate the result or select callbacks from an unknown source.
        return $condition instanceof Expr\BinaryOp\BooleanOr
            && $condition->left instanceof Expr\BinaryOp\Identical
            && $condition->right instanceof Expr\Instanceof_
            && $first instanceof Expr\Variable && is_string($first->name)
            && $second instanceof Expr\Variable && is_string($second->name)
            && self::isVariable($condition->left->left, $keyName)
            && self::isVariable($condition->left->right, $first->name)
            && self::isVariable($condition->right->expr, $second->name)
            && self::isVariable($condition->right->class, $keyName);
    }

    /**
     * Distinguishes PHP's merge function from a local replacement with the same short name.
     *
     * @param Expr\FuncCall    $call   - Array merge expression; a dynamic name stays unproved.
     * @param Stmt\ClassMethod $method - Owning namespace checked for a written replacement function.
     * @return bool - True only for the ordinary built-in name in this visible file.
     */
    private static function isBuiltinArrayMerge(Expr\FuncCall $call, Stmt\ClassMethod $method): bool
    {
        $name = $call->name;
        // A qualified or aliased user function cannot borrow PHP's array_merge contract.
        if (!$name instanceof Name || strtolower($name->toString()) !== 'array_merge') {
            return false;
        }
        // An explicit global call is independent of local namespace functions.
        if ($name instanceof Name\FullyQualified) {
            return true;
        }
        $resolved = $name->getAttribute('resolvedName');
        // A use-function alias identifies another implementation, even when the call text looks built in.
        if ($resolved instanceof Name && strtolower($resolved->toString()) !== 'array_merge') {
            return false;
        }
        $namespace = $method->getAttribute('parent');
        // A function written in this namespace would intercept an unqualified call at runtime.
        while ($namespace instanceof Node && !$namespace instanceof Stmt\Namespace_) {
            $namespace = $namespace->getAttribute('parent');
        }
        // Global-source calls can use the built-in when no local namespace declaration exists.
        if (!$namespace instanceof Stmt\Namespace_) {
            return true;
        }
        // A visible replacement can also be declared inside a method before this call runs.
        return (new NodeFinder())->findFirst($namespace->stmts, static fn (Node $node): bool =>
            $node instanceof Stmt\Function_ && strcasecmp($node->name->toString(), 'array_merge') === 0) === null;
    }

    /**
     * Audits every visible use of one property before treating any stored value as callable.
     *
     * @param Stmt\ClassLike     $declaration - Written class or trait; other declarations are separate owners.
     * @param Expr\PropertyFetch $selected    - Exact property read supplying the returned collection.
     * @return bool - True for an initially empty non-public property with typed bucket appends only.
     */
    private static function hasOnlyRegisteredPropertyUses(Stmt\ClassLike $declaration, Expr\PropertyFetch $selected): bool
    {
        // Dynamic names, foreign receivers and public properties cannot establish owned callback storage.
        if (!$selected->name instanceof Identifier || !self::isThisProperty($selected)
            || !CallableCollectionEvidence::hasEmptyOwnedDefault($declaration, $selected)) {
            return false;
        }
        $propertyName = $selected->name->toString();
        $hasStore     = false;
        // Runtime imports can create writes that the scanned declaration cannot enumerate.
        if ((new NodeFinder())->findFirst($declaration->stmts, static fn (Node $node): bool =>
            self::owningDeclaration($node) === $declaration
            && ($node instanceof Expr\Eval_ || $node instanceof Expr\Include_)) !== null) {
            return false;
        }
        $uses = (new NodeFinder())->findInstanceOf($declaration->stmts, Expr\PropertyFetch::class);
        // Even a later write matters because this object can be used again after an earlier invocation.
        foreach ($uses as $use) {
            // A nested class can also hold an alias to this object, so a matching or computed property stays unproved.
            if (self::owningDeclaration($use) !== $declaration) {
                if (!$use->name instanceof Identifier || $use->name->toString() === $propertyName) {
                    return false;
                }
                continue;
            }
            // A computed name on any receiver might reach this object through an alias.
            if (!$use->name instanceof Identifier) {
                return false;
            }
            // Other named properties have no effect on this collection.
            if ($use->name->toString() !== $propertyName) {
                continue;
            }
            // The only supported read is the exact source selected by the returned method.
            if (!self::isThisProperty($use) || ($use !== $selected && !self::isTypedBucketAppend($use))) {
                return false;
            }
            // A typed append contributes one proved callback to any keyed bucket.
            if ($use !== $selected) {
                $hasStore = true;
            }
        }

        return $hasStore;
    }

    /**
     * Checks one indexed append for an untouched native callable parameter.
     *
     * @param Expr\PropertyFetch $property - Observed use; reads and reference writes keep the warning.
     * @return bool - True only for property[key][] assigned from a proved method parameter.
     */
    private static function isTypedBucketAppend(Expr\PropertyFetch $property): bool
    {
        $bucket     = $property->getAttribute('parent');
        $append     = $bucket instanceof Expr\ArrayDimFetch ? $bucket->getAttribute('parent') : null;
        $assignment = $append instanceof Expr\ArrayDimFetch ? $append->getAttribute('parent') : null;
        // A complete keyed append adds one value; replacing a bucket or borrowing it does not.
        return $bucket instanceof Expr\ArrayDimFetch && $bucket->var === $property && $bucket->dim instanceof Expr
            && $append instanceof Expr\ArrayDimFetch && $append->var === $bucket && $append->dim === null
            && $assignment instanceof Expr\Assign && $assignment->var === $append
            && CallableFlowEvidence::hasRegisteredParameterEvidence($assignment->expr, $assignment);
    }

    /**
     * Finds the closest written class or trait without borrowing another declaration's properties.
     *
     * @param Node $node - Invocation or property use whose owner the scanner needs.
     * @return Stmt\ClassLike|null - Visible declaration, or null when no owner can be proved.
     */
    private static function owningDeclaration(Node $node): ?Stmt\ClassLike
    {
        $parent = $node->getAttribute('parent');
        // A nested class starts a new property boundary.
        while ($parent instanceof Node) {
            // The nearest declaration is the only one whose property default can be inspected.
            if ($parent instanceof Stmt\ClassLike) {
                return $parent;
            }
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Recognizes a plain local with one exact spelling.
     *
     * @param Node|null $candidateLocal - Expression or absent value; absence cannot supply the expected local.
     * @param string    $name           - Local used by the bounded return summary.
     * @return bool - True for this ordinary local alone.
     */
    private static function isVariable(?Node $candidateLocal, string $name): bool
    {
        return $candidateLocal instanceof Expr\Variable && $candidateLocal->name === $name;
    }

    /**
     * Recognizes an empty PHP list without trusting an annotation or variable name.
     *
     * @param Node|null $candidateArray - Expression or absent fallback; absence is not an empty list.
     * @return bool - True only for literal [].
     */
    private static function isEmptyArray(?Node $candidateArray): bool
    {
        return $candidateArray instanceof Expr\Array_ && $candidateArray->items === [];
    }

    /**
     * Restricts property evidence to the current object's named storage.
     *
     * @param Expr\PropertyFetch $property - Selected read or write; foreign receivers cannot prove this list.
     * @return bool - True only for an explicit $this receiver and fixed property name.
     */
    private static function isThisProperty(Expr\PropertyFetch $property): bool
    {
        return $property->var instanceof Expr\Variable && $property->var->name === 'this' && $property->name instanceof Identifier;
    }
}
