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
 * Resolves callback syntax to an exact declaring-class and method identity.
 * Rules use the identity after parsing has attached resolved names and parent links.
 * Users benefit when a statically proven named callback is preserved without hiding a namesake.
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
     * @return string|null - Literal method name, or null when the array does not target this scope.
     */
    public static function sameClassCallableArrayMethodName(Node $node, Class_|Trait_|Enum_ $classLike): ?string
    {
        $parts = self::callableArrayParts($node);

        // Non-callable arrays and computed method slots carry no precise private-method name.
        if ($parts === null) {
            return null;
        }

        // Preserve the dead-code rule's proven same-class receiver boundary.
        if (!self::isSameClassCallableTarget($parts['target'], $classLike)) {
            return null;
        }

        return $parts['method'];
    }

    /**
     * Report whether a keyed callable array can dynamically select any same-class method.
     *
     * @param Node $node - Candidate array node whose runtime method expression may be unknown.
     * @param Class_|Trait_|Enum_ $classLike - Scope whose private methods could be selected.
     *
     * @return bool - True only for a valid callable key layout, same-class receiver, and computed method slot.
     */
    public static function hasUnresolvedSameClassCallableArrayMethod(
        Node $node,
        Class_|Trait_|Enum_ $classLike,
    ): bool {
        $expressions = self::callableArrayExpressions($node);

        // Invalid arrays and literal methods do not create an unresolved dispatch boundary.
        if ($expressions === null || $expressions['method'] instanceof Node\Scalar\String_) {
            return false;
        }

        return self::isSameClassCallableTarget($expressions['target'], $classLike);
    }

    /**
     * Decide whether a callable-array receiver resolves to its surrounding declaration.
     *
     * @param Expr $target - First value from a two-element callable array.
     * @param Class_|Trait_|Enum_ $classLike - Scope used for the same-class comparison.
     *
     * @return bool - True for `$this`, `__CLASS__`, self/static class constants, or the same resolved class.
     */
    public static function isSameClassCallableTarget(Expr $target, Class_|Trait_|Enum_ $classLike): bool
    {
        // Preserve dead-code's lexical `$this` handling even for an anonymous class with no declaration name.
        if ($target instanceof Expr\Variable && $target->name === 'this') {
            return true;
        }

        // `__CLASS__` is same-scope by language definition and needs no comparable class name.
        if ($target instanceof Node\Scalar\MagicConst\Class_) {
            return true;
        }

        // Self and static class constants are also same-scope before any explicit-name comparison.
        if ($target instanceof Expr\ClassConstFetch
            && $target->name instanceof Node\Identifier
            && strtolower($target->name->toString()) === 'class'
            && $target->class instanceof Name
            && in_array(strtolower($target->class->toString()), ['self', 'static'], true)
        ) {
            return true;
        }

        $declaringClass = self::declaringClassName($classLike, true);

        // A malformed anonymous declaration offers no class name for explicit target comparison.
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
     * @return array{target: Expr, method: string}|null - Ordered callback parts, or null for any computed/incomplete shape.
     */
    private static function callableArrayParts(Node $node): ?array
    {
        $expressions = self::callableArrayExpressions($node);

        // Invalid key layouts and incomplete values cannot name a usable callback.
        if ($expressions === null) {
            return null;
        }

        // A computed method slot is intentionally left to the user's explicit symbol allowlist.
        if (!$expressions['method'] instanceof Node\Scalar\String_) {
            return null;
        }

        return [
            'target' => $expressions['target'],
            'method' => $expressions['method']->value,
        ];
    }

    /**
     * Resolve PHP callable slots zero and one without assuming their source order.
     *
     * @param Node $node - Candidate literal array whose runtime key layout is simulated.
     *
     * @return array{target: Expr, method: Expr}|null - Receiver and method expressions, or null when keys cannot form a callable pair.
     */
    private static function callableArrayExpressions(Node $node): ?array
    {
        // PHP callable arrays have exactly two source items before their runtime keys are resolved.
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

            // An unkeyed item receives PHP's next automatic integer key.
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

        // PHP recognizes a callable pair only when runtime keys zero and one both survive exactly once.
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
     * @return string|null - Target class name, or null when runtime computation prevents a safe match.
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

        // `$this` always denotes the current object and therefore its declaring class.
        if ($target instanceof Expr\Variable && $target->name === 'this') {
            return $declaringClass;
        }

        // `__CLASS__` is the lexical declaration name even when used as an array value.
        if ($target instanceof Node\Scalar\MagicConst\Class_) {
            return $declaringClass;
        }

        // Every other supported receiver must be a literal `Name::class` expression.
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
     * Read the fully resolved name attached to a class-like declaration.
     *
     * @param Class_|Trait_|Enum_ $classLike - Named declaration that owns a method or callback.
     * @param bool $isWrittenFallbackAllowed - True accepts the written declaration name for dead-code; false requires parser resolution.
     *
     * @return string|null - Fully resolved name, or null for anonymous or unresolved declarations.
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

        // The waste rule rejects unresolved owners; dead-code keeps its prior written-name fallback.
        if (!$isWrittenFallbackAllowed) {
            return null;
        }

        return $classLike->name?->toString();
    }

    /**
     * Read a fully resolved class reference without trusting an unresolved short name.
     *
     * @param Name $name - Explicit class target from first-class callable syntax.
     * @param bool $isWrittenFallbackAllowed - True accepts an unresolved written name for dead-code; false returns null.
     *
     * @return string|null - Fully resolved name, or null when name resolution supplied no proof.
     */
    private static function resolvedReferenceName(Name $name, bool $isWrittenFallbackAllowed = false): ?string
    {
        // A fully qualified node is already exact even when no resolvedName attribute is attached.
        if ($name instanceof Name\FullyQualified) {
            return $name->toString();
        }

        $resolvedName = $name->getAttribute('resolvedName');

        // Name resolution supplies the exact namespace-safe reference used by both rules.
        if ($resolvedName instanceof Name) {
            return $resolvedName->toString();
        }

        return $isWrittenFallbackAllowed ? $name->toString() : null;
    }
}
