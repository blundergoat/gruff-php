<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;

/**
 * Provides shared AST helpers for security rules.
 */
final class SecurityNodeHelper
{
    /**
     * List PHP superglobals treated as request-controlled input.
     *
     * @return list<string>
     */
    public static function userInputSuperglobals(): array
    {
        // The canonical set every taint check treats as attacker-controlled; keep $_ENV/$_SESSION out by design.
        return ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_FILES'];
    }

    /**
     * Resolve a non-namespaced function call to its lower-case name.
     *
     * @param FuncCall $call Function call node to inspect.
     * @return string|null Function name, or null for dynamic or namespaced calls.
     */
    public static function globalFunctionName(FuncCall $call): ?string
    {
        if (!$call->name instanceof Name) {
            // A dynamic callee (variable/expression) has no static name to match against the rule's allowlist.
            return null;
        }

        $parts = $call->name->getParts();
        if (count($parts) !== 1) {
            // Namespaced calls are not the global builtins these rules guard, so they never match.
            return null;
        }

        // Lower-case because PHP function names are case-insensitive; callers compare against lower-case literals.
        return strtolower($parts[0]);
    }

    /**
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args  Call argument nodes to inspect.
     * @param int                                                  $index Zero-based argument index.
     * @return Expr|null Argument expression at the requested index, or null when absent.
     */
    public static function argumentValue(array $args, int $index): ?Expr
    {
        $arg = $args[$index] ?? null;
        if (!$arg instanceof Node\Arg) {
            // Missing slot or a variadic spread placeholder: no concrete argument expression to hand back.
            return null;
        }

        // Unwrap to the bare value expression so callers inspect the argument, not its Arg wrapper.
        return $arg->value;
    }

    /**
     * Resolve a constant fetch to its normalized constant name.
     *
     * @param Node $node Node to inspect.
     * @return string|null Constant name, or null for unsupported expressions.
     */
    public static function constantName(Node $node): ?string
    {
        if (!$node instanceof Expr\ConstFetch) {
            // Only a bare constant fetch carries a name; anything else has no constant to normalise.
            return null;
        }

        $parts = $node->name->getParts();
        if (count($parts) !== 1) {
            // Namespaced constants are out of scope for these flag checks, so report no name.
            return null;
        }

        // Upper-case because PHP constant names are case-sensitive but callers compare against UPPER literals.
        return strtoupper($parts[0]);
    }

    /**
     * Determine whether a node statically represents a false-like value.
     *
     * @param Node $node Node to inspect.
     * @return bool True when the node is literally false or integer zero.
     */
    public static function isFalseLike(Node $node): bool
    {
        if ($node instanceof Expr\ConstFetch) {
            // The literal `false` keyword (case-insensitive) is the disabled-flag value rules look for.
            return strtolower($node->name->toString()) === 'false';
        }

        if ($node instanceof Scalar\Int_) {
            // Integer 0 is the other common "off" literal, e.g. CURLOPT_SSL_VERIFYPEER set to 0.
            return $node->value === 0;
        }

        // Variables, constants, and computed values are not statically known to be false-like.
        return false;
    }

    /**
     * Detect whether an expression reads from user-input superglobals.
     *
     * Also follows simple same-scope local assignments before the inspected
     * expression so sinks catch `$next = $_GET["next"]; header($next);`.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when the node tree contains request-sourced input.
     */
    public static function containsUserInput(Node $node): bool
    {
        if (self::containsDirectUserInput($node)) {
            // A direct superglobal read is request-tainted with no further analysis needed.
            return true;
        }

        // Otherwise fall back to the costlier same-scope flow analysis for laundered locals.
        return self::containsTaintedLocal($node);
    }

