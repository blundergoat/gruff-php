<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Rule\RuleContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class ModernisationNodeHelper
{
    /**
     * Determine whether the configured target PHP version supports a syntax feature.
     *
     * @return bool True when the project target is at least the requested version.
     */
    public static function supportsPhp(RuleContext $context, float $version): bool
    {
        return $context->config->minimumPhpVersion() >= $version;
    }

    /**
     * Normalize simple PHP type nodes to lower-case names.
     *
     * @return string|null Type name, or null for complex/absent types.
     */
    public static function typeName(null|Identifier|Name|Node\ComplexType $type): ?string
    {
        if ($type instanceof Identifier || $type instanceof Name) {
            return strtolower($type->toString());
        }

        return null;
    }

    /**
     * Check whether an expression fetches a property from `$this`.
     *
     * @return bool True when the expression matches the requested `$this` property.
     */
    public static function isThisPropertyFetch(Expr $expr, ?string $propertyName = null): bool
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->var instanceof Expr\Variable) {
            return false;
        }

        if ($expr->var->name !== 'this' || !$expr->name instanceof Identifier) {
            return false;
        }

        return $propertyName === null || $expr->name->toString() === $propertyName;
    }

    /**
     * Resolve the property name from a static property-fetch expression.
     *
     * @return string|null Property name, or null for dynamic property access.
     */
    public static function propertyFetchName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            return null;
        }

        return $expr->name->toString();
    }

    /**
     * Resolve a class statement's declared name.
     *
     * @return string|null Class name, or null for anonymous classes.
     */
    public static function className(Stmt\Class_ $class): ?string
    {
        return $class->name?->toString();
    }

    /**
     * Identify value-style classes by conventional suffixes.
     *
     * @return bool True when the class name looks like a DTO/value object.
     */
    public static function isDtoClass(Stmt\Class_ $class): bool
    {
        $name = self::className($class);
        if ($name === null) {
            return false;
        }

        foreach (['Data', 'Dto', 'DTO', 'Payload', 'ValueObject'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the parent node connected by PhpParser's parent visitor.
     *
     * @return Node|null Parent node, or null when the attribute is absent.
     */
    public static function parent(Node $node): ?Node
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Node ? $parent : null;
    }
}
