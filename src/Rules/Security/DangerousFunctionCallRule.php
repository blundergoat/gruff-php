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
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Flags calls into PHP's execution and evaluation surface - immutable built-ins such as `exec`,
 * `shell_exec`, and `system`, project-added global functions, `eval`, `assert('...')`, and dynamic
 * `$callable()` invocations - so a reviewer can confirm each one runs trusted input rather than
 * attacker-controlled data (command injection / arbitrary code execution).
 *
 * Runs per file. To keep noise down it first learns which local variables, properties, and parameters
 * are known callables (closures, arrow functions, `[obj, 'method']` arrays, or callable type hints and
 * docblocks) and trusts calls through those, flagging only unproven dynamic targets. Warning severity,
 * medium confidence - a name match is evidence for review, not a proven vulnerability.
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
                    'mitigation' => 'Callable provenance is proven only from this file, so add a callable type hint or assign the closure locally to make the target visible.',
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
        $callableParameters = $this->callableParameterNames($analysisUnit);
        $callableProperties = $this->callablePropertyNames($analysisUnit);
        $callableLocals     = array_replace(
            $this->callableLocalVariableNames($analysisUnit),
            $this->callableForeachVariableNames($analysisUnit),
        );

        // Weigh every function call for a dangerous or unresolved dynamic callee.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // An unresolved callee name means this is a dynamic call worth scrutinising.
            if ($name === null) {
                // Flag it unless the target is a plain name or a slot proven to hold a callable.
                if (!$call->name instanceof Node\Name && !$this->isKnownCallableInvocation($call->name, $callableParameters, $callableProperties, $callableLocals)) {
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
     * Collects the local variables assigned a closure, arrow function, or static callable array.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for `$x = fn/closure/[obj, 'method']` assignments
     *
     * @return array<string, true> - set of variable names known to hold callables, used as a name allow-list
     *   so a later `$x(...)` is not flagged as an unknown dynamic call; values are always true (set semantics)
     */
    private function callableLocalVariableNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        // Weigh every assignment for a variable that receives a callable.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
            // Skip assignments that do not bind a plain variable to a callable expression.
            if (
                !$assignment->var instanceof Expr\Variable
                || !is_string($assignment->var->name)
                || !$this->isCallableExpression($assignment->expr)
            ) {
                continue;
            }

            $names[$assignment->var->name] = true;
        }

        // Last write wins by name; reassignment to a non-callable is untracked, so this only ever over-suppresses.
        return $names;
    }

    /**
     * Collects the foreach value variables that iterate a property known to hold callables.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for `foreach ($this->handlers as $h)` loops
     *
     * @return array<string, true> - set of loop-variable names treated as callables, so invoking `$h(...)`
     *   inside the loop body is not flagged; values are always true (set semantics)
     */
    private function callableForeachVariableNames(AnalysisUnit $analysisUnit): array
    {
        $names                        = [];
        $callableCollectionProperties = $this->callableCollectionPropertyNames($analysisUnit);

        // Weigh every foreach for a loop over a callable-bearing property.
        foreach (NodeIndex::nodesOf($analysisUnit, Foreach_::class) as $foreach) {
            // Only a plainly named value variable can be tracked as a callable.
            if (!$foreach->valueVar instanceof Expr\Variable || !is_string($foreach->valueVar->name)) {
                continue;
            }

            // Skip loops whose subject is not a known callable collection.
            if (!$this->isCallableCollectionExpression($foreach->expr, $callableCollectionProperties)) {
                continue;
            }

            $names[$foreach->valueVar->name] = true;
        }

        // Only the value variable is tracked; key and destructured forms fall through and stay subject to flagging.
        return $names;
    }

    /**
     * Collects the properties populated as callable collections via `$this->prop[] = fn(...)`.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for array-append writes of callables onto a property
     *
     * @return array<string, true> - set of property names whose elements are callables; feeds
     *   callableForeachVariableNames so a foreach over one of them is recognised; values are always true
     */
    private function callableCollectionPropertyNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        // Weigh every assignment for a callable appended to a property array.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
            // Only an array-append of a callable onto a property qualifies.
            if (
                !$assignment->var instanceof Expr\ArrayDimFetch
                || !$assignment->var->var instanceof Expr\PropertyFetch
                || !$assignment->var->var->name instanceof Identifier
                || !$this->isCallableExpression($assignment->expr)
            ) {
                continue;
            }

            $names[$assignment->var->var->name->toString()] = true;
        }

        // A single callable append marks the whole property; we do not require every element to be callable.
        return $names;
    }

    /**
     * Collects the function and method parameters declared with a callable-like type hint.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for `callable`/`Closure`/`*Callable`-typed parameters
     *
     * @return array<string, true> - set of parameter names that arrive already typed as callables, so a call
     *   through one is trusted rather than flagged; names are not scoped per-function, so a callable name in
     *   one method suppresses the same name everywhere in the file (a deliberate over-suppress to avoid noise)
     */
    private function callableParameterNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        // Weigh every function and method for callable-typed parameters.
        foreach (NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class]) as $function) {
            // Guard the loose node type before reading its parameter list.
            if (!$function instanceof Function_ && !$function instanceof ClassMethod) {
                continue;
            }

            // Inspect each parameter for a callable type hint.
            foreach ($function->params as $param) {
                // Record only plainly named parameters that are typed as callable.
                if (!$param->var instanceof Expr\Variable || !is_string($param->var->name) || !$this->isCallableType($param->type)) {
                    continue;
                }

                $names[$param->var->name] = true;
            }
        }

        // File-wide name set, not per-scope: shadowing is rare here and a missed flag beats a false positive.
        return $names;
    }

    /**
     * Collects the properties that hold callables, via a callable type hint, a `@var callable` docblock, or
     * a promoted constructor parameter typed as callable.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for callable-typed or callable-documented properties
     *
     * @return array<string, true> - set of property names treated as callables, so `$this->prop(...)` is
     *   trusted; values are always true (set semantics)
     */
    private function callablePropertyNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        // Weigh every declared property for a callable type or docblock.
        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            // Skip properties that are neither callable-typed nor callable-documented.
            if (!$this->isCallableType($property->type) && !$this->hasCallableDocblock($property)) {
                continue;
            }

            // One declaration can name several properties; record each.
            foreach ($property->props as $prop) {
                $names[$prop->name->toString()] = true;
            }
        }

        // Also weigh promoted constructor parameters, which become properties.
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

                    $names[$param->var->name] = true;
                }
            }
        }

        // Both promoted-constructor params and declared properties land in one name set; first match is enough.
        return $names;
    }

    /**
     * Reports whether a dynamic call target ($var() or $this->prop()) resolves to a slot already
     * proven to hold a callable, which is the gate that turns a finding off.
     *
     * @param Node                $name               - the callee expression from a FuncCall whose
     *                                                `name` is not a plain function Name; only Variable and PropertyFetch forms can be vindicated
     * @param array<string, true> $callableParameters - callable-typed parameter names from the file
     * @param array<string, true> $callableProperties - callable-typed or callable-documented property names
     * @param array<string, true> $callableLocals     - local and foreach variable names assigned callables
     *
     * @return bool - true means "trusted callable, do not flag"; false means the target is unproven and the
     *   caller should record a dynamic-call finding (false is the safe default, not a positive denial)
     */
    private function isKnownCallableInvocation(Node $name, array $callableParameters, array $callableProperties, array $callableLocals): bool
    {
        if ($name instanceof Expr\Variable && is_string($name->name)) {
            // A bare `$var()` is trusted if the variable was either typed as callable (param) or assigned one (local).
            return isset($callableParameters[$name->name]) || isset($callableLocals[$name->name]);
        }

        if ($name instanceof Expr\PropertyFetch && $name->name instanceof Identifier) {
            // A call through a property is trusted only when that property is known callable; dynamic names cannot be.
            return isset($callableProperties[$name->name->toString()]);
        }

        // Any other callee shape (array access, method chain, ...) stays unproven and must be flagged.
        return false;
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

        // Match @var annotations whose declared type is callable-like.
        return preg_match('/@var\s+[^\n]*(?:callable|Closure)\b/i', $docComment->getText()) === 1;
    }

    /**
     * Reports whether an assigned expression definitely produces a callable, so the assignment target
     * can be added to a trusted-callable name set.
     *
     * @param Expr $expr - right-hand side of an assignment to classify
     *
     * @return bool - true only for syntactic callables visible at this node: a closure, an arrow function,
     *   or a `[receiver, 'method']` array; a string function name or a returned callable reads as false here
     *   (deliberately strict, to keep the trusted set to forms we can prove)
     */
    private function isCallableExpression(Expr $expr): bool
    {
        // Strings like 'strlen' are intentionally excluded: a name we cannot resolve should not silence a finding.
        return $expr instanceof Expr\Closure
            || $expr instanceof Expr\ArrowFunction
            || $this->isStaticCallableArray($expr);
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

        $methodItem = $expr->items[1] ?? null;

        // The method element must be a plain string literal and not spread; a dynamic method name is not provable here.
        return $methodItem !== null
            && !$methodItem->unpack
            && $methodItem->value instanceof Scalar\String_;
    }

    /**
     * Reports whether a foreach subject is a property already known to hold callables, so the loop
     * value variable can be trusted as callable.
     *
     * @param Expr                $expr                         - the `foreach (... as $v)` subject expression
     * @param array<string, true> $callableCollectionProperties - property names proven to contain callables,
     *                                                          as produced by callableCollectionPropertyNames
     *
     * @return bool - true when the subject is `$this->prop` for a known-callable property; false for any other
     *   subject (a local array, a method call, a dynamic property name), leaving the loop variable untrusted
     */
    private function isCallableCollectionExpression(Expr $expr, array $callableCollectionProperties): bool
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            // Only a statically named property fetch can be matched against the known-callable set.
            return false;
        }

        // True only when this exact property was recorded as holding callables; unknown properties stay untrusted.
        return isset($callableCollectionProperties[$expr->name->toString()]);
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
