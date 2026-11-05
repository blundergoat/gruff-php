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
 * Resolves the line a finding on a declaration should report.
 *
 * A PHP 8 attribute group is a sub-node of the declaration it decorates, so `getStartLine()` on an attributed
 * method, class, property, constant or enum case returns the attribute's line rather than the signature's. The
 * finding then points a reader, an editor jump, and any future autofix at `#[SomeAttribute]` instead of at the
 * code the rule is talking about. This resolves the declaration's own line instead.
 *
 * A declaration carrying no attributes is returned unchanged, so adopting this helper cannot move any finding
 * that was already correct.
 */
final class DeclarationLine
{
    /**
     * Report the line of the declaration itself, skipping any attribute group above it.
     *
     * @param Node $node - Declaration node a rule is reporting; any node without attribute groups is returned unchanged.
     *
     * @return int - 1-based source line of the declaration, never the line of one of its attributes.
     */
    public static function of(Node $node): int
    {
        // Unattributed declarations already start where the reader expects, so nothing moves for them.
        if (self::attributeGroups($node) === []) {
            return $node->getStartLine();
        }

        return self::declarationStartLine($node) ?? $node->getStartLine();
    }

    /**
     * Read the attribute groups a node carries, across the node kinds that can carry them.
     *
     * @param Node $node - Any parsed node; kinds that cannot carry attributes report none.
     *
     * @return list<Node\AttributeGroup> - Attribute groups on this declaration; empty when it has none or cannot have any.
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
     * Find the first token of the declaration proper, after any attribute group.
     *
     * @param Node $node - Attributed declaration whose own start line is wanted.
     *
     * @return int|null - Declaration line, or null when this node kind has no named part to anchor on.
     */
    private static function declarationStartLine(Node $node): ?int
    {
        // The name is the first token the declaration owns, so it is where the signature begins even when
        // the attribute sits on its own line above it or inline on the same one.
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof EnumCase) {
            return $node->name->getStartLine();
        }

        // An anonymous class has no name to anchor on and keeps the node's own start line.
        if ($node instanceof ClassLike) {
            return $node->name?->getStartLine();
        }

        // A property or constant list declares its names in items; the first one sits on the declaration line.
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
