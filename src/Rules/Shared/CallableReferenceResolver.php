<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Matches supported callable-array syntax to a lexical class, trait, or enum scope.
 * Rules use literal method names or unresolved-method detection for private-method analysis.
 * Explicit receiver names use parser resolution when available, with an optional written-name fallback.
 */
final class CallableReferenceResolver
{
    /** Number of runtime slots in PHP's callable-array form. */
    private const CALLABLE_PART_COUNT = 2;

    /**
     * Read a literal method name from a same-class callable array for dead-code analysis.
     *
     * @param Node $node - Candidate array node from one class-like body.
     * @param Class_|Trait_|Enum_ $classLike - Scope whose private methods may be referenced.
     *
     * @return string|null - Literal method name, or null for unsupported arrays, non-literal methods, or another scope.
     */
    public static function sameClassCallableArrayMethodName(Node $node, Class_|Trait_|Enum_ $classLike): ?string
    {
        $parts = self::callableArrayParts($node);

        // Non-callable arrays and computed method slots carry no precise private-method name.
        if ($parts === null) {
            return null;
        }


        if (!self::isSameClassCallableTarget($parts['target'], $classLike)) {
            return null;
        }

        return $parts['method'];
    }

    /**
     * Reports whether a callable-array shape has a same-class receiver and a non-literal method expression.
     *
     * @param Node $node - Candidate array node whose callable slots are inspected without evaluating expressions.
     * @param Class_|Trait_|Enum_ $classLike - Scope whose private methods could be selected.
     *
     * @return bool - True for a supported two-slot shape with a same-class receiver and non-literal method expression.
     */
    public static function hasUnresolvedSameClassCallableArrayMethod(
        Node $node,
        Class_|Trait_|Enum_ $classLike,
    ): bool {
        $expressions = self::callableArrayExpressions($node);

        // Unsupported layouts and literal method expressions are not unresolved same-class candidates.
        if ($expressions === null || $expressions['method'] instanceof Node\Scalar\String_) {
            return false;
        }

        return self::isSameClassCallableTarget($expressions['target'], $classLike);
    }

    /**
     * Classifies whether dead-code analysis treats a callable-array receiver as the surrounding declaration.
     *
     * @param Expr $target - First value from a two-element callable array.
     * @param Class_|Trait_|Enum_ $classLike - Scope used for the same-class comparison.
     *
     * @return bool - True for supported same-class receiver forms or an explicit class name resolved to the declaration.
     */
    public static function isSameClassCallableTarget(Expr $target, Class_|Trait_|Enum_ $classLike): bool
    {
        // Dead-code analysis treats $this as lexical scope even when an anonymous class has no declaration name.
        if ($target instanceof Expr\Variable && $target->name === 'this') {
            return true;
        }

        // __CLASS__ names the lexical scope and needs no comparable declaration name.
        if ($target instanceof Node\Scalar\MagicConst\Class_) {
            return true;
        }

        // Dead-code analysis treats self and static class constants as lexical before comparing explicit names.
        if ($target instanceof Expr\ClassConstFetch
            && $target->name instanceof Node\Identifier
            && strtolower($target->name->toString()) === 'class'
            && $target->class instanceof Name
            && in_array(strtolower($target->class->toString()), ['self', 'static'], true)
        ) {
            return true;
        }

        $declaringClass = self::declaringClassName($classLike, true);

        // Anonymous declarations can lack a name for explicit receiver comparison.
        if ($declaringClass === null) {
            return false;
        }

        $targetClass = self::callableTargetName($target, $classLike, true);

        return $targetClass !== null && strtolower($targetClass) === strtolower($declaringClass);
    }

    /**
     * Split PHP's literal callable-array form into its target and method name.
     *
     * @param Node $node - Candidate node that may be a two-element array literal.
     *
     * @return array{target: Expr, method: string}|null - Receiver and literal method, or null for unsupported layouts or method expressions.
     */
    private static function callableArrayParts(Node $node): ?array
    {
        $expressions = self::callableArrayExpressions($node);

        // Unsupported layouts provide no statically usable callback name.
        if ($expressions === null) {
            return null;
        }

        // Computed method names belong to the separate unresolved-dispatch check.
        if (!$expressions['method'] instanceof Node\Scalar\String_) {
            return null;
        }

        return [
            'target' => $expressions['target'],
            'method' => $expressions['method']->value,
        ];
    }

