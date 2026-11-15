<?php

declare(strict_types=1);

namespace GruffPhp\Support;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Selects a declaration line for findings that would otherwise point at a leading attribute.
 *
 * Attributed named declarations use the name or first declared item's line as an anchor.
 * Unattributed nodes and declarations without a supported anchor retain the node's original start line.
 */
final class DeclarationLine
{
    /**
     * Selects a finding line for a declaration, using a name or item anchor when attributes are present.
     *
     * @param Node $node - Declaration to locate; unsupported or unattributed nodes retain their original start line.
     *
     * @return int - Selected name or first-item line, or the original start line when no anchor is available.
     */
    public static function of(Node $node): int
    {
        if (self::attributeGroups($node) === []) {
            return $node->getStartLine();
        }

        return self::declarationStartLine($node) ?? $node->getStartLine();
    }

    /**
     * Returns attribute groups for the declaration kinds supported by this helper.
     *
     * @param Node $node - Node to inspect; unsupported kinds are treated as having no attribute groups.
     *
     * @return list<Node\AttributeGroup> - Groups on a supported declaration; empty for unsupported nodes or no attributes.
     */
    private static function attributeGroups(Node $node): array
    {
        // PhpParser exposes attributes through the FunctionLike interface and as a property elsewhere,
        // so each supported declaration kind is named rather than reached dynamically.
        return match (true) {
            $node instanceof FunctionLike => array_values($node->getAttrGroups()),
            $node instanceof ClassLike, $node instanceof Property,
            $node instanceof ClassConst, $node instanceof EnumCase => array_values($node->attrGroups),
            default => [],
        };
    }

    /**
     * Returns the name or first declared item's line for a supported declaration.
     *
     * @param Node $node - Attributed declaration whose name or item should anchor the finding.
     *
     * @return int|null - Anchor line, or null for anonymous classes, closures, arrow functions, unsupported nodes, or empty item lists.
     */
    private static function declarationStartLine(Node $node): ?int
    {


        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof EnumCase) {
            return $node->name->getStartLine();
        }

        // An anonymous class has no name to anchor on and keeps the node's own start line.
        if ($node instanceof ClassLike) {
            return $node->name?->getStartLine();
        }

        // The first declared item provides an anchor after attributes, even when modifiers are on an earlier line.
        if ($node instanceof Property) {
            return $node->props === [] ? null : $node->props[0]->getStartLine();
        }

        if ($node instanceof ClassConst) {
            return $node->consts === [] ? null : $node->consts[0]->getStartLine();
        }

        // A closure or arrow function has no named part, so its own start line remains the best anchor.
        return null;
    }
}
