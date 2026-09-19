<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

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
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * Flags calls into PHP's execution and evaluation surface so a reviewer can confirm each one runs trusted input
 * rather than attacker-controlled data, which is where command injection and arbitrary code execution begin.
 *
 * The surface is the non-removable built-ins `exec`, `shell_exec`, and `system`, any project-added global functions,
 * `eval`, `assert('...')` on a literal, and dynamic `$callable()` invocations.
 *
 * Runs per file. To keep noise down it first learns which locals, properties, and parameters hold known callables:
 *
 * - closures, arrow functions, `new` objects of a named or anonymous class, first-class callables of a written method
 *   other than reflection's `invoke` or of one of PHP's own functions that takes no callable, such as `strlen(...)`
 *   but not `array_map(...)`, and `[obj, 'method']` callable arrays assigned to a variable, or an inline
 *   `@var callable` docblock on the assignment;
 * - parameters carrying a `callable` or `Closure` type hint, or a `@param` docblock type that names one; a
 *   `callable-string` names a function, so it proves nothing;
 * - properties typed or documented as callable, and properties that collect appended callables;
 * - an enclosing `instanceof` or one-argument `is_callable()` condition that guards the call.
 *
 * An immediately invoked closure, arrow function, or `new` object is its own proof, and `Closure::fromCallable($x)()`
 * is judged exactly as `$x()` would be. `$f(...)` only creates a Closure, so it is never reported.
 *
 * Trust is scoped. A name is trusted only inside the function, method, or closure that proves it; an arrow function
 * also sees its parent's names unless its own parameter shadows them, a closure sees the names it `use`s, and code
 * outside any function shares one file scope. Property trust belongs to the class that declares it. No proof outranks
 * request input: a callee that reads a superglobal, directly or through a local the same function filled from one,
 * is always reported, so `is_callable($_GET['f'])` cannot vouch for `system`; a closure literal is still a closure
 * whatever its body reads. Otherwise reassigning a trusted name is not tracked. Calls through those are trusted,
 * leaving only unproven dynamic targets. Warning at medium confidence, because a name match is evidence for review
 * rather than a proven vulnerability.
 */
final class DangerousFunctionCallRule implements RuleInterface
{
    /**
     * Stable rule identifier for dangerous function call findings.
     */
    public const ID = 'security.dangerous-function-call';

    /**
     * @var list<string>
     */
    private const DANGEROUS_FUNCTIONS = [
        'exec',
        'passthru',
        'popen',
        'proc_open',
        'shell_exec',
        'system',
    ];

    /**
     * Scope key for code that sits outside every function, method, and closure.
     */
    private const FILE_SCOPE = 'file';

    /**
     * Global functions that return their first argument's callables as a collection, so a foreach over the result
     * iterates the same callables.
     *
     * @var list<string>
     */
    private const CALLABLE_COLLECTION_WRAPPERS = ['array_filter', 'array_reverse', 'array_values', 'array_wrap'];

    /**
     * Reflection methods that call whatever function or method they wrap, so a first-class callable of one proves
     * nothing about what it will run. A global function is judged by its own signature instead.
     *
     * @var list<string>
     */
    private const DISPATCHING_METHODS = ['invoke', 'invokeargs'];

