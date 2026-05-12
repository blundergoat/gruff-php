<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
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
     * Detect whether an expression reads directly from user-input superglobals.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when the node tree contains request-sourced input.
     */
    public static function containsUserInput(Node $node): bool
    {
        $finder = new NodeFinder();

        return $finder->findFirst($node, static function (Node $candidate): bool {
            return $candidate instanceof Expr\Variable
                && is_string($candidate->name)
                && in_array($candidate->name, self::userInputSuperglobals(), true);
        }) instanceof Node;
    }

    /**
     * Detect string construction patterns that can hide unsafe concatenation.
     *
     * @param Node $node Node tree to inspect.
     * @return bool True when concatenation or interpolation appears in the node tree.
     */
    public static function containsConcatOrInterpolation(Node $node): bool
    {
        $finder = new NodeFinder();

        return $finder->findFirst($node, static function (Node $candidate): bool {
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
}
