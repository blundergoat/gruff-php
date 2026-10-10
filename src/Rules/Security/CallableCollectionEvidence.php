<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Recognizes callbacks registered for later boot or teardown within the scanned declaration.
 *
 * A developer gets this exception only for a non-public, initially empty property with verified parameter stores.
 * Every observed property use must preserve the collection; inherited or external implementations are not inferred.
 */
final class CallableCollectionEvidence
{
    /**
     * Checks the collection behind an unchanged loop value at this exact invocation.
     *
     * @param Expr\FuncCall $call - Dynamic invocation; calls outside the selecting loop receive no collection evidence.
     * @return bool - True when the owning declaration proves its observable registrations and reads.
     */
    public static function hasInvocationEvidence(Expr\FuncCall $call): bool
    {
        $parent = $call->getAttribute('parent');
        // Stop at the method or closure boundary so a captured name never borrows another loop's evidence.
        while ($parent instanceof Node && !$parent instanceof Node\FunctionLike) {
            // Only the loop selecting this unchanged callback can establish its collection source.
            if ($parent instanceof Stmt\Foreach_ && CallableFlowEvidence::hasUnchangedLoopValue($call, $parent)) {
                return self::hasCollectionEvidence($parent);
            }
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    /**
     * Binds a selected collection to its own declaration, default and observed property uses.
     *
     * @param Stmt\Foreach_ $loop - Current callback iteration; unsupported selection shapes keep the warning.
     * @return bool - True only for an owned collection with at least one proved registration.
     */
    private static function hasCollectionEvidence(Stmt\Foreach_ $loop): bool
    {
        $selected    = $loop->expr;
        $property    = $selected instanceof Expr\ArrayDimFetch ? $selected->var : $selected;
        $declaration = self::owningDeclaration($loop);
        // Direct instance lists and one runtime-class static bucket are the supported registration forms.
        if ((!$property instanceof Expr\PropertyFetch && !$property instanceof Expr\StaticPropertyFetch)
            || !$property->name instanceof Identifier || $declaration === null
            || ($property instanceof Expr\StaticPropertyFetch && !$selected instanceof Expr\ArrayDimFetch)
            || !self::hasOwnedReceiver($property)
            || !self::isSelectedCollection($selected, $property, $selected instanceof Expr\ArrayDimFetch)
            || !self::hasEmptyOwnedDefault($declaration, $property)) {
            return false;
        }

        return self::hasOnlyKnownUses($declaration, $property, $selected instanceof Expr\ArrayDimFetch);
    }

    /**
     * Requires a visible empty declaration rather than assuming a missing or inherited property is safe.
     *
     * @param Stmt\ClassLike                              $declaration - Class or trait that owns this invocation.
     * @param Expr\PropertyFetch|Expr\StaticPropertyFetch $property    - Exact property selected for iteration.
     * @return bool - True for a matching non-public ordinary property initialized to an empty array.
     */
    public static function hasEmptyOwnedDefault(Stmt\ClassLike $declaration, Expr\PropertyFetch|Expr\StaticPropertyFetch $property): bool
    {
        // A computed name cannot identify the declared collection default.
        if (!$property->name instanceof Identifier) {
            return false;
        }
        // Promoted parameters and inherited properties have no supported empty local declaration here.
        foreach ($declaration->getProperties() as $definition) {
            // Each item in a shared declaration still has its own case-sensitive name and initial value.
            foreach ($definition->props as $item) {
                // Other property declarations cannot supply this collection's starting value.
                if ($item->name->toString() !== $property->name->toString()) {
                    continue;
                }

                return !$definition->isPublic() && $definition->hooks === [] && $definition->isStatic() === ($property instanceof Expr\StaticPropertyFetch)
                    && $item->default instanceof Expr\Array_ && $item->default->items === [];
            }
        }

        return false;
    }

    /**
     * Checks every relevant use before granting a declaration-wide registration fact.
     *
     * @param Stmt\ClassLike                              $declaration    - Owning source boundary; nested declarations own separate properties.
     * @param Expr\PropertyFetch|Expr\StaticPropertyFetch $property       - Named collection whose uses must agree.
     * @param bool                                        $hasClassBucket - True selects exactly static::class within a static property.
     * @return bool - False for unknown uses, dynamic property names, foreign receivers or absent registrations.
     */
    private static function hasOnlyKnownUses(Stmt\ClassLike $declaration, Expr\PropertyFetch|Expr\StaticPropertyFetch $property, bool $hasClassBucket): bool
    {
        // Runtime imports or evaluation can introduce property writes that this declaration cannot account for.
        if (!$property->name instanceof Identifier || (new NodeFinder())->findFirst($declaration->stmts,
            static fn (Node $node): bool => self::owningDeclaration($node) === $declaration
                && ($node instanceof Expr\Eval_ || $node instanceof Expr\Include_)) !== null) {
            return false;
        }
        $hasRegistration = false;
        $propertyUses    = (new NodeFinder())->findInstanceOf($declaration->stmts, $property::class);
        // A later reset or reference can affect an earlier invocation on a later call to the method.
        foreach ($propertyUses as $propertyUse) {
            // Nested classes have separate storage; their matching spellings are not this declaration's property.
            if (self::owningDeclaration($propertyUse) !== $declaration) {
                continue;
            }
            // A different written property name is distinct under PHP's case-sensitive property rules.
            if ($propertyUse->name instanceof Identifier && $propertyUse->name->toString() !== $property->name->toString()) {
                continue;
            }
            // Computed names or another receiver cannot prove which collection a developer will invoke.
            if (!$propertyUse->name instanceof Identifier || !self::hasOwnedReceiver($propertyUse)
                || !self::hasKnownUse($propertyUse, $property, $hasClassBucket, $hasRegistration)) {
                return false;
            }
        }

        return $hasRegistration;
    }

    /**
     * Allows empty resets, exact by-value iteration and append-only typed registrations.
     *
     * @param Expr\PropertyFetch|Expr\StaticPropertyFetch $propertyUse     - One observed reference to this property.
     * @param Expr\PropertyFetch|Expr\StaticPropertyFetch $property        - Collection identity at the invocation.
     * @param bool                                        $hasClassBucket  - Whether stores and reads must select the runtime-class bucket.
     * @param bool                                        $hasRegistration - Tracks proved appends; false means none observed yet.
     * @return bool - False for every other use, including argument borrowing and property references.
     */
    private static function hasKnownUse(
        Expr\PropertyFetch|Expr\StaticPropertyFetch $propertyUse,
        Expr\PropertyFetch|Expr\StaticPropertyFetch $property,
        bool $hasClassBucket,
        bool &$hasRegistration,
    ): bool {
        $selection = $propertyUse;
        $parent    = $selection->getAttribute('parent');
        // Include the complete indexed target so a partial bucket write cannot masquerade as an empty reset.
        while ($parent instanceof Expr\ArrayDimFetch && $parent->var === $selection) {
            $selection = $parent;
            $parent    = $selection->getAttribute('parent');
        }
        // Iterating by reference can replace a stored callback after the method returns.
        if ($parent instanceof Stmt\Foreach_ && $parent->expr === $selection) {
            return !$parent->byRef && self::isSelectedCollection($selection, $property, $hasClassBucket);
        }
        // Unknown consumers may retain references; only ordinary assignment and empty initialization are supported.
        if ((!$parent instanceof Expr\Assign && !$parent instanceof Expr\AssignOp\Coalesce) || $parent->var !== $selection) {
            return false;
        }
        // Resetting the whole property or selected bucket to empty contributes no unproved callback.
        if ($parent->expr instanceof Expr\Array_ && $parent->expr->items === []) {
            return $selection === $propertyUse || self::isSelectedCollection($selection, $property, $hasClassBucket);
        }
        // Registration appends one untouched native callable parameter to exactly the selected collection.
        if ($parent instanceof Expr\Assign && $selection instanceof Expr\ArrayDimFetch && $selection->dim === null
            && self::isSelectedCollection($selection->var, $property, $hasClassBucket)
            && CallableFlowEvidence::hasRegisteredParameterEvidence($parent->expr, $parent)) {
            $hasRegistration = true;
            return true;
        }

        return false;
    }

    /**
     * Matches a complete collection selection without merging different runtime-class buckets.
     *
     * @param Expr                                        $selection      - Read or write target; unknown dimensions keep the warning.
     * @param Expr\PropertyFetch|Expr\StaticPropertyFetch $property       - Exact receiver and name required by the invocation.
     * @param bool                                        $hasClassBucket - True requires the single static::class dimension.
     * @return bool - True only when the complete selection identifies the same supported collection.
     */
    private static function isSelectedCollection(Expr $selection, Expr\PropertyFetch|Expr\StaticPropertyFetch $property, bool $hasClassBucket): bool
    {
        // Boot registrations partition a static property by the current runtime class.
        if ($hasClassBucket) {
            // Arbitrary keys and self::class can select another bucket, so neither grants this proof.
            if (!$property instanceof Expr\StaticPropertyFetch || !$selection instanceof Expr\ArrayDimFetch
                || !$selection->dim instanceof Expr\ClassConstFetch || !$selection->dim->class instanceof Name
                || strtolower($selection->dim->class->toString()) !== 'static'
                || !$selection->dim->name instanceof Identifier || strtolower($selection->dim->name->toString()) !== 'class') {
                return false;
            }
            $selection = $selection->var;
        }

        return $selection instanceof $property && $selection->name instanceof Identifier && $property->name instanceof Identifier
            && $selection->name->toString() === $property->name->toString() && self::hasOwnedReceiver($selection);
    }

    /**
     * Restricts new collection evidence to this object's storage or the written late-static receiver.
     *
     * @param Expr\PropertyFetch|Expr\StaticPropertyFetch $property - Property reference whose receiver must be explicit.
     * @return bool - False for aliases, named foreign classes and computed receivers.
     */
    private static function hasOwnedReceiver(Expr\PropertyFetch|Expr\StaticPropertyFetch $property): bool
    {
        // The instance case must name this object; a similarly named property on another object is unrelated.
        if ($property instanceof Expr\PropertyFetch) {
            return $property->var instanceof Expr\Variable && $property->var->name === 'this';
        }

        return $property->class instanceof Name && strtolower($property->class->toString()) === 'static';
    }

    /**
     * Finds the class or trait that owns a property use without borrowing an outer declaration.
     *
     * @param Node $node - Read, write or loop whose source owner is needed.
     * @return Stmt\ClassLike|null - Nearest declaration; null means no local property contract can be inspected.
     */
    private static function owningDeclaration(Node $node): ?Stmt\ClassLike
    {
        $parent = $node->getAttribute('parent');
        // Anonymous and nested classes form their own property boundary.
        while ($parent instanceof Node) {
            // The nearest class-like owner decides which declarations are visible to this proof.
            if ($parent instanceof Stmt\ClassLike) {
                return $parent;
            }
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }
}
