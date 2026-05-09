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
    public static function supportsPhp(RuleContext $context, float $version): bool
    {
        return $context->config->minimumPhpVersion() >= $version;
    }

    public static function typeName(null|Identifier|Name|Node\ComplexType $type): ?string
    {
        if ($type instanceof Identifier || $type instanceof Name) {
            return strtolower($type->toString());
        }

        return null;
    }

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

    public static function propertyFetchName(Expr $expr): ?string
    {
        if (!$expr instanceof Expr\PropertyFetch || !$expr->name instanceof Identifier) {
            return null;
        }

        return $expr->name->toString();
    }

    public static function className(Stmt\Class_ $class): ?string
    {
        return $class->name?->toString();
    }

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

    public static function parent(Node $node): ?Node
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Node ? $parent : null;
    }
}
