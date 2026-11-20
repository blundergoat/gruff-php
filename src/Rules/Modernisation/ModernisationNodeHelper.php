<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Modernisation;

use GruffPhp\Rules\Contracts\RuleContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Shared AST helpers the modernisation rules lean on - target-version checks, type-name normalisation,
 * `$this->prop` detection, and class-name and DTO classification.
 *
 * Centralising these keeps every modernisation rule reading the AST the same way, so a `mixed` check or a
 * DTO exemption behaves identically wherever it fires. Pure static utility that holds no state.
 */
final class ModernisationNodeHelper
{
    /**
     * Reports whether the project's target PHP version is new enough for a given syntax feature.
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
     * Normalises a simple PHP type node to its lower-case name for easy comparison.
     *
     * @param null|Identifier|Name|Node\ComplexType $type - Declared type node; null means the declaration omits a type.
     *
     * @return string|null - Type name, or null when the type is compound (union/intersection/nullable) or absent.
     */
    public static function typeName(null|Identifier|Name|Node\ComplexType $type): ?string
    {
        if ($type instanceof Identifier || $type instanceof Name) {
            // Lower-case so callers can compare against literal type names without minding source casing.
            return strtolower($type->toString());
        }

        // Compound and absent types are intentionally not reduced to a single name.
        return null;
    }

    /**
     * Reports whether an expression fetches a statically named property from $this.
     *
     * @param Expr        $expr - Candidate property access, including assignment targets.
     * @param string|null $propertyName - Exact name to match; null accepts any statically named $this property.
     *
     * @return bool - True when the expression matches the requested $this property.
     */
    public static function isThisPropertyFetch(Expr $expr, ?string $propertyName = null): bool
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->var instanceof Expr\Variable) {

            return false;
        }

        if ($expr->var->name !== 'this' || !$expr->name instanceof Identifier) {
            // Reject fetches off other variables, or a property fetched from $this by a dynamic name, since the name is not statically known.
            return false;
        }

        return $propertyName === null || $expr->name->toString() === $propertyName;
    }

    /**
     * Resolves a statically named property from a property-fetch expression.
     *
     * @param Expr $expr - Candidate property access; dynamic names and non-fetch expressions yield null.
     *
     * @return string|null - Property name, or null when the access uses a dynamic property name or not a fetch.
     */
    public static function propertyFetchName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            // Dynamic property names and non-fetch expressions have no static name to report.
            return null;
        }

        return $expr->name->toString();
    }

    /**
     * Resolves a class statement's declared name.
     *
     * @param Stmt\Class_ $class - Class declaration whose unqualified name is returned.
     *
     * @return string|null - Class name, or null for an anonymous class that has no declared name.
     */
    public static function className(Stmt\Class_ $class): ?string
    {
        return $class->name?->toString();
    }

    /**
     * Reports whether a class name looks like a DTO/value object by its conventional suffix.
     *
     * @param Stmt\Class_ $class - Class whose declared name is checked for Data, Dto, DTO, Payload, or ValueObject suffixes.
     *
     * @return bool - True for a case-sensitive suffix match; false for other names and anonymous classes.
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
}