    /**
     * Reads the statically supported callable slots zero and one without assuming source order.
     *
     * @param Node $node - Candidate two-item array whose literal key layout is inspected without evaluation.
     *
     * @return array{target: Expr, method: Expr}|null - Receiver and method expressions, or null outside the supported exact two-slot shape.
     */
    private static function callableArrayExpressions(Node $node): ?array
    {

        if (!$node instanceof Expr\Array_ || count($node->items) !== self::CALLABLE_PART_COUNT) {
            return null;
        }

        $slots = [];

        // Apply PHP's integer, numeric-string, and automatic array-key behavior in source order.
        foreach ($node->items as $arrayItem) {
            // Unpacking can add runtime slots, so it cannot prove an exact two-slot callable.
            if ($arrayItem->unpack) {
                return null;
            }


            if ($arrayItem->key === null) {
                $slots[] = $arrayItem;
                continue;
            }

            // Literal integer and string keys are the only key conversions proven without evaluation.
            if (!$arrayItem->key instanceof Node\Scalar\Int_ && !$arrayItem->key instanceof Node\Scalar\String_) {
                return null;
            }

            $slots[$arrayItem->key->value] = $arrayItem;
        }

        // The supported shape must leave exactly the runtime keys zero and one.
        if (count($slots) !== self::CALLABLE_PART_COUNT
            || !isset($slots[0], $slots[1])) {
            return null;
        }

        return ['target' => $slots[0]->value, 'method' => $slots[1]->value];
    }

    /**
     * Resolve one supported callable-array receiver to a class name.
     *
     * @param Expr $target - First callable-array value to classify.
     * @param Class_|Trait_|Enum_ $classLike - Lexical declaration that defines same-class tokens.
     * @param bool $isWrittenFallbackAllowed - True preserves dead-code's legacy written-name fallback; false rejects unresolved names.
     *
     * @return string|null - Target class name, or null for a missing declaration identity, unsupported receiver, or unresolved name.
     */
    private static function callableTargetName(
        Expr $target,
        Class_|Trait_|Enum_ $classLike,
        bool $isWrittenFallbackAllowed,
    ): ?string {
        $declaringClass = self::declaringClassName($classLike, $isWrittenFallbackAllowed);

        // Same-class tokens still need a usable declaration identity to return.
        if ($declaringClass === null) {
            return null;
        }

        // Private-method analysis treats $this as the lexical scope for this conservative comparison.
        if ($target instanceof Expr\Variable && $target->name === 'this') {
            return $declaringClass;
        }

        // __CLASS__ is the lexical declaration name even when used as an array value.
        if ($target instanceof Node\Scalar\MagicConst\Class_) {
            return $declaringClass;
        }

        // Every other supported receiver must be a literal Name::class expression.
        if (!$target instanceof Expr\ClassConstFetch
            || !$target->name instanceof Node\Identifier
            || strtolower($target->name->toString()) !== 'class'
            || !$target->class instanceof Name
        ) {
            return null;
        }

        $writtenName = strtolower($target->class->toString());

        // Self and static class constants stay within the lexical declaration for this conservative match.
        if ($writtenName === 'self' || $writtenName === 'static') {
            return $declaringClass;
        }

        // Parent names a different declaration owner and cannot match an exact local method key.
        if ($writtenName === 'parent') {
            return null;
        }

        return self::resolvedReferenceName($target->class, $isWrittenFallbackAllowed);
    }

    /**
     * Read a declaration's resolved name, optionally falling back to its written name.
     *
     * @param Class_|Trait_|Enum_ $classLike - Declaration whose lexical identity is needed.
     * @param bool $isWrittenFallbackAllowed - Allow the written declaration name when parser resolution is absent.
     *
     * @return string|null - Resolved or permitted written name; null when no acceptable name is available.
     */
    private static function declaringClassName(
        Class_|Trait_|Enum_ $classLike,
        bool $isWrittenFallbackAllowed = false,
    ): ?string
    {
        $namespacedName = $classLike->namespacedName ?? null;

        // A parser-resolved declaration name is the preferred exact identity.
        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }


        if (!$isWrittenFallbackAllowed) {
            return null;
        }

        return $classLike->name?->toString();
    }

    /**
     * Read a resolved class reference, optionally accepting its unresolved written name.
     *
     * @param Name $name - Class name from a callable-array receiver's class constant.
     * @param bool $isWrittenFallbackAllowed - True permits a written fallback for dead code; false returns null when resolution is unavailable.
     *
     * @return string|null - Resolved or permitted written name; null when neither is available.
     */
    private static function resolvedReferenceName(Name $name, bool $isWrittenFallbackAllowed = false): ?string
    {
        // A fully qualified node is already exact even when no resolvedName attribute is attached.
        if ($name instanceof Name\FullyQualified) {
            return $name->toString();
        }

        $resolvedName = $name->getAttribute('resolvedName');

        // Parser resolution distinguishes imported names from unrelated namesakes.
        if ($resolvedName instanceof Name) {
            return $resolvedName->toString();
        }

        return $isWrittenFallbackAllowed ? $name->toString() : null;
    }
}