    /**
     * Detect direct reads from request superglobals within a node tree.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when the node tree directly contains request-sourced input.
     */
    private static function containsDirectUserInput(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        // True when any node in the tree is a superglobal read; presence of a match is the taint signal.
        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            // A variable whose name is one of the request superglobals is the attacker-controlled source.
            return $candidate instanceof Expr\Variable
                && is_string($candidate->name)
                && in_array($candidate->name, self::userInputSuperglobals(), true);
        }) instanceof Node;
    }

    /**
     * Detect local variables that were assigned request data earlier in the same scope.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when the node references a same-scope tainted local variable.
     */
    private static function containsTaintedLocal(Node $node): bool
    {
        $referencedVariables = self::referencedVariableNames($node);
        if ($referencedVariables === []) {
            // No local variables to trace, so there is nothing that could have been laundered from a superglobal.
            return false;
        }

        $scope = self::enclosingFunctionLike($node);
        if (!$scope instanceof FunctionLike) {
            // Without an owning function body the same-scope flow analysis has no statements to walk.
            return false;
        }

        $statements = $scope->getStmts();
        if ($statements === null) {
            // Abstract or interface methods have no body, so no assignment could taint a local here.
            return false;
        }

        $sinkPosition = $node->getStartFilePos();
        if ($sinkPosition < 0) {
            // A missing byte offset means we cannot order assignments before the sink; stay safe and bail.
            return false;
        }

        $taintedVariables = self::taintedVariableNamesBefore(array_values($statements), $scope, $sinkPosition);

        foreach ($referencedVariables as $variableName) {
            if (isset($taintedVariables[$variableName])) {
                // The sink reads a local that an earlier same-scope assignment filled from request input.
                return true;
            }
        }

        // None of the referenced locals were tainted before the sink, so the expression is clean.
        return false;
    }

    /**
     * @param list<Node\Stmt> $statements
     * @param FunctionLike    $scope        Scope that owns the sink expression.
     * @param int             $sinkPosition Byte offset of the sink expression.
     * @return array<string, true>
     */
    private static function taintedVariableNamesBefore(array $statements, FunctionLike $scope, int $sinkPosition): array
    {
        $taintedVariables = [];
        $nodeFinder       = new NodeFinder();
        $assignments      = $nodeFinder->find(
            $statements,
            static fn (Node $candidate): bool => $candidate instanceof Expr\Assign
                && $candidate->getStartFilePos() >= 0
                && $candidate->getStartFilePos() < $sinkPosition,
        );

        foreach ($assignments as $assignment) {
            if (!$assignment instanceof Expr\Assign) {
                continue;
            }

            if (self::enclosingFunctionLike($assignment) !== $scope) {
                continue;
            }

            if (!$assignment->var instanceof Expr\Variable || !is_string($assignment->var->name)) {
                continue;
            }

            $variableName = $assignment->var->name;
            if (
                self::containsDirectUserInput($assignment->expr)
                || self::hasTaintedVariableReference($assignment->expr, $taintedVariables)
            ) {
                $taintedVariables[$variableName] = true;
                continue;
            }

            unset($taintedVariables[$variableName]);
        }

        // Taint state evaluated at the sink: because assignments are walked in source order, the last write
        // to a variable wins, so a clean reassignment that overwrote earlier request data leaves it absent.
        return $taintedVariables;
    }

    /**
     * Check whether a node references a known tainted variable.
     *
     * @param Node                $node             Node tree to inspect.
     * @param array<string, true> $taintedVariables
     * @return bool True when the node references any known tainted variable.
     */
    private static function hasTaintedVariableReference(Node $node, array $taintedVariables): bool
    {
        foreach (self::referencedVariableNames($node) as $variableName) {
            if (isset($taintedVariables[$variableName])) {
                // The expression reads an already-tainted local, so taint propagates to this assignment.
                return true;
            }
        }

        // The expression touches no known-tainted local, so it does not carry request input forward.
        return false;
    }

    /**
     * Return non-superglobal variable names referenced by a node tree.
     *
     * @param Node $node Node tree to inspect.
     * @return list<string>
     */
    private static function referencedVariableNames(Node $node): array
    {
        $names      = [];
        $nodeFinder = new NodeFinder();
        $variables  = $nodeFinder->find($node, static fn (Node $candidate): bool => $candidate instanceof Expr\Variable);

        foreach ($variables as $variable) {
            if (!$variable instanceof Expr\Variable) {
                continue;
            }

            if (is_string($variable->name) && !in_array($variable->name, self::userInputSuperglobals(), true)) {
                $names[$variable->name] = true;
            }
        }

        // Deduplicated local names only; superglobals are excluded since they are sources, not laundered locals.
        return array_keys($names);
    }

    /**
     * Find the function, method, or closure scope containing a node.
     *
     * @param Node $node Node whose containing function-like scope is needed.
     * @return FunctionLike|null Containing scope, or null outside function-like code.
     */
    private static function enclosingFunctionLike(Node $node): ?FunctionLike
    {
        $current = $node;
        while ($current instanceof Node) {
            if ($current instanceof FunctionLike) {
                // The nearest enclosing function/method/closure; this is the scope taint analysis is bounded to.
                return $current;
            }

            $parent  = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

        // Walked past the top of the tree without a function-like ancestor: the node lives at file scope.
        return null;
    }

    /**
     * Detect string construction patterns that can hide unsafe concatenation.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when concatenation or interpolation appears in the node tree.
     */
    public static function containsConcatOrInterpolation(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        // True when any node builds a string by joining parts, the shape that can splice untrusted data in.
        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            // A `.` concatenation or a "$var" interpolated string is the dynamic-construction signal.
            return $candidate instanceof Expr\BinaryOp\Concat
                || $candidate instanceof Scalar\Encapsed;
        }) instanceof Node;
    }

    /**
     * Identify literal string nodes for security-rule exemptions.
     *
     * @param Node $node Node to inspect.
     * @return bool True when the node is a string scalar.
     */
    public static function isStringLiteral(Node $node): bool
    {
        // A bare string literal is statically trusted, so rules use this to exempt constant arguments.
        return $node instanceof Scalar\String_;
    }

    /**
     * Build the display name used when reporting a function call.
     *
     * @param FuncCall $call Function call node to describe.
     * @return string Function name or dynamic-call fallback label.
     */
    public static function functionNameForMessage(FuncCall $call): string
    {
        $name = self::globalFunctionName($call);

        // Fall back to a human label when the callee is dynamic, so finding messages never show an empty name.
        return $name ?? 'dynamic function call';
    }

    /**
     * Resolve a method name to its lower-case string when statically known.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call Call node to inspect.
     * @return string|null Method name, or null for dynamic method calls.
     */
    public static function methodName(Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        if (!$call->name instanceof Identifier) {
            // A computed method name ($obj->$method()) cannot be matched statically, so report none.
            return null;
        }

        // Lower-case because method names are case-insensitive in PHP; callers compare against lower-case literals.
        return strtolower($call->name->toString());
    }

    /**
     * Resolve a class node to a lower-case class name when statically known.
     *
     * @param Node $class Class node from a new/static call.
     * @return string|null Resolved class name, or null for dynamic/anonymous classes.
     */
    public static function className(Node $class): ?string
    {
        if (!$class instanceof Name) {
            // Anonymous classes and dynamic `new $cls` have no static name to resolve.
            return null;
        }

        $resolvedName = $class->getAttribute('resolvedName');
        if ($resolvedName instanceof Name) {
            // Prefer the import/namespace-resolved FQCN so short aliases match their fully-qualified target.
            return strtolower($resolvedName->toString());
        }

        // No resolver attribute (unresolved pass): fall back to the name as written in source.
        return strtolower($class->toString());
    }

    /**
     * Match a class node against exact FQCNs or short class names.
     *
     * @param Node         $class      Class node from a new/static call.
     * @param list<string> $classNames FQCNs or short class names to match.
     * @return bool True when the class matches one of the supplied names.
     */
    public static function hasMatchingClassName(Node $class, array $classNames): bool
    {
        $resolvedName = self::className($class);
        if ($resolvedName === null) {
            // An unresolvable class name can never equal a configured target, so it cannot match.
            return false;
        }

        foreach ($classNames as $className) {
            $normalized = strtolower(ltrim($className, '\\'));
            if ($resolvedName === $normalized) {
                // Exact FQCN match against a configured fully-qualified target.
                return true;
            }

            if (!str_contains($normalized, '\\') && str_ends_with($resolvedName, '\\' . $normalized)) {
                // Short-name target: match any namespace whose final segment equals it (the `\Foo` suffix).
                return true;
            }
        }

        // None of the supplied names matched the resolved class.
        return false;
    }

    /**
     * Detect whether a node tree contains an HTTP(S) literal.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when an HTTP or HTTPS string literal appears.
     */
    public static function containsUrlLiteral(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        // True when any string literal in the tree is an outbound URL, used to gate URL-only sinks.
        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            if (!$candidate instanceof Scalar\String_) {
                // Only literal strings can be inspected for a scheme; non-literals are never a URL match here.
                return false;
            }

            // Match literal outbound URL strings for URL-only sinks.
            return preg_match('/^https?:\/\//i', $candidate->value) === 1;
        }) instanceof Node;
    }

    /**
     * Detect whether an expression references likely sensitive data.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when variable names, string keys, or env reads carry secret context.
     */
    public static function containsSensitiveReference(Node $node): bool
    {
        $nodeFinder = new NodeFinder();

        // True when any node in the tree names a secret; each branch maps a node kind to its identifier text.
        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            if ($candidate instanceof Expr\Variable && is_string($candidate->name)) {
                // A variable name like $apiKey signals secret context.
                return self::hasSensitiveContext($candidate->name);
            }

            if ($candidate instanceof Expr\PropertyFetch && $candidate->name instanceof Identifier) {
                // A property access like $config->secret signals secret context.
                return self::hasSensitiveContext($candidate->name->toString());
            }

            if ($candidate instanceof Expr\ArrayDimFetch && $candidate->dim instanceof Scalar\String_) {
                // A literal array key like $env['password'] signals secret context.
                return self::hasSensitiveContext($candidate->dim->value);
            }

            if ($candidate instanceof Scalar\String_) {
                // A bare string literal whose text itself names a secret signals secret context.
                return self::hasSensitiveContext($candidate->value);
            }

            if ($candidate instanceof Expr\FuncCall) {
                // A call may be an env reader pulling a secret-named key; defer to that check.
                return self::isSensitiveEnvironmentRead($candidate);
            }

            // Node kinds we do not classify carry no secret context.
            return false;
        }) instanceof Node;
    }

    /**
     * Detect env-reader calls that request a sensitive key.
     *
     * @param FuncCall $call Call node to inspect; only env readers (getenv/env/apache_getenv) are considered.
     * @return bool True when getenv/env reads a secret-like key.
     */
    private static function isSensitiveEnvironmentRead(FuncCall $call): bool
    {
        $name = self::globalFunctionName($call);
        if ($name === null || !in_array($name, ['apache_getenv', 'env', 'getenv'], true)) {
            // Not one of the recognised env readers, so it cannot be a sensitive environment lookup.
            return false;
        }

        $firstArg = self::argumentValue($call->args, 0);
        if ($firstArg === null) {
            // A no-argument getenv() returns the whole environment, which is treated as sensitive wholesale.
            return true;
        }

        // A literal key argument is sensitive only when its text matches the secret-name pattern.
        return $firstArg instanceof Scalar\String_ && self::hasSensitiveContext($firstArg->value);
    }

    /**
     * Detect secret-like words in identifiers or string keys.
     *
     * @param string $contextText Identifier or string-key text to scan; the value itself, never a read secret.
     * @return bool True when the text names likely sensitive data.
     */
    private static function hasSensitiveContext(string $contextText): bool
    {
        // Match secret-like identifier and string-key fragments without reading values into findings.
        return preg_match('/(?:api[_-]?key|auth(?:orization)?|cookie|pass(?:word|wd)?|private[_-]?key|secret|token)/i', $contextText) === 1;
    }
}
