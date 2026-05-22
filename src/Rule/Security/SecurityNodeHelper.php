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
     * @return list<string>
     */
    public static function userInputSuperglobals(): array
    {
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
            return null;
        }

        $parts = $call->name->getParts();
        if (count($parts) !== 1) {
            return null;
        }

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
            return null;
        }

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
            return null;
        }

        $parts = $node->name->getParts();
        if (count($parts) !== 1) {
            return null;
        }

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
            return strtolower($node->name->toString()) === 'false';
        }

        if ($node instanceof Scalar\Int_) {
            return $node->value === 0;
        }

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
            return true;
        }

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

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
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
            return false;
        }

        $scope = self::enclosingFunctionLike($node);
        if (!$scope instanceof FunctionLike) {
            return false;
        }

        $statements = $scope->getStmts();
        if ($statements === null) {
            return false;
        }

        $sinkPosition = $node->getStartFilePos();
        if ($sinkPosition < 0) {
            return false;
        }

        $taintedVariables = self::taintedVariableNamesBefore(array_values($statements), $scope, $sinkPosition);

        foreach ($referencedVariables as $variableName) {
            if (isset($taintedVariables[$variableName])) {
                return true;
            }
        }

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
                return true;
            }
        }

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
                return $current;
            }

            $parent = $current->getAttribute('parent');
            $current = $parent instanceof Node ? $parent : null;
        }

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

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
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
            return null;
        }

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
            return null;
        }

        $resolvedName = $class->getAttribute('resolvedName');
        if ($resolvedName instanceof Name) {
            return strtolower($resolvedName->toString());
        }

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
            return false;
        }

        foreach ($classNames as $className) {
            $normalized = strtolower(ltrim($className, '\\'));
            if ($resolvedName === $normalized) {
                return true;
            }

            if (!str_contains($normalized, '\\') && str_ends_with($resolvedName, '\\' . $normalized)) {
                return true;
            }
        }

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

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            if (!$candidate instanceof Scalar\String_) {
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

        return $nodeFinder->findFirst($node, static function (Node $candidate): bool {
            if ($candidate instanceof Expr\Variable && is_string($candidate->name)) {
                return self::hasSensitiveContext($candidate->name);
            }

            if ($candidate instanceof Expr\PropertyFetch && $candidate->name instanceof Identifier) {
                return self::hasSensitiveContext($candidate->name->toString());
            }

            if ($candidate instanceof Expr\ArrayDimFetch && $candidate->dim instanceof Scalar\String_) {
                return self::hasSensitiveContext($candidate->dim->value);
            }

            if ($candidate instanceof Scalar\String_) {
                return self::hasSensitiveContext($candidate->value);
            }

            if ($candidate instanceof Expr\FuncCall) {
                return self::isSensitiveEnvironmentRead($candidate);
            }

            return false;
        }) instanceof Node;
    }

    /**
     * Detect env-reader calls that request a sensitive key.
     *
     * @return bool True when getenv/env reads a secret-like key.
     */
    private static function isSensitiveEnvironmentRead(FuncCall $call): bool
    {
        $name = self::globalFunctionName($call);
        if ($name === null || !in_array($name, ['apache_getenv', 'env', 'getenv'], true)) {
            return false;
        }

        $firstArg = self::argumentValue($call->args, 0);
        if ($firstArg === null) {
            return true;
        }

        return $firstArg instanceof Scalar\String_ && self::hasSensitiveContext($firstArg->value);
    }

    /**
     * Detect secret-like words in identifiers or string keys.
     *
     * @return bool True when the text names likely sensitive data.
     */
    private static function hasSensitiveContext(string $contextText): bool
    {
        // Match secret-like identifier and string-key fragments without reading values into findings.
        return preg_match('/(?:api[_-]?key|auth(?:orization)?|cookie|pass(?:word|wd)?|private[_-]?key|secret|token)/i', $contextText) === 1;
    }
}
