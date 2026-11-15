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
 * Reports built-in execution patterns, configured global functions, and unresolved dynamic calls for review.
 * Local callable evidence can suppress dynamic-call findings; it does not prove that a callable body is harmless.
 * Built-in process calls whose command is a plain string literal are skipped.
 */
final class DangerousFunctionCallRule implements RuleInterface
{
    /**
     * Stable rule identifier for dangerous function call findings.
     */
    public const ID = 'security.dangerous-function-call';

    /**
     * @var list<string> - Non-removable built-in process-execution names.
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
     * Global wrapper names recognized when propagating callable-collection evidence.
     *
     * @var list<string> - Wrapper names matched case-insensitively by wrappedCollectionArgument().
     */
    private const CALLABLE_COLLECTION_WRAPPERS = ['array_filter', 'array_reverse', 'array_values', 'array_wrap'];

    /**
     * Reflection methods that call whatever function or method they wrap, so a first-class callable of one proves
     * nothing about what it will run. A global function is judged by its own signature instead.
     *
     * @var list<string> - Method names excluded from static first-class callable evidence.
     */
    private const DISPATCHING_METHODS = ['invoke', 'invokeargs'];

    /**
     * Describes the dangerous-function-call rule for the registry and reports.
     *
     * @return RuleDefinition - Registry metadata with Warning severity because flagged calls require human review.
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
            // Off unless a project sets `enabled: true`: precision-floor M02 found it right on 3 of 6 judged findings, too few
            // to establish its precision floor, so it is off by default rather than deleted (ADR-034).
            isEnabledByDefault: false,
            defaultOptions:     ['additionalFunctions' => []],
            description:        'Flags dynamic execution, built-in shell and evaluation patterns, and configured additive global function names.',
            optionDescriptions: [
                'additionalFunctions' => 'Global function names added to the non-removable built-in execution list; matching is case-insensitive.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'A constrained internal wrapper that runs a command assembled from validated values through exec(), system(), or shell_exec().',
                    'mitigation' => 'Only a built-in execution call whose command is one plain string literal is skipped; any other command, and every options.additionalFunctions name, still reports, so review the wrapper once and accept it.',
                ],
                [
                    'shape' => 'A dynamic $callable() whose target is defined in another file, such as a container-resolved handler or an injected callable.',
                    'mitigation' => 'Callable provenance is proven only from this file, so add a callable type hint or a `@param callable` docblock, or assign the closure locally to make the target visible.',
                ],
                [
                    'shape' => 'A callable proven only by an early-return guard such as `if (!is_callable($handler)) { return; }` before the call.',
                    'mitigation' => 'Only an enclosing `if`, ternary, or `&&` condition proves callability, so move the call inside the guarded branch or type the value as callable.',
                ],
            ],
        );
    }

    /**
     * Reports dynamic execution, eval, assert-string, and dangerous shell calls.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file supplying call sites and local callable evidence.
     * @param RuleContext $ruleContext - Per-run settings supplying additive global function names.
     *
     * @return list<Finding> - Advisory findings for matching patterns; empty when none match, with multiple findings possible per site.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition         = $this->definition();
        $dangerousFunctions = $this->dangerousFunctions(
            $ruleContext->settingsFor($definition)->stringListOption('additionalFunctions'),
        );
        $findings = [];
        $trust    = $this->callableTrust($analysisUnit);


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            // A local with one detected literal function name is classified like a direct call.
            $name = SecurityNodeHelper::globalFunctionName($call) ?? CallableFlowEvidence::literalCalleeName($call);

            if ($name === null) {
                // $f(...) builds a Closure from its target and executes nothing, so there is no call to judge.
                if ($call->isFirstClassCallable()) {
                    continue;
                }


                if (!$call->name instanceof Node\Name && !$this->isKnownCallableInvocation($call, $trust)
                    && !CallableFlowEvidence::hasInvocationEvidence($call, fn (Expr $callbackValue): bool => $this->isProvenCallable($callbackValue, $call, $trust))
                    && !CallableCollectionEvidence::hasInvocationEvidence($call)
                    && !CallableCollectionReturnEvidence::hasInvocationEvidence($call)
                    && !CallableFlowEvidence::hasLocalClosureListEvidence($call)) {
                    $findings[] = $this->finding($analysisUnit, $call, 'dynamic function call');
                }

                continue;
            }

            $firstArg = SecurityNodeHelper::sinkArgumentValue($call, 0);

            if (in_array($name, $dangerousFunctions, true) && !$this->isFixedBuiltInCommand($name, $firstArg)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }


            if ($name === 'assert' && $firstArg !== null && SecurityNodeHelper::isStringLiteral($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $call, 'assert string evaluation');
            }
        }


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Eval_::class) as $eval) {
            $findings[] = $this->finding($analysisUnit, $eval, 'eval');
        }

        // Function-call findings precede eval findings; each group retains node discovery order.
        return $findings;
    }

    /**
     * Reports whether a built-in process call has a plain string-literal command argument.
     *
     * @param string $name - Lower-cased called function name.
     * @param Expr|null $firstArg - Command argument; a missing argument proves nothing.
     *
     * @return bool - True when the name is built in and the command argument is a plain string literal.
     */
    private function isFixedBuiltInCommand(string $name, ?Expr $firstArg): bool
    {
        return in_array($name, self::DANGEROUS_FUNCTIONS, true) && $firstArg instanceof Node\Scalar\String_;
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
     * Collects callable evidence in the file, bucketed by the scope that owns it.
     *
     * Variable evidence is keyed by its function-like or file scope; property evidence is keyed by its class.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for parameters, assignments, loops, and properties that supply callable evidence
     *
     * @return array{variables: array<int|string, array<string, true>>, collections: array<int|string, array<string, true>>, properties: array<int|string, array<string, true>>, collectionProperties: array<int|string, array<string, true>>} -
     *   Scoped variable, collection, property, and collection-property names; true marks membership in each inner set.
     */
    private function callableTrust(AnalysisUnit $analysisUnit): array
    {
        [$variables, $collections]           = $this->callableParameterNames($analysisUnit);
        [$properties, $collectionProperties] = $this->callablePropertyNames($analysisUnit);

        // A closure, arrow function, object construction, or supported callable-array shape supplies evidence in its
        // scope; an inline @var callable $name annotation on the assignment does too.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {

            if (
                !$assignment->var instanceof Expr\Variable
                || !is_string($assignment->var->name)
                || (!$this->isCallableExpression($assignment->expr) && !$this->hasInlineCallableDocblock($assignment, $assignment->var->name))
            ) {
                continue;
            }

            $variables[$this->scopeKey(SecurityNodeHelper::enclosingFunctionLike($assignment))][$assignment->var->name] = true;
        }

        // An expression accepted by the callable-evidence heuristic marks its property array as callable collection evidence for that class.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
            $propertyName = $assignment->var instanceof Expr\ArrayDimFetch ? $this->staticPropertyName($assignment->var->var) : null;
            if ($propertyName === null || !$this->isCallableExpression($assignment->expr)) {
                continue;
            }

            $collectionProperties[$this->classKey($assignment)][$propertyName] = true;
        }

