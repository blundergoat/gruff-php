<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;

final class SecurityNodeHelper
{
    /**
     * @return list<string>
     */
    public static function userInputSuperglobals(): array
    {
        return ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_FILES'];
    }

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
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args
     */
    public static function argumentValue(array $args, int $index): ?Expr
    {
        $arg = $args[$index] ?? null;
        if (!$arg instanceof Node\Arg) {
            return null;
        }

        return $arg->value;
    }

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

    public static function containsUserInput(Node $node): bool
    {
        $finder = new NodeFinder();

        return $finder->findFirst($node, static function (Node $candidate): bool {
            return $candidate instanceof Expr\Variable
                && is_string($candidate->name)
                && in_array($candidate->name, self::userInputSuperglobals(), true);
        }) instanceof Node;
    }

    public static function containsConcatOrInterpolation(Node $node): bool
    {
        $finder = new NodeFinder();

        return $finder->findFirst($node, static function (Node $candidate): bool {
            return $candidate instanceof Expr\BinaryOp\Concat
                || $candidate instanceof Scalar\Encapsed;
        }) instanceof Node;
    }

    public static function isStringLiteral(Node $node): bool
    {
        return $node instanceof Scalar\String_;
    }

    public static function functionNameForMessage(FuncCall $call): string
    {
        $name = self::globalFunctionName($call);

        return $name ?? 'dynamic function call';
    }
}
