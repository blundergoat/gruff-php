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
 * Detects calls to execution and evaluation functions with high security risk.
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
     * Describe the dangerous function call rule.
     *
     * @return RuleDefinition - the registry entry the engine keys this rule by; severity stays Warning
     *   (not Error) because a flagged call may be a legitimate constrained wrapper a human must judge
     */
    public function definition(): RuleDefinition
    {
        // Confidence is Medium, not High: a name match cannot prove the call reaches an attacker-controlled argument.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Dangerous function calls',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find dynamic execution, eval, assert-string, and dangerous shell calls.
     *
     * @param AnalysisUnit $analysisUnit - single parsed file the caller wants scanned; its AST and token
     *   stream are the only source consulted, so cross-file callable definitions are invisible here
     * @param RuleContext  $ruleContext - shared per-run context; this rule reads no settings from it but
     *   the interface requires it, so callers pass the same instance used for every rule in the pass
     *
     * @return list<Finding> - one Finding per suspicious call site, empty when none match; callers treat
     *   the list as advisory evidence for review, not a proven vulnerability
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings           = [];
        $callableParameters = $this->callableParameterNames($analysisUnit);
        $callableProperties = $this->callablePropertyNames($analysisUnit);
        $callableLocals     = array_replace(
            $this->callableLocalVariableNames($analysisUnit),
            $this->callableForeachVariableNames($analysisUnit),
        );

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null) {
                if (!$call->name instanceof Node\Name && !$this->isKnownCallableInvocation($call->name, $callableParameters, $callableProperties, $callableLocals)) {
                    $findings[] = $this->finding($analysisUnit, $call, 'dynamic function call');
                }

                continue;
            }

            if (in_array($name, self::DANGEROUS_FUNCTIONS, true)) {
                $findings[] = $this->finding($analysisUnit, $call, $name);
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($name === 'assert' && $firstArg !== null && SecurityNodeHelper::isStringLiteral($firstArg)) {
                $findings[] = $this->finding($analysisUnit, $call, 'assert string evaluation');
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Eval_::class) as $eval) {
            $findings[] = $this->finding($analysisUnit, $eval, 'eval');
        }

        // Order is by discovery, not severity: the reporter sorts; emitting here in AST order keeps results stable.
        return $findings;
    }

    /**
     * Find local variables assigned a closure, arrow function, or static callable array.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for `$x = fn/closure/[obj, 'method']` assignments
     *
     * @return array<string, true> - set of variable names known to hold callables, used as a name allow-list
     *   so a later `$x(...)` is not flagged as an unknown dynamic call; values are always true (set semantics)
     */
    private function callableLocalVariableNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
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
     * Find foreach value variables that iterate a property known to hold callables.
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

        foreach (NodeIndex::nodesOf($analysisUnit, Foreach_::class) as $foreach) {
            if (!$foreach->valueVar instanceof Expr\Variable || !is_string($foreach->valueVar->name)) {
                continue;
            }

            if (!$this->isCallableCollectionExpression($foreach->expr, $callableCollectionProperties)) {
                continue;
            }

            $names[$foreach->valueVar->name] = true;
        }

        // Only the value variable is tracked; key and destructured forms fall through and stay subject to flagging.
        return $names;
    }

    /**
     * Find properties populated as callable collections via `$this->prop[] = fn(...)`.
     *
     * @param AnalysisUnit $analysisUnit - file to scan for array-append writes of callables onto a property
     *
     * @return array<string, true> - set of property names whose elements are callables; feeds
     *   callableForeachVariableNames so a foreach over one of them is recognised; values are always true
     */
    private function callableCollectionPropertyNames(AnalysisUnit $analysisUnit): array
    {
        $names = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\Assign::class) as $assignment) {
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
     * Find function and method parameters declared with a callable-like type hint.
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

        foreach (NodeIndex::nodesOfAny($analysisUnit, [Function_::class, ClassMethod::class]) as $function) {
            if (!$function instanceof Function_ && !$function instanceof ClassMethod) {
                continue;
            }

            foreach ($function->params as $param) {
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
     * Find properties that hold callables, via a callable type hint, a `@var callable` docblock, or
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

        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            if (!$this->isCallableType($property->type) && !$this->hasCallableDocblock($property)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $names[$prop->name->toString()] = true;
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

                    $names[$param->var->name] = true;
                }
            }
        }

        // Both promoted-constructor params and declared properties land in one name set; first match is enough.
        return $names;
    }

    /**
     * Decide whether a dynamic call target ($var() or $this->prop()) resolves to a slot already
     * proven to hold a callable, which is the gate that turns a finding off.
     *
     * @param Node                $name - the callee expression from a FuncCall whose
     *   `name` is not a plain function Name; only Variable and PropertyFetch forms can be vindicated
     * @param array<string, true> $callableParameters - callable-typed parameter names from the file
     * @param array<string, true> $callableProperties - callable-typed or callable-documented property names
     * @param array<string, true> $callableLocals - local and foreach variable names assigned callables
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
     * Recognise a property whose type is untyped or too loose for isCallableType but whose `@var`
     * docblock still promises a callable, so the property can join the trusted set.
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
     * Decide whether an assigned expression definitely produces a callable, so the assignment target
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
     * Recognise the two-element array callable form, `[$receiver, 'method']` or
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
     * Decide whether a foreach subject is a property already known to hold callables, so the loop
     * value variable can be trusted as callable.
     *
     * @param Expr                $expr - the `foreach (... as $v)` subject expression
     * @param array<string, true> $callableCollectionProperties - property names proven to contain callables,
     *   as produced by callableCollectionPropertyNames
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
     * Decide whether a declared type node permits callable invocation, recursing through union and
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

        if ($type instanceof Name) {
            $shortName = strtolower($type->getLast());

            // Match on the short name only (namespace ignored): Closure, or any `...Callable` convention class.
            return $shortName === 'closure' || str_ends_with($shortName, 'callable');
        }

        if ($type instanceof Node\UnionType) {
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
     * Assemble the Finding for one flagged call site, fixing severity, pillar, and remediation so every
     * detection path reports identically.
     *
     * @param AnalysisUnit $analysisUnit - unit being scanned; supplies the display path recorded on the finding
     * @param Node         $node - the offending node (call or eval) whose start line locates the report
     * @param string       $function - human-readable label for the pattern (e.g. `exec`, `eval`,
     *   `dynamic function call`); flows into both the message and the `function` metadata key for grouping
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