        // Iterating a collection with callable evidence supplies the same evidence for its plain value variable.
        foreach (NodeIndex::nodesOf($analysisUnit, Foreach_::class) as $foreach) {
            // Only a plainly named value variable can be tracked; key and destructured forms stay subject to flagging.
            if (!$foreach->valueVar instanceof Expr\Variable || !is_string($foreach->valueVar->name)) {
                continue;
            }


            if (!$this->isCallableCollectionExpression($foreach->expr, $foreach, $collections, $collectionProperties)) {
                continue;
            }

            $variables[$this->scopeKey(SecurityNodeHelper::enclosingFunctionLike($foreach))][$foreach->valueVar->name] = true;
        }

        return [
            'variables' => $variables,
            'collections' => $collections,
            'properties' => $properties,
            'collectionProperties' => $collectionProperties,
        ];
    }

    /**
     * Collects the parameters of every function, method, closure, and arrow function that are typed or documented
     * as callable, each under the function-like that declares it.
     * Both result sets contain the same names for direct calls and callable-collection evidence.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for callable-typed or @param callable-documented parameters
     *
     * @return array{0: array<int|string, array<string, true>>, 1: array<int|string, array<string, true>>} - Identical parameter-name sets by scope.
     */
    private function callableParameterNames(AnalysisUnit $analysisUnit): array
    {
        $variables   = [];
        $collections = [];


        foreach (NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class, Expr\Closure::class, Expr\ArrowFunction::class]) as $functionLike) {

            if (!$functionLike instanceof FunctionLike) {
                continue;
            }

            $documented = $this->documentedCallableParameters($functionLike);
            $scope      = $this->scopeKey($functionLike);


            foreach ($functionLike->getParams() as $param) {

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
     * @return array<string, true> - Matching parameter names without $, including collection types; empty without matching tags.
     */
    private function documentedCallableParameters(FunctionLike $functionLike): array
    {
        $docComment = $functionLike->getDocComment();
        if ($docComment === null) {

            return [];
        }

        $names = [];
        // Parameter tags provide callable evidence only when the declared type can be associated with a named parameter.
        preg_match_all('/@param\s+(?<type>[^$\n]*?)\s*&?(?:\.\.\.)?\$(?<name>[A-Za-z_]\w*)/', $docComment->getText(), $matches, PREG_SET_ORDER);

        // Keep each documented parameter whose type mentions a callable form anywhere in it.
        foreach ($matches as $match) {
            // Collection or union members count; callable-string stays untrusted because it can name a dangerous global function.
            if (preg_match('/\b(?:callable|closure)\b(?!-)/i', $match['type']) === 1) {
                $names[$match['name']] = true;
            }
        }

        return $names;
    }

    /**
     * Collects property callable evidence from a callable type hint, an @var callable docblock, or a promoted
     * constructor parameter typed as callable, keyed by declaring class.
     * Documented properties also supply collection evidence.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for callable-typed or callable-documented properties
     *
     * @return array{0: array<int|string, array<string, true>>, 1: array<int|string, array<string, true>>} - Callable then documented sets by class.
     */
    private function callablePropertyNames(AnalysisUnit $analysisUnit): array
    {
        $properties           = [];
        $collectionProperties = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            $isDocumented = $this->hasCallableDocblock($property);

            if (!$this->isCallableType($property->type) && !$isDocumented) {
                continue;
            }

            $classScope = $this->classKey($property);

            foreach ($property->props as $prop) {
                $properties[$classScope][$prop->name->toString()] = true;


                if ($isDocumented) {
                    $collectionProperties[$classScope][$prop->name->toString()] = true;
                }
            }
        }


        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {

            foreach ($class->getMethods() as $classMethod) {

                foreach ($classMethod->params as $param) {

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
     * @param Expr\FuncCall                                                                                                                                                                                                                   $call  - the dynamic call whose callee is not a plain function name
     * @param array{variables: array<int|string, array<string, true>>, collections: array<int|string, array<string, true>>, properties: array<int|string, array<string, true>>, collectionProperties: array<int|string, array<string, true>>} $trust -
     *                                                                                                                                                                                                                                               the scoped callable proofs `callableTrust()` learned from this file
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
     * @param Expr                                                                                                                                                                                                                            $target - the callee, or the argument `Closure::fromCallable()` wraps
     * @param Expr\FuncCall                                                                                                                                                                                                                   $call   - the dynamic call, whose scope and class resolve names
     * @param array{variables: array<int|string, array<string, true>>, collections: array<int|string, array<string, true>>, properties: array<int|string, array<string, true>>, collectionProperties: array<int|string, array<string, true>>} $trust  -
     *                                                                                                                                                                                                                                                the scoped callable proofs `callableTrust()` learned from this file
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
     * Returns the first argument from a static fromCallable call on a class whose final name is Closure.
     *
     * @param Expr $expr - candidate static call
     *
     * @return Expr|null - Wrapped argument, or null for a mismatch, a first-class reference, or a missing argument.
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
     * Reports whether an assignment statement documents its target variable as callable or Closure.
     *
     * @param Expr\Assign $assignment - assignment whose enclosing statement's docblock is read
     * @param string      $name       - the assigned variable's name without the dollar-sign prefix
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

        // Require an @var tag for this variable whose type names callable or Closure but not callable-string.
        return preg_match('/@var\s+[^$\n]*\b(?:callable|closure)\b(?!-)[^$\n]*\$' . preg_quote($name, '/') . '\b/i', $docComment->getText()) === 1;
    }

    /**
     * Reports whether a variable name has callable evidence in a scope, following only scopes that can expose it.
     *
     * @param string                                 $name  - Variable name without its dollar-sign prefix.
     * @param FunctionLike|null $scope - function-like the use sits in, or null for file scope
     * @param array<int|string, array<string, true>> $sets - names with callable evidence, keyed by scope
     *
     * @return bool - True for local or inherited callable evidence visible through arrow capture or a closure use clause.
     */
    private function isTrustedName(string $name, ?FunctionLike $scope, array $sets): bool
    {
        // Climb only through the scopes PHP itself lets the name through.
        while (true) {
            if (isset($sets[$this->scopeKey($scope)][$name])) {

                return true;
            }

            // An arrow function captures its parent scope by value, so callable evidence applies unless a parameter
            // shadows the name.
            if ($scope instanceof Expr\ArrowFunction && !$this->hasParameterNamed($scope, $name)) {
                $scope = $this->parentScope($scope);
                continue;
            }

            // A closure inherits callable evidence only for names imported by its use clause.
            if ($scope instanceof Expr\Closure && $this->isImportedByClosure($scope, $name)) {
                $scope = $this->parentScope($scope);
                continue;
            }

            // A named function, a method, a closure that does not import the name, or the file scope ends the climb.
            return false;
        }
    }

    /**
     * Reports whether a recognized enclosing condition supplies callable evidence before a variable call.
     *
     * @param Expr\FuncCall $call - Dynamic call through the named local.
     * @param string        $name - Callee variable name without its dollar-sign prefix.
     *
     * @return bool - True when a recognized if/elseif, ternary, or conjunction guard supplies callable evidence.
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
                // A guard outside a function-like cannot supply evidence for a name that the function-like does not capture.
                return false;
            }

            $child  = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Reports whether one ancestor runs a child only after a condition supplies callable evidence.
     *
     * @param Node $parent - ancestor of the call, one step at a time
     * @param Node   $child  - Node on the call path that the parent directly contains.
     * @param string $name   - Variable name without its dollar-sign prefix.
     *
     * @return bool - True for a guarded body, ternary true arm, or conjunction right operand testing the same variable.
     */
    private function isGuardedBranch(Node $parent, Node $child, string $name): bool
    {
        if ($parent instanceof Stmt\If_ || $parent instanceof Stmt\ElseIf_) {
            // Only the guarded body runs after the condition held; the condition and else branches do not.
            return in_array($child, $parent->stmts, true) && $this->isNarrowedToCallable($parent->cond, $name);
        }

        if ($parent instanceof Expr\Ternary) {
            // The ternary true arm is accepted only when its condition supplies evidence for the same variable.
            return $parent->if === $child && $this->isNarrowedToCallable($parent->cond, $name);
        }

        if ($parent instanceof Expr\BinaryOp\BooleanAnd || $parent instanceof Expr\BinaryOp\LogicalAnd) {
            // The conjunction's right operand is accepted only when its left operand supplies evidence for the same variable.
            return $parent->right === $child && $this->isNarrowedToCallable($parent->left, $name);
        }

        return false;
    }

    /**
     * Reports whether a condition supplies callable evidence for a named variable.
     *
     * @param Expr   $condition - If, ternary, or conjunction condition inspected by the rule.
     * @param string $name      - Variable name without its dollar-sign prefix.
     *
     * @return bool - True for a recognized class test, is_callable() check, or conjunction containing either.
     */
    private function isNarrowedToCallable(Expr $condition, string $name): bool
    {
        if ($condition instanceof Expr\BinaryOp\BooleanAnd || $condition instanceof Expr\BinaryOp\LogicalAnd) {
            // When a conjunction is true, either operand can supply the rule's callable evidence.
            return $this->isNarrowedToCallable($condition->left, $name) || $this->isNarrowedToCallable($condition->right, $name);
        }

        if ($condition instanceof Expr\Instanceof_) {
            // A class test on the same variable rules out a string global-function target.
            return $condition->class instanceof Name && $this->isVariableNamed($condition->expr, $name);
        }

        if (
            $condition instanceof Expr\FuncCall
            && $condition->name instanceof Name
            && strtolower($condition->name->getLast()) === 'is_callable'
            && count($condition->args) === 1
        ) {
            // A one-argument is_callable() check supplies evidence for the same variable; a second argument can accept any string.
            $firstArg = SecurityNodeHelper::sinkArgumentValue($condition, 0);

            return $firstArg !== null && $this->isVariableNamed($firstArg, $name);
        }

        return false;
    }

    /**
     * Reports whether an expression is the plain variable with a given name.
     *
     * @param Expr   $expr - Expression to inspect.
     * @param string $name - Variable name without its dollar-sign prefix.
     *
     * @return bool - True only for that plain variable; property fetches and computed variable names are false.
     */
    private function isVariableNamed(Expr $expr, string $name): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === $name;
    }

    /**
     * Reports whether a closure imports a variable through its use clause.
     *
     * @param Expr\Closure $closure - Closure whose use list is read.
     * @param string       $name    - Variable name without its dollar-sign prefix.
     *
     * @return bool - True when the use clause lists the named variable by value or by reference.
     */
    private function isImportedByClosure(Expr\Closure $closure, string $name): bool
    {

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
     * @param FunctionLike $functionLike - Function-like whose parameter list is read.
     * @param string       $name         - Variable name without its dollar-sign prefix.
     *
     * @return bool - True when a parameter binds the name and hides the parent-scope variable of that name.
     */
    private function hasParameterNamed(FunctionLike $functionLike, string $name): bool
    {

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
     * @return string|null - Literal property name regardless of receiver; null for other expressions or computed property names.
     */
    private function staticPropertyName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            return null;
        }

        return $expr->name->toString();
    }

    /**
     * Reports whether the rule finds request-input syntax in a callee expression.
     *
     * @param Expr          $target - Callee or argument passed to Closure::fromCallable().
     * @param Expr\FuncCall $call   - Dynamic call that owns the callee.
     *
     * @return bool - True for request-input syntax unless a plain variable's latest local assignment is a closure or arrow function.
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
     * Returns the latest source-ordered assignment in the call's own function.
     *
     * @param string        $name - Variable name without its dollar-sign prefix.
     * @param Expr\FuncCall $call - Dynamic call whose function body is searched.
     *
     * @return Expr\Assign|null - Latest same-function assignment starting earlier in source; null at file scope or when none is found.
     */
    private function latestAssignmentBefore(string $name, Expr\FuncCall $call): ?Expr\Assign
    {
        $scope      = SecurityNodeHelper::enclosingFunctionLike($call);
        $statements = $scope?->getStmts();
        if ($statements === null) {
            return null;
        }

        $latest = null;

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
     * @param Node $node - property, assignment, call, or foreach statement whose owning class is needed
     *
     * @return int|string - the enclosing class-like's object id, or the file-scope key when there is none
     */
    private function classKey(Node $node): int|string
    {
        $current = $node->getAttribute('parent');

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
     * Reports whether the rule accepts an expression as local callable evidence.
     *
     * @param Expr $expr - Assignment value or dynamic-call callee inspected syntactically.
     *
     * @return bool - True for supported syntax; receiver invokability and method existence are not verified here.
     */
    private function isCallableExpression(Expr $expr): bool
    {
        // String function names are excluded because unresolved names must not suppress a finding.
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
     * @return bool - True for internal function references without callback evidence, or named methods except invoke/invokeArgs; false otherwise.
     */
    private function isStaticFirstClassCallable(Expr $expr): bool
    {
        if (!$expr instanceof Expr\CallLike || !$expr->isFirstClassCallable()) {
            // First-class callable syntax creates a Closure without running the target.
            return false;
        }

        if ($expr instanceof Expr\FuncCall) {
            // Accept a function reference only for an internal function with no recognized callback parameter.
            // Dispatch helpers could invoke a caller-supplied target.
            return $expr->name instanceof Name && $this->isNonDispatchingInternalFunction($expr->name->toString());
        }


        return ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall)
            && $expr->name instanceof Identifier
            && !in_array(strtolower($expr->name->toString()), self::DISPATCHING_METHODS, true);
    }

    /**
     * Checks whether a runtime-internal function has no recognized callback parameters.
     *
     * @param string $name - function name as written, without a leading backslash
     *
     * @return bool - True if the function exists, is internal, and has no recognized callback parameters; false otherwise.
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


        foreach ($function->getParameters() as $parameter) {
            if ($this->isCallableParameter($parameter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks a reflected parameter for callback evidence from its type and name.
     *
     * @param \ReflectionParameter $parameter - one parameter of an internal function
     *
     * @return bool - True for callable/Closure members, or when a member is treated as mixed and the parameter name contains callback.
     */
    private function isCallableParameter(\ReflectionParameter $parameter): bool
    {
        $type    = $parameter->getType();
        $members = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];


        foreach ($members as $member) {
            $typeName = $member instanceof \ReflectionNamedType ? strtolower($member->getName()) : 'mixed';
            if (in_array($typeName, ['callable', 'closure'], true)) {
                return true;
            }

            // For a parameter treated as mixed by this heuristic, its name is the only callback evidence available here.
            if ($typeName === 'mixed' && str_contains(strtolower($parameter->getName()), 'callback')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether an expression has two array items and a literal method name in the second item.
     *
     * @param Expr $expr - expression to test for the array-callable shape
     *
     * @return bool - True for two present, non-unpacked items with a literal string second value; receiver validity is not checked.
     */
    private function isStaticCallableArray(Expr $expr): bool
    {
        if (!$expr instanceof Expr\Array_ || count($expr->items) !== 2) {

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
     * Reports whether tracked evidence supports treating a foreach subject as a callable collection.
     *
     * @param Expr                                   $expr                 - the subject expression from the foreach loop
     * @param Node                                   $context              - the foreach, whose scope and class resolve names
     * @param array<int|string, array<string, true>> $collections          - variables tracked as callable collections in each scope
     * @param array<int|string, array<string, true>> $collectionProperties - properties tracked as callable collections in each class
     *
     * @return bool - True when the rule carries tracked collection evidence through the expression; false leaves the loop value untrusted.
     */
    private function isCallableCollectionExpression(Expr $expr, Node $context, array $collections, array $collectionProperties): bool
    {
        $propertyName = $this->staticPropertyName($expr);
        if ($propertyName !== null) {
            // A statically named property is matched against its own class's callable collections.
            return isset($collectionProperties[$this->classKey($context)][$propertyName]);
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            // A variable carries collection evidence when its scope, or one it can see, tracks it.
            return $this->isTrustedName($expr->name, SecurityNodeHelper::enclosingFunctionLike($context), $collections);
        }

        if ($expr instanceof Expr\Cast\Array_) {
            // The heuristic carries existing collection evidence through an array cast.
            return $this->isCallableCollectionExpression($expr->expr, $context, $collections, $collectionProperties);
        }

        $wrapped = $this->wrappedCollectionArgument($expr);

        // Recognized wrapper syntax carries the first argument's collection evidence into the loop.
        return $wrapped !== null && $this->isCallableCollectionExpression($wrapped, $context, $collections, $collectionProperties);
    }

    /**
     * Returns the first argument when the syntax names a recognized collection wrapper.
     *
     * @param Expr $expr - candidate wrapper call
     *
     * @return Expr|null - First argument of a recognized wrapper; null for other expressions, first-class references, or no argument.
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

        // Other calls may transform the values, so this heuristic does not carry collection evidence through them.
        if ((!$isArrWrap && !$isWrapperFunction) || !$expr instanceof Expr\CallLike || $expr->isFirstClassCallable()) {
            return null;
        }

        $firstArg = $expr->getArgs()[0] ?? null;

        return $firstArg?->value;
    }

    /**
     * Reports whether declared type syntax matches the rule's callable heuristic, recursing through union and
     * nullable wrappers so callable|null or ?Closure still count.
     *
     * @param Node|null $type - the parameter or property type node, or null when the declaration is untyped
     *
     * @return bool - True when the type syntax contains a callable keyword or recognized callable class name; false otherwise.
     */
    private function isCallableType(?Node $type): bool
    {
        if ($type instanceof Identifier) {
            // A built-in type hint counts only when it is the literal callable keyword.
            return strtolower($type->toString()) === 'callable';
        }

        // A class-name hint counts when it names Closure or a *Callable convention class.
        if ($type instanceof Name) {
            $shortName = strtolower($type->getLast());

            // Match on the short name only (namespace ignored): Closure, or any ...Callable convention class.
            return $shortName === 'closure' || str_ends_with($shortName, 'callable');
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $innerType) {
                if ($this->isCallableType($innerType)) {
                    // One callable member is enough for this permissive type heuristic.
                    return true;
                }
            }
        }

        if ($type instanceof Node\NullableType) {
            // This heuristic follows the inner type through nullable syntax.
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
     * @param Node $node - the offending node (call or eval) whose start line locates the report
     * @param string $function - Pattern label used in both the message and the function metadata key.
     *
     * @return Finding - Advisory finding with fixed security classification and the offending node's start line.
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
