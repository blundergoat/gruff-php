<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Rule\RuleContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Provides shared AST helpers for modernisation rules.
 */
final class ModernisationNodeHelper
{
    /**
     * Determine whether the configured target PHP version supports a syntax feature.
     *
     * @param RuleContext $ruleContext - Rule context carrying effective config.
     * @param float       $version - PHP version required by the syntax feature.
     *
     * @return bool - True when the project target is at least the requested version.
     */
    public static function supportsPhp(RuleContext $ruleContext, float $version): bool
    {
        return $ruleContext->config->minimumPhpVersion() >= $version;
    }

    /**
     * Normalize simple PHP type nodes to lower-case names.
     *
     * @param null|Identifier|Name|Node\ComplexType $type - Type node to normalize.
     *
     * @return string|null - Type name, or null for complex/absent types.
     */
    public static function typeName(null|Identifier|Name|Node\ComplexType $type): ?string
    {
        if ($type instanceof Identifier || $type instanceof Name) {
            // Lower-case so callers can compare against literal type names without minding source casing.
            return strtolower($type->toString());
        }

        // Union, intersection, nullable, and absent types carry no single name to normalise.
        return null;
    }

    /**
     * Check whether an expression fetches a property from `$this`.
     *
     * @param Expr        $expr - Expression to inspect.
     * @param string|null $propertyName - Optional property name to match.
     *
     * @return bool - True when the expression matches the requested `$this` property.
     */
    public static function isThisPropertyFetch(Expr $expr, ?string $propertyName = null): bool
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->var instanceof Expr\Variable) {
            // Anything that is not a property fetch on a plain variable cannot be a `$this->prop` access.
            return false;
        }

        if ($expr->var->name !== 'this' || !$expr->name instanceof Identifier) {
            // Reject fetches off other variables, or dynamic `$this->$name`, since the name is not statically known.
            return false;
        }

        return $propertyName === null || $expr->name->toString() === $propertyName;
    }

    /**
     * Resolve the property name from a static property-fetch expression.
     *
     * @param Expr $expr - Expression to inspect.
     *
     * @return string|null - Property name, or null for dynamic property access.
     */
    public static function propertyFetchName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            // Dynamic property names (`$obj->$name`) and non-fetch expressions have no static name to report.
            return null;
        }

        return $expr->name->toString();
    }

    /**
     * Resolve a class statement's declared name.
     *
     * @param Stmt\Class_ $class - Class statement to inspect.
     *
     * @return string|null - Class name, or null for anonymous classes.
     */
    public static function className(Stmt\Class_ $class): ?string
    {
        return $class->name?->toString();
    }

    /**
     * Identify value-style classes by conventional suffixes.
     *
     * @param Stmt\Class_ $class - Class statement to classify.
     *
     * @return bool - True when the class name looks like a DTO/value object.
     */
    public static function isDtoClass(Stmt\Class_ $class): bool
    {
        $name = self::className($class);
        if ($name === null) {
            // An anonymous class carries no naming convention to classify, so treat it as non-DTO.
            return false;
        }

        foreach (['Data', 'Dto', 'DTO', 'Payload', 'ValueObject'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                // A recognised value-object suffix is the signal that exempts the class from mutable-state rules.
                return true;
            }
        }

        return false;
    }

    /**
     * Read the parent node connected by PhpParser's parent visitor.
     *
     * @param Node $node - Node whose parent attribute should be read.
     *
     * @return Node|null - Parent node, or null when the attribute is absent.
     */
    public static function parent(Node $node): ?Node
    {
        $parent = $node->getAttribute('parent');

        // Null means the parent visitor never ran or this is a root node, not that a parent was checked and absent.
        return $parent instanceof Node ? $parent : null;
    }
}