    /**
     * Describes the dangerous-function-call rule for the registry and reports.
     *
     * @return RuleDefinition - the registry entry the engine keys this rule by; severity stays Warning
     *   (not Error) because a flagged call may be a legitimate constrained wrapper a human must judge
     */
    public function definition(): RuleDefinition
    {
        // Confidence is Medium, not High: a name match cannot prove the call reaches an attacker-controlled argument.
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Dangerous function calls',
            pillar:             Pillar::Security,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Warning,
            confidence:         Confidence::Medium,
            defaultOptions:     ['additionalFunctions' => []],
            description:        'Flags dynamic execution, built-in shell and evaluation patterns, and configured additive global function names.',
            optionDescriptions: [
                'additionalFunctions' => 'Global function names added to the non-removable built-in execution list; matching is case-insensitive.',
            ],
            falsePositiveShapes: [
                [
                    'shape'      => 'A constrained internal wrapper that runs a fixed literal command through exec(), system(), or shell_exec().',
                    'mitigation' => 'The built-in execution list matches on function name alone and never inspects the argument, and options.additionalFunctions only adds names, so review the wrapper once and accept it.',
                ],
                [
                    'shape'      => 'A dynamic $callable() whose target is defined in another file, such as a container-resolved handler or an injected callable.',
                    'mitigation' => 'Callable provenance is proven only from this file, so add a callable type hint or a `@param callable` docblock, or assign the closure locally to make the target visible.',
                ],
                [
                    'shape'      => 'A callable proven only by an early-return guard such as `if (!is_callable($handler)) { return; }` before the call.',
                    'mitigation' => 'Only an enclosing `if`, ternary, or `&&` condition proves callability, so move the call inside the guarded branch or type the value as callable.',
                ],
            ],
        );
    }

    /**
     * Reports dynamic execution, eval, assert-string, and dangerous shell calls.
     *
     * @param AnalysisUnit $analysisUnit - single parsed file the caller wants scanned; its AST and token
     *                                   stream are the only source consulted, so cross-file callable definitions are invisible here
     * @param RuleContext  $ruleContext  - shared per-run context; this rule reads `additionalFunctions`
     *                                   from it, which is unioned with the non-removable built-in execution list
     *
     * @return list<Finding> - one Finding per suspicious call site, empty when none match; callers treat
     *   the list as advisory evidence for review, not a proven vulnerability
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition         = $this->definition();
        $dangerousFunctions = $this->dangerousFunctions(
            $ruleContext->settingsFor($definition)->stringListOption('additionalFunctions'),
        );
        $findings           = [];
        $trust              = $this->callableTrust($analysisUnit);

        // Weigh every function call for a dangerous or unresolved dynamic callee.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // An unresolved callee name means this is a dynamic call worth scrutinising.
            if ($name === null) {
                // `$f(...)` builds a Closure from its target and executes nothing, so there is no call to judge.
                if ($call->isFirstClassCallable()) {
                    continue;
                }

                // Flag it unless the target is a plain name or a slot proven to hold a callable.
                if (!$call->name instanceof Node\Name && !$this->isKnownCallableInvocation($call, $trust)) {
                    $findings[] = $this->finding($analysisUnit, $call, 'dynamic function call');
                }

                continue;
            }

            // A direct exec/shell family call is the clearest dangerous shape.
            if (in_array($name, $dangerousFunctions, true)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }

            $firstArg = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // assert() on a string literal evaluates that string as code.
            if ($name === 'assert' && $firstArg !== null && SecurityNodeHelper::isStringLiteral($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $call, 'assert string evaluation');
            }
        }

        // Every eval() executes its argument as PHP, so flag them all.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Eval_::class) as $eval) {
            $findings[] = $this->finding($analysisUnit, $eval, 'eval');
        }

        // Order is by discovery, not severity: the reporter sorts; emitting here in AST order keeps results stable.
        return $findings;
    }

    /**
     * Unions normalized configured names with the built-in execution surface.
     *
     * @param list<string> $additionalFunctions - Project-supplied global function names.
     *
     * @return list<string> - Non-removable built-ins followed by unique normalized additions.
     */
    private function dangerousFunctions(array $additionalFunctions): array
    {
        $normalizedAdditionalFunctions = array_values(array_filter(array_map(
            static fn (string $functionName): string => strtolower(trim($functionName)),
            $additionalFunctions,
        ), static fn (string $functionName): bool => $functionName !== ''));

        return array_values(array_unique([
            ...self::DANGEROUS_FUNCTIONS,
            ...$normalizedAdditionalFunctions,
        ]));
    }

    /**
     * Learns every callable proof in the file, bucketed by the scope that owns it.
     *
     * Variable proofs are keyed by their enclosing function-like, or the file scope; property proofs by their class.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for parameters, assignments, loops, and properties that prove callability
     *
     * @return array{variables: array<int|string, array<string, true>>, collections: array<int|string, array<string, true>>, properties: array<int|string, array<string, true>>, collectionProperties: array<int|string, array<string, true>>} -
     *   trusted variable names, variables that hold a callable collection, trusted properties, and properties
     *   that hold a callable collection; every inner set uses `true` values (set semantics)
     */
    private function callableTrust(AnalysisUnit $analysisUnit): array
    {
        [$variables, $collections] = $this->callableParameterNames($analysisUnit);
        [$properties, $collectionProperties] = $this->callablePropertyNames($analysisUnit);

        // A closure, arrow function, object, or callable array bound to a plain variable proves that variable in its
        // scope, and so does an inline `/** @var callable $name */` docblock on the assignment.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
            // Skip assignments that do not bind a plain variable to a proven callable.
            if (
                !$assignment->var instanceof Expr\Variable
                || !is_string($assignment->var->name)
                || (!$this->isCallableExpression($assignment->expr) && !$this->hasInlineCallableDocblock($assignment, $assignment->var->name))
            ) {
                continue;
            }

            $variables[$this->scopeKey(SecurityNodeHelper::enclosingFunctionLike($assignment))][$assignment->var->name] = true;
        }

        // A callable appended onto a property array marks that property, in its own class, as a callable collection.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
            $propertyName = $assignment->var instanceof Expr\ArrayDimFetch ? $this->staticPropertyName($assignment->var->var) : null;
            // Only an array-append of a callable onto a named property qualifies.
            if ($propertyName === null || !$this->isCallableExpression($assignment->expr)) {
                continue;
            }

            $collectionProperties[$this->classKey($assignment)][$propertyName] = true;
        }

        // A foreach over a proven callable collection proves its value variable inside the loop's scope.
        foreach (NodeIndex::nodesOf($analysisUnit, Foreach_::class) as $foreach) {
            // Only a plainly named value variable can be tracked; key and destructured forms stay subject to flagging.
            if (!$foreach->valueVar instanceof Expr\Variable || !is_string($foreach->valueVar->name)) {
                continue;
            }

            // Skip loops whose subject is not a known callable collection.
            if (!$this->isCallableCollectionExpression($foreach->expr, $foreach, $collections, $collectionProperties)) {
                continue;
            }

            $variables[$this->scopeKey(SecurityNodeHelper::enclosingFunctionLike($foreach))][$foreach->valueVar->name] = true;
        }

        return [
            'variables'            => $variables,
            'collections'          => $collections,
            'properties'           => $properties,
            'collectionProperties' => $collectionProperties,
        ];
    }

    /**
     * Collects the parameters of every function, method, closure, and arrow function that are typed or documented
     * as callable, each under the function-like that declares it.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for callable-typed or `@param callable`-documented parameters
     *
     * @return array{0: array<int|string, array<string, true>>, 1: array<int|string, array<string, true>>} -
     *   the trusted parameter names per scope, then the same names as callable-collection sources, because a
     *   documented `array|callable` parameter is iterated as often as it is called
     */
    private function callableParameterNames(AnalysisUnit $analysisUnit): array
    {
        $variables   = [];
        $collections = [];

        // Weigh every function-like for callable parameters.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class, Expr\Closure::class, Expr\ArrowFunction::class]) as $functionLike) {
            // Guard the loose node type before reading its parameter list.
            if (!$functionLike instanceof FunctionLike) {
                continue;
            }

            $documented = $this->documentedCallableParameters($functionLike);
            $scope      = $this->scopeKey($functionLike);

            // Inspect each parameter for a callable type hint or a callable docblock type.
            foreach ($functionLike->getParams() as $param) {
                // Record only plainly named parameters that are typed or documented as callable.
                if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }

                $parameterName = $param->var->name;
                if (!$this->isCallableType($param->type) && !isset($documented[$parameterName])) {
                    continue;
                }

                $variables[$scope][$parameterName]   = true;
                $collections[$scope][$parameterName] = true;
            }
        }

        return [$variables, $collections];
    }

    /**
     * Reads the parameter names a function-like's docblock types as callable or Closure.
     *
     * @param FunctionLike $functionLike - function, method, closure, or arrow function whose docblock is read
     *
     * @return array<string, true> - parameter names (without `$`) whose `@param` type names callable or Closure,
     *   including unions, nullable forms, and collections such as `callable[]`; empty when there is no docblock
     */
    private function documentedCallableParameters(FunctionLike $functionLike): array
    {
        $docComment = $functionLike->getDocComment();
        if ($docComment === null) {
            // No docblock means no `@param` types to read.
            return [];
        }

        $names = [];
        // Each `@param <type> $name` tag, capturing the type text and the name, with by-reference and variadic forms.
        preg_match_all('/@param\s+(?<type>[^$\n]*?)\s*&?(?:\.\.\.)?\$(?<name>[A-Za-z_]\w*)/', $docComment->getText(), $matches, PREG_SET_ORDER);

        // Keep each documented parameter whose type mentions a callable form anywhere in it.
        foreach ($matches as $match) {
            // The word callable or Closure anywhere in the type, so `callable[]` and `array|callable|null` both count;
            // `callable-string` and the other hyphenated forms name a function or method, which proves nothing.
            if (preg_match('/\b(?:callable|closure)\b(?!-)/i', $match['type']) === 1) {
                $names[$match['name']] = true;
            }
        }

        return $names;
    }

    /**
     * Collects the properties that hold callables, via a callable type hint, a `@var callable` docblock, or a
     * promoted constructor parameter typed as callable, each under the class that declares it.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for callable-typed or callable-documented properties
     *
     * @return array{0: array<int|string, array<string, true>>, 1: array<int|string, array<string, true>>} -
     *   trusted property names per class, then the documented ones that also count as callable collections,
     *   since a `@var callable[]` property is iterated rather than called
     */
    private function callablePropertyNames(AnalysisUnit $analysisUnit): array
    {
        $properties           = [];
        $collectionProperties = [];

        // Weigh every declared property for a callable type or docblock.
        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            $isDocumented = $this->hasCallableDocblock($property);
            // Skip properties that are neither callable-typed nor callable-documented.
            if (!$this->isCallableType($property->type) && !$isDocumented) {
                continue;
            }

            $classScope = $this->classKey($property);
            // One declaration can name several properties; record each.
            foreach ($property->props as $prop) {
                $properties[$classScope][$prop->name->toString()] = true;

                // A documented callable property is often a callable list, so a loop over it is trusted too.
                if ($isDocumented) {
                    $collectionProperties[$classScope][$prop->name->toString()] = true;
                }
            }
        }

        // Also weigh promoted constructor parameters, which become properties of their own class.
        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            // Scan every method for a promoted-parameter property.
            foreach ($class->getMethods() as $classMethod) {
                // Inspect each parameter of the method.
                foreach ($classMethod->params as $param) {
                    // Record only promoted parameters that are plainly named and callable-typed.
                    if (
                        $param->flags === 0
                        || !$param->var instanceof Expr\Variable
                        || !is_string($param->var->name)
                        || !$this->isCallableType($param->type)
                    ) {
                        continue;
                    }

                    $properties[spl_object_id($class)][$param->var->name] = true;
                }
            }
        }

        return [$properties, $collectionProperties];
    }

    /**
     * Reports whether a dynamic call's target is proven to hold a callable, which is the gate that turns a
     * finding off.
     *
     * @param Expr\FuncCall $call  - the dynamic call whose callee is not a plain function name
     * @param array{variables: array<int|string, array<string, true>>, collections: array<int|string, array<string, true>>, properties: array<int|string, array<string, true>>, collectionProperties: array<int|string, array<string, true>>} $trust -
     *   the scoped callable proofs `callableTrust()` learned from this file
     *
     * @return bool - true means "trusted callable, do not flag"; false means the target is unproven and the
     *   caller should record a dynamic-call finding (false is the safe default, not a positive denial)
     */
    private function isKnownCallableInvocation(Expr\FuncCall $call, array $trust): bool
    {
        return $call->name instanceof Expr && $this->isProvenCallable($call->name, $call, $trust);
    }

    /**
     * Reports whether one callee expression is proven callable at a call site.
     *
     * @param Expr          $target - the callee, or the argument `Closure::fromCallable()` wraps
     * @param Expr\FuncCall $call   - the dynamic call, whose scope and class resolve names
     * @param array{variables: array<int|string, array<string, true>>, collections: array<int|string, array<string, true>>, properties: array<int|string, array<string, true>>, collectionProperties: array<int|string, array<string, true>>} $trust -
     *   the scoped callable proofs `callableTrust()` learned from this file
     *
     * @return bool - true for a proven variable or property, a guarded variable, or a syntactic callable; false otherwise
     */
    private function isProvenCallable(Expr $target, Expr\FuncCall $call, array $trust): bool
    {
        if ($this->isRequestInputCallee($target, $call)) {
            // Request input reaching the callee outranks every proof: `is_callable()` and `callable` accept `'system'`.
            return false;
        }

        if ($target instanceof Expr\Variable && is_string($target->name)) {
            // A bare `$var()` is trusted when its scope proves the name, or an enclosing condition guards it.
            return $this->isTrustedName($target->name, SecurityNodeHelper::enclosingFunctionLike($call), $trust['variables'])
                || $this->isGuardedCallable($call, $target->name);
        }

        $propertyName = $this->staticPropertyName($target);
        if ($propertyName !== null) {
            // A call through a property is trusted only when the enclosing class proves that property callable.
            return isset($trust['properties'][$this->classKey($call)][$propertyName]);
        }

        $wrappedCallable = $this->closureFromCallableArgument($target);
        if ($wrappedCallable !== null) {
            // `Closure::fromCallable($x)()` runs exactly what `$x()` would, so its argument is judged in the call's place.
            return $this->isProvenCallable($wrappedCallable, $call, $trust);
        }

        // An immediately invoked closure, arrow function, object, or callable array is its own proof.
        return $this->isCallableExpression($target);
    }

    /**
     * Returns the argument of a `Closure::fromCallable(...)` call.
     *
     * @param Expr $expr - candidate static call
     *
     * @return Expr|null - the wrapped callable expression; null for any other expression or a first-class callable form
     */
    private function closureFromCallableArgument(Expr $expr): ?Expr
    {
        if (
            !$expr instanceof Expr\StaticCall
            || !$expr->class instanceof Name
            || strtolower($expr->class->getLast()) !== 'closure'
            || !$expr->name instanceof Identifier
            || strtolower($expr->name->toString()) !== 'fromcallable'
            || $expr->isFirstClassCallable()
        ) {
            return null;
        }

        return ($expr->getArgs()[0] ?? null)?->value;
    }

    /**
     * Reports whether an assignment carries an inline `@var callable $name` docblock for its target.
     *
     * @param Expr\Assign $assignment - assignment whose enclosing statement's docblock is read
     * @param string      $name       - the assigned variable's name without `$`
     *
     * @return bool - true when the statement's docblock types that variable as callable or Closure; false otherwise
     */
    private function hasInlineCallableDocblock(Expr\Assign $assignment, string $name): bool
    {
        $statement  = $assignment->getAttribute('parent');
        $docComment = $statement instanceof Stmt\Expression ? $statement->getDocComment() : null;

        // Only a docblock on the assignment statement itself speaks for this variable.
        if ($docComment === null) {
            return false;
        }

        // An `@var` tag whose type names callable or Closure, not `callable-string`, and whose variable is this one.
        return preg_match('/@var\s+[^$\n]*\b(?:callable|closure)\b(?!-)[^$\n]*\$' . preg_quote($name, '/') . '\b/i', $docComment->getText()) === 1;
    }

    /**
     * Reports whether a variable name is proven callable in a scope, following the scopes that can see it.
     *
     * @param string                                  $name  - variable name without `$`
     * @param FunctionLike|null                       $scope - function-like the use sits in, or null for file scope
     * @param array<int|string, array<string, true>> $sets  - proven names per scope key
     *
     * @return bool - true when the scope proves the name, or an arrow function's parent does, or a closure's parent
     *   does for a name the closure `use`s; false for any other name, including one proven only in a sibling scope
     */
    private function isTrustedName(string $name, ?FunctionLike $scope, array $sets): bool
    {
        // Climb only through the scopes PHP itself lets the name through.
        while (true) {
            if (isset($sets[$this->scopeKey($scope)][$name])) {
                // This scope proved the name directly.
                return true;
            }

            // An arrow function captures its parent scope by value, so its parent's proofs apply to every name its own
            // parameters do not shadow.
            if ($scope instanceof Expr\ArrowFunction && !$this->hasParameterNamed($scope, $name)) {
                $scope = $this->parentScope($scope);
                continue;
            }

            // A closure sees only the names it `use`s, and those carry their parent's proofs.
            if ($scope instanceof Expr\Closure && $this->isImportedByClosure($scope, $name)) {
                $scope = $this->parentScope($scope);
                continue;
            }

            // A named function, a method, a closure that does not import the name, or the file scope ends the climb.
            return false;
        }
    }

    /**
     * Reports whether an enclosing condition proves a variable callable before the call runs.
     *
     * @param Expr\FuncCall $call - dynamic call through `$name`
     * @param string        $name - the callee variable's name without `$`
     *
     * @return bool - true when an enclosing `if`/`elseif` body, ternary true branch, or `&&` right operand runs only
     *   after an `instanceof` or `is_callable()` test on the same variable; false otherwise, including early-return
     *   guards, which this walk does not see
     */
    private function isGuardedCallable(Expr\FuncCall $call, string $name): bool
    {
        $child  = $call;
        $parent = $call->getAttribute('parent');

        // Walk outward to the nearest scope boundary the variable cannot cross.
        while ($parent instanceof Node) {
            if ($this->isGuardedBranch($parent, $child, $name)) {
                return true;
            }

            if (
                $parent instanceof FunctionLike
                && !($parent instanceof Expr\ArrowFunction && !$this->hasParameterNamed($parent, $name))
                && !($parent instanceof Expr\Closure && $this->isImportedByClosure($parent, $name))
            ) {
                // A guard outside a function-like cannot prove a name the function-like does not capture.
                return false;
            }

            $child  = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Reports whether one ancestor runs a child only after a condition proved a variable callable.
     *
     * @param Node   $parent - ancestor of the call, one step at a time
     * @param Node   $child  - the node on the path from the call that `$parent` directly contains
     * @param string $name   - variable name without `$`
     *
     * @return bool - true when `$child` is an `if`/`elseif` body statement, a ternary true branch, or an `&&` right
     *   operand whose condition proves `$name`; false for every other ancestor or position
     */
    private function isGuardedBranch(Node $parent, Node $child, string $name): bool
    {
        if ($parent instanceof Stmt\If_ || $parent instanceof Stmt\ElseIf_) {
            // Only the guarded body runs after the condition held; the condition and else branches do not.
            return in_array($child, $parent->stmts, true) && $this->isNarrowedToCallable($parent->cond, $name);
        }

        if ($parent instanceof Expr\Ternary) {
            // `$f instanceof Closure ? $f() : null` runs the call only on the proven branch.
            return $parent->if === $child && $this->isNarrowedToCallable($parent->cond, $name);
        }

        if ($parent instanceof Expr\BinaryOp\BooleanAnd || $parent instanceof Expr\BinaryOp\LogicalAnd) {
            // `is_callable($f) && $f()` evaluates the call only after the left operand held.
            return $parent->right === $child && $this->isNarrowedToCallable($parent->left, $name);
        }

        return false;
    }

    /**
     * Reports whether a condition, when true, proves a variable holds a callable.
     *
     * @param Expr   $condition - the `if`, ternary, or `&&` condition to read
     * @param string $name      - variable name without `$`
     *
     * @return bool - true for `$name instanceof SomeClass`, `is_callable($name)`, or an `&&` chain containing either;
     *   an object target cannot name a global function, so any class test is as strong as `callable`, while
     *   `is_callable()` is only as strong as a `callable` type, so the caller vetoes request input first
     */
    private function isNarrowedToCallable(Expr $condition, string $name): bool
    {
        if ($condition instanceof Expr\BinaryOp\BooleanAnd || $condition instanceof Expr\BinaryOp\LogicalAnd) {
            // Both operands held when an `&&` chain is true, so either one can carry the proof.
            return $this->isNarrowedToCallable($condition->left, $name) || $this->isNarrowedToCallable($condition->right, $name);
        }

        if ($condition instanceof Expr\Instanceof_) {
            // A class test on the same variable proves an object, which PHP invokes through __invoke.
            return $condition->class instanceof Name && $this->isVariableNamed($condition->expr, $name);
        }

        if (
            $condition instanceof Expr\FuncCall
            && $condition->name instanceof Name
            && strtolower($condition->name->getLast()) === 'is_callable'
            && count($condition->args) === 1
        ) {
            // PHP's own callability check on the same variable; a second argument can make it accept any string.
            $firstArg = SecurityNodeHelper::sinkArgumentValue($condition, 0);

            return $firstArg !== null && $this->isVariableNamed($firstArg, $name);
        }

        return false;
    }

    /**
     * Reports whether an expression is the plain variable with a given name.
     *
     * @param Expr   $expr - expression to test
     * @param string $name - variable name without `$`
     *
     * @return bool - true only for `$name` itself; property fetches and dynamic variable names are false
     */
    private function isVariableNamed(Expr $expr, string $name): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === $name;
    }

    /**
     * Reports whether a closure imports a variable through its `use` clause.
     *
     * @param Expr\Closure $closure - closure whose `use` list is read
     * @param string       $name    - variable name without `$`
     *
     * @return bool - true when the closure lists `$name` in `use (...)`, by value or by reference
     */
    private function isImportedByClosure(Expr\Closure $closure, string $name): bool
    {
        // Each `use` entry imports one variable from the parent scope.
        foreach ($closure->uses as $use) {
            if ($use->var->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a function-like declares a parameter with a given name.
     *
     * @param FunctionLike $functionLike - function-like whose parameter list is read
     * @param string       $name         - variable name without `$`
     *
     * @return bool - true when a parameter binds `$name`, which hides any parent-scope variable of that name
     */
    private function hasParameterNamed(FunctionLike $functionLike, string $name): bool
    {
        // Each parameter binds one name inside the function-like.
        foreach ($functionLike->getParams() as $param) {
            if ($this->isVariableNamed($param->var, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the name of a statically named property fetch.
     *
     * @param Expr $expr - candidate property fetch
     *
     * @return string|null - the property name for `$receiver->name`, whatever the receiver, because a static factory
     *   reads its own class's properties through `$self` or `$response` as often as through `$this`; null for a
     *   computed name, whose value this class never proved
     */
    private function staticPropertyName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            return null;
        }

        return $expr->name->toString();
    }

    /**
     * Reports whether request input reaches a callee, which outranks every proof.
     *
     * @param Expr          $target - the callee, or the argument `Closure::fromCallable()` wraps
     * @param Expr\FuncCall $call   - the dynamic call the callee belongs to
     *
     * @return bool - true when the callee reads a superglobal, directly or through a local its function filled from
     *   one; false when it does not, or when the variable's latest binding before the call is a closure or arrow
     *   function literal, whose body may read request data without the callee being that data
     */
    private function isRequestInputCallee(Expr $target, Expr\FuncCall $call): bool
    {
        if (!SecurityNodeHelper::containsUserInput($target)) {
            return false;
        }

        if (!$target instanceof Expr\Variable || !is_string($target->name)) {
            return true;
        }

        $latest = $this->latestAssignmentBefore($target->name, $call);

        return !($latest?->expr instanceof Expr\Closure || $latest?->expr instanceof Expr\ArrowFunction);
    }

    /**
     * Returns the last assignment to a variable in the call's own function before the call.
     *
     * @param string        $name - variable name without `$`
     * @param Expr\FuncCall $call - the dynamic call whose function body is searched
     *
     * @return Expr\Assign|null - the assignment that starts latest before the call; null at file scope, in a body-less
     *   function, or when no earlier assignment binds the name
     */
    private function latestAssignmentBefore(string $name, Expr\FuncCall $call): ?Expr\Assign
    {
        $scope      = SecurityNodeHelper::enclosingFunctionLike($call);
        $statements = $scope?->getStmts();
        if ($statements === null) {
            return null;
        }

        $latest = null;
        // Keep the latest plain assignment to the name that this same function makes before the call.
        foreach ((new NodeFinder())->findInstanceOf($statements, Expr\Assign::class) as $assignment) {
            if (
                !$this->isVariableNamed($assignment->var, $name)
                || $assignment->getStartFilePos() >= $call->getStartFilePos()
                || SecurityNodeHelper::enclosingFunctionLike($assignment) !== $scope
                || ($latest !== null && $assignment->getStartFilePos() < $latest->getStartFilePos())
            ) {
                continue;
            }

            $latest = $assignment;
        }

        return $latest;
    }

    /**
     * Returns the function-like scope that encloses a closure or arrow function.
     *
     * @param FunctionLike $functionLike - nested function-like whose parent scope is needed
     *
     * @return FunctionLike|null - the enclosing function-like, or null when the nested one sits at file scope
     */
    private function parentScope(FunctionLike $functionLike): ?FunctionLike
    {
        $parent = $functionLike->getAttribute('parent');

        return $parent instanceof Node ? SecurityNodeHelper::enclosingFunctionLike($parent) : null;
    }

    /**
     * Returns the key a function-like scope, or the file scope, is stored under.
     *
     * @param FunctionLike|null $functionLike - owning function-like, or null for code outside every function
     *
     * @return int|string - the function-like's object id, or the file-scope key
     */
    private function scopeKey(?FunctionLike $functionLike): int|string
    {
        return $functionLike === null ? self::FILE_SCOPE : spl_object_id($functionLike);
    }

    /**
     * Returns the key of the class-like declaration that encloses a node.
     *
     * @param Node $node - property, assignment, or call whose owning class is needed
     *
     * @return int|string - the enclosing class-like's object id, or the file-scope key when there is none
     */
    private function classKey(Node $node): int|string
    {
        $current = $node->getAttribute('parent');

        // Climb to the nearest class, trait, enum, or interface.
        while ($current instanceof Node) {
            if ($current instanceof ClassLike) {
                return spl_object_id($current);
            }

            $current = $current->getAttribute('parent');
        }

        return self::FILE_SCOPE;
    }

    /**
     * Reports whether a property's `@var` docblock promises a callable even when its declared type is
     * untyped or too loose for isCallableType, so the property can still join the trusted set.
     *
     * @param Property $property - property declaration node whose attached docblock (if any) is examined
     *
     * @return bool - true when the docblock's `@var` names callable or Closure; false when there is no
     *   docblock or the declared type is not callable-like, leaving the property subject to flagging
     */
    private function hasCallableDocblock(Property $property): bool
    {
        $docComment = $property->getDocComment();
        if ($docComment === null) {
            // No docblock means no `@var` to inspect, so this path cannot vouch for the property.
            return false;
        }

        // Match @var annotations whose declared type is callable-like, but not a `callable-string` function name.
        return preg_match('/@var\s+[^\n]*(?:callable|Closure)\b(?!-)/i', $docComment->getText()) === 1;
    }

    /**
     * Reports whether an expression definitely produces a callable, so an assignment target can be trusted or an
     * immediately invoked callee accepted.
     *
     * @param Expr $expr - right-hand side of an assignment, or the callee of a dynamic call
     *
     * @return bool - true only for syntactic callables visible at this node: a closure, an arrow function, a `new`
     *   object of a written or anonymous class (invoked through `__invoke`; `new $class` lets the caller pick the
     *   class), a first-class callable whose target is written statically such as
     *   `strlen(...)` or `$this->handle(...)`, or a `[receiver, 'method']` array; a string function name, a returned
     *   callable, or `$name(...)` reads as false here (deliberately strict, to keep the trusted set to forms we can prove)
     */
    private function isCallableExpression(Expr $expr): bool
    {
        // Strings like 'strlen' are intentionally excluded: a name we cannot resolve should not silence a finding.
        return $expr instanceof Expr\Closure
            || $expr instanceof Expr\ArrowFunction
            || ($expr instanceof Expr\New_ && ($expr->class instanceof Name || $expr->class instanceof Class_))
            || $this->isStaticFirstClassCallable($expr)
            || $this->isStaticCallableArray($expr);
    }

    /**
     * Reports whether an expression is a first-class callable whose target is written statically.
     *
     * @param Expr $expr - expression to test
     *
     * @return bool - true for `strlen(...)`, `$object->method(...)`, and `Type::method(...)`; false for `$name(...)`
     *   or `$object->$method(...)`, which would turn an attacker-chosen name into a trusted Closure, and for a
     *   dispatcher such as `array_map(...)`, `call_user_func(...)` or `$reflection->invoke(...)`, which runs whatever
     *   name it is handed, or a userland function whose body is not visible
     */
    private function isStaticFirstClassCallable(Expr $expr): bool
    {
        if (!$expr instanceof Expr\CallLike || !$expr->isFirstClassCallable()) {
            // Only the `(...)` form creates a Closure without running anything.
            return false;
        }

        if ($expr instanceof Expr\FuncCall) {
            // A function reference proves its target only when it names one of PHP's own functions that takes no
            // callable: `array_map(...)` or `call_user_func(...)` runs whatever name its caller passes.
            return $expr->name instanceof Name && $this->isNonDispatchingInternalFunction($expr->name->toString());
        }

        // A method reference is static when its method name is written out rather than computed.
        return ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall)
            && $expr->name instanceof Identifier
            && !in_array(strtolower($expr->name->toString()), self::DISPATCHING_METHODS, true);
    }

    /**
     * Reports whether a global function is one of PHP's own functions with no parameter that accepts a callable.
     *
     * @param string $name - function name as written, without a leading backslash
     *
     * @return bool - true for `strlen` or `trim`; false for `array_map`, `usort` or `call_user_func`, which call a
     *   callable argument, and for a userland or unknown function, whose body this file-local rule cannot see
     */
    private function isNonDispatchingInternalFunction(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }

        $function = new \ReflectionFunction($name);
        if (!$function->isInternal()) {
            return false;
        }

        // Any parameter that can receive a callable makes the function a dispatcher.
        foreach ($function->getParameters() as $parameter) {
            if ($this->isCallableParameter($parameter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether a PHP function parameter can receive a callable it may call.
     *
     * @param \ReflectionParameter $parameter - one parameter of an internal function
     *
     * @return bool - true for a `callable` or `Closure` type, including in a union or nullable, and for an untyped or
     *   `mixed` parameter named like `ob_start()`'s `$callback`
     */
    private function isCallableParameter(\ReflectionParameter $parameter): bool
    {
        $type    = $parameter->getType();
        $members = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];

        // Weigh each member of the declared type, or the missing type itself.
        foreach ($members as $member) {
            $typeName = $member instanceof \ReflectionNamedType ? strtolower($member->getName()) : 'mixed';
            if (in_array($typeName, ['callable', 'closure'], true)) {
                return true;
            }

            // An untyped or `mixed` parameter reveals itself only through its name.
            if ($typeName === 'mixed' && str_contains(strtolower($parameter->getName()), 'callback')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether an expression is the two-element array callable form, `[$receiver, 'method']` or
     * `[ClassName::class, 'method']`, where the second element is a literal method name.
     *
     * @param Expr $expr - expression to test for the array-callable shape
     *
     * @return bool - true when the node is a two-item array whose second item is a non-unpacked string
     *   literal; the first item is not type-checked because any receiver expression is acceptable here
     */
    private function isStaticCallableArray(Expr $expr): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {
            // Only a literal two-element array can be a `[receiver, method]` callable; everything else is rejected.
            return false;
        }

        $receiverItem = $expr->items[0] ?? null;
        $methodItem   = $expr->items[1] ?? null;

        // Neither element may be spread, which would hide the real element count, and the method element must be a
        // plain string literal; a dynamic method name is not provable here.
        return $receiverItem !== null
            && !$receiverItem->unpack
            && $methodItem !== null
            && !$methodItem->unpack
            && $methodItem->value instanceof Scalar\String_;
    }

    /**
     * Reports whether a foreach subject is a proven callable collection, so the loop value variable can be trusted.
     *
     * @param Expr                                    $expr                 - the `foreach (... as $v)` subject expression
     * @param Node                                    $context              - the foreach, whose scope and class resolve names
     * @param array<int|string, array<string, true>> $collections          - variables proven to hold callable collections, per scope
     * @param array<int|string, array<string, true>> $collectionProperties - properties proven to hold callable collections, per class
     *
     * @return bool - true for a proven property of the enclosing class, a proven variable visible from the loop's
     *   scope, or either one passed through `(array)`, `Arr::wrap()`, or an order- or membership-preserving array
     *   function; false for any other subject, leaving the loop variable untrusted
     */
    private function isCallableCollectionExpression(Expr $expr, Node $context, array $collections, array $collectionProperties): bool
    {
        $propertyName = $this->staticPropertyName($expr);
        if ($propertyName !== null) {
            // A statically named property is matched against its own class's callable collections.
            return isset($collectionProperties[$this->classKey($context)][$propertyName]);
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            // A variable is a collection when its scope, or one it can see, proved it.
            return $this->isTrustedName($expr->name, SecurityNodeHelper::enclosingFunctionLike($context), $collections);
        }

        if ($expr instanceof Expr\Cast\Array_) {
            // `(array) $callbacks` wraps a single callable or passes a list through unchanged.
            return $this->isCallableCollectionExpression($expr->expr, $context, $collections, $collectionProperties);
        }

        $wrapped = $this->wrappedCollectionArgument($expr);

        // A wrapper returns its first argument's callables, so the loop sees the same values.
        return $wrapped !== null && $this->isCallableCollectionExpression($wrapped, $context, $collections, $collectionProperties);
    }

    /**
     * Returns the first argument of a call that returns its argument's elements unchanged as a list.
     *
     * @param Expr $expr - candidate wrapper call
     *
     * @return Expr|null - the wrapped argument for `Arr::wrap()` or a listed array function; null for any other expression
     */
    private function wrappedCollectionArgument(Expr $expr): ?Expr
    {
        $isArrWrap = $expr instanceof Expr\StaticCall
            && $expr->class instanceof Name
            && strtolower($expr->class->getLast()) === 'arr'
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'wrap';
        $isWrapperFunction = $expr instanceof Expr\FuncCall
            && $expr->name instanceof Name
            && in_array(strtolower($expr->name->getLast()), self::CALLABLE_COLLECTION_WRAPPERS, true);

        // Anything else might transform the values, so it cannot pass a proof through.
        if ((!$isArrWrap && !$isWrapperFunction) || !$expr instanceof Expr\CallLike || $expr->isFirstClassCallable()) {
            return null;
        }

        $firstArg = $expr->getArgs()[0] ?? null;

        return $firstArg?->value;
    }

    /**
     * Reports whether a declared type node permits callable invocation, recursing through union and
     * nullable wrappers so `callable|null` or `?Closure` still count.
     *
     * @param Node|null $type - the parameter or property type node, or null when the declaration is untyped
     *
     * @return bool - true when the type is `callable`, a `Closure`/`*Callable` class name, or a union or
     *   nullable that contains one; false (including for a null/untyped declaration) means not callable
     */
    private function isCallableType(?Node $type): bool
    {
        if ($type instanceof Identifier) {
            // A built-in type hint counts only when it is the literal `callable` keyword.
            return strtolower($type->toString()) === 'callable';
        }

        // A class-name hint counts when it names Closure or a *Callable convention class.
        if ($type instanceof Name) {
            $shortName = strtolower($type->getLast());

            // Match on the short name only (namespace ignored): Closure, or any `...Callable` convention class.
            return $shortName === 'closure' || str_ends_with($shortName, 'callable');
        }

        // A union is callable when any member is.
        if ($type instanceof Node\UnionType) {
            // Weigh each member of the union.
            foreach ($type->types as $innerType) {
                if ($this->isCallableType($innerType)) {
                    // One callable member is enough: `callable|string` can still be invoked as a callable.
                    return true;
                }
            }
        }

        if ($type instanceof Node\NullableType) {
            // `?T` is callable exactly when its inner type is; nullability does not change invokability.
            return $this->isCallableType($type->type);
        }

        // Intersection types, scalar hints, and untyped (null) declarations are not treated as callable.
        return false;
    }

    /**
     * Builds the Finding for one flagged call site, fixing severity, pillar, and remediation so every
     * detection path reports identically.
     *
     * @param AnalysisUnit $analysisUnit - unit being scanned; supplies the display path recorded on the finding
     * @param Node         $node         - the offending node (call or eval) whose start line locates the report
     * @param string       $function     - human-readable label for the pattern (e.g. `exec`, `eval`,
     *                                   `dynamic function call`); flows into both the message and the `function` metadata key for grouping
     *
     * @return Finding - the populated finding the caller appends to its result list; never null, since this is
     *   only called once a pattern has already matched
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $function): Finding
    {
        // Severity and confidence are pinned here, not derived per pattern, so all dangerous-call findings rank alike.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Dangerous PHP execution pattern detected: %s.', $function),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Replace direct execution with a constrained wrapper, strict allow-lists, or a non-shell API.',
            metadata:    [
                'function' => $function,
            ],
        );
    }
}
