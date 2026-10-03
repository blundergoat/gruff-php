<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Resolves whether a method inherits its documentation contract from a parent or interface, so the docs
 * rules can exempt an override that legitimately relies on the ancestor's documented contract.
 *
 * Used by the missing-throws, missing-param and missing-return rules and by waste.one-line-method: an
 * `@inheritdoc` marker, an `#[Override]` attribute, or a documented same-file ancestor method all count as an
 * inherited contract. Only ancestors declared in the same parsed unit are resolved; cross-file inheritance is
 * out of scope, so a marker stands in for an ancestor this file cannot see.
 */
final readonly class DocsInheritanceHelper
{
    /**
     * Reports whether a method inherits its documentation contract from a parent, interface, or marker.
     *
     * @param ClassMethod     $classMethod - Method node whose inherited contract should be inspected.
     * @param list<Node\Stmt> $statements - Parsed statements used to find ancestor declarations.
     * @param NodeFinder      $nodeFinder - Node finder used to search inherited method candidates.
     *
     * @return bool - True when inheritance or override metadata provides the contract docs.
     */
    public function hasInheritedContractDoc(ClassMethod $classMethod, array $statements, NodeFinder $nodeFinder): bool
    {
        if ($this->hasInheritDoc($classMethod) || $this->hasOverrideAttribute($classMethod)) {
            // An explicit @inheritdoc or #[Override] is the author asserting the parent owns the contract.
            return true;
        }

        $class = $this->enclosingClass($classMethod);
        if (!$class instanceof Class_) {
            // Only Class_ nodes can extend or implement, so nothing else can inherit a contract.
            return false;
        }

        $ancestorNames = $this->ancestorNames($class);

        // Inherited docs only count when a named ancestor in this same unit actually documents the method.
        return $ancestorNames !== [] && $this->hasDocumentedAncestorMethod(
            ancestorNames: $ancestorNames,
            methodName:    strtolower($classMethod->name->toString()),
            statements:    $statements,
            nodeFinder:    $nodeFinder,
        );
    }

    /**
     * Returns the same-file direct ancestor method a method overrides, whether or not it is documented.
     *
     * @param ClassMethod     $classMethod - Overriding method whose ancestor is looked up by name.
     * @param list<Node\Stmt> $statements - Parsed statements used to find ancestor declarations.
     * @param NodeFinder      $nodeFinder - Node finder used to search inherited method candidates.
     *
     * @return ClassMethod|null - The ancestor's method, so a caller can read exactly which tags it declares; null when
     *   the class has no ancestor declared in this unit, or that ancestor has no non-private method of this name.
     */
    public function sameFileAncestorMethod(ClassMethod $classMethod, array $statements, NodeFinder $nodeFinder): ?ClassMethod
    {
        $class = $this->enclosingClass($classMethod);
        $ancestorNames = $class instanceof Class_ ? $this->ancestorNames($class) : [];
        if ($ancestorNames === []) {
            // Without a named parent or interface there is nothing in this file to inherit from.
            return null;
        }

        $methodName = strtolower($classMethod->name->toString());
        // Search every class-like in the unit for a matching ancestor method.
        foreach ($nodeFinder->findInstanceOf($statements, ClassLike::class) as $candidate) {
            // Only the class's named ancestors are worth searching.
            if (!$this->isNamedAncestorCandidate($candidate, $ancestorNames)) {
                continue;
            }

            // The first same-named method on a direct ancestor is the one overridden; a private one never is.
            foreach ($candidate->getMethods() as $ancestorMethod) {
                if (!$ancestorMethod->isPrivate() && strtolower($ancestorMethod->name->toString()) === $methodName) {
                    return $ancestorMethod;
                }
            }
        }

        return null;
    }

    /**
     * Reports whether a method carries an inheritance marker its class-like could honour.
     *
     * @param ClassMethod $classMethod - Method whose docblock and attributes are read.
     *
     * @return bool - True for `{@inheritdoc}`, a tag-line `@inheritdoc`, or `#[Override]` in a class-like that can
     *   inherit a contract; false for no marker, or for a marker in a standalone class, which inherits nothing.
     */
    public function hasInheritanceMarker(ClassMethod $classMethod): bool
    {
        return ($this->hasInheritDoc($classMethod) || $this->hasOverrideAttribute($classMethod))
            && $this->canInheritContract($classMethod);
    }

    /**
     * Reports whether a method's class-like can inherit a contract from a declaration this file may not see.
     *
     * @param ClassMethod $classMethod - Method whose owning class-like is read.
     *
     * @return bool - True for a class that extends, implements, or uses a trait (whose abstract methods are a
     *   contract), an enum that implements or uses a trait, an interface that extends another, and a trait, whose
     *   using class may implement an interface; false for a standalone class, enum or interface, where an
     *   `@inheritdoc` marker inherits nothing.
     */
    public function canInheritContract(ClassMethod $classMethod): bool
    {
        $classLike = $classMethod->getAttribute('parent');
        if ($classLike instanceof Class_) {
            return $classLike->extends !== null || $classLike->implements !== [] || $classLike->getTraitUses() !== [];
        }

        if ($classLike instanceof Enum_) {
            return $classLike->implements !== [] || $classLike->getTraitUses() !== [];
        }

        if ($classLike instanceof Interface_) {
            return $classLike->extends !== [];
        }

        return $classLike instanceof Trait_;
    }

    /**
     * Returns the resolved parent and implemented-interface names declared directly on a class.
     *
     * @param Class_ $class - Class declaration whose direct parent and interfaces should be collected.
     *
     * @return list<string> - Lowercased, namespace-resolved ancestor names declared directly on the class.
     */
    private function ancestorNames(Class_ $class): array
    {
        $ancestorNames = [];
        // A named parent class is a direct ancestor.
        if ($class->extends instanceof Name) {
            $ancestorNames[] = $this->resolvedName($class->extends);
        }

        // Each implemented interface is a direct ancestor too.
        foreach ($class->implements as $interface) {
            $ancestorNames[] = $this->resolvedName($interface);
        }

        // Direct parent plus implemented interfaces only; transitive ancestors are not resolved here.
        return $ancestorNames;
    }

    /**
     * Reports whether a same-file direct ancestor documents the given method.
     *
     * @param list<string>    $ancestorNames - Resolved, lowercased names of direct ancestors.
     * @param string          $methodName - Lowercase method name to find.
     * @param list<Node\Stmt> $statements - Parsed statements used to find ancestor declarations.
     * @param NodeFinder      $nodeFinder - Node finder used to search inherited method candidates.
     *
     * @return bool - True when a same-file ancestor method has a docblock.
     */
    private function hasDocumentedAncestorMethod(
        array $ancestorNames,
        string $methodName,
        array $statements,
        NodeFinder $nodeFinder,
    ): bool {
        // Search every class-like in the unit for a matching ancestor.
        foreach ($nodeFinder->findInstanceOf($statements, ClassLike::class) as $candidate) {
            // Only the class's named ancestors are worth searching.
            if (!$this->isNamedAncestorCandidate($candidate, $ancestorNames)) {
                continue;
            }

            // Look for the overridden method on the ancestor.
            foreach ($candidate->getMethods() as $ancestorMethod) {
                if ($this->isDocumentedMethod($ancestorMethod, $methodName)) {
                    // First documented ancestor override is enough; the contract is inherited.
                    return true;
                }
            }
        }

        // No same-unit ancestor documents this method, so the override carries no inherited contract.
        return false;
    }

    /**
     * Reports whether a class-like node is a named direct ancestor worth searching for methods.
     *
     * @param ClassLike    $candidate - Class-like node found in the unit, tested against the ancestor list.
     * @param list<string> $ancestorNames - Resolved, lowercased names of direct ancestors.
     *
     * @return bool - True when the candidate should be searched for methods.
     */
    private function isNamedAncestorCandidate(ClassLike $candidate, array $ancestorNames): bool
    {
        if (!$candidate instanceof Class_ && !$candidate instanceof Interface_) {
            // Traits and enums cannot be ancestors, so they never supply inherited method contracts.
            return false;
        }

        // A declaration's namespaced name, when the parser resolved one, keeps a same-named class in another
        // namespace of this file from standing in for the real ancestor.
        $candidateName = ($candidate->namespacedName ?? $candidate->name)?->toLowerString();

        // Anonymous classes (null name) and unrelated types are excluded; only listed ancestors match.
        return $candidateName !== null && in_array($candidateName, $ancestorNames, true);
    }

    /**
     * Reports whether an ancestor method matches the target name and carries a docblock.
     *
     * @param ClassMethod $ancestorMethod - Candidate ancestor method to compare by name and docblock.
     * @param string      $methodName - Already-lowercased name of the overriding method to match.
     *
     * @return bool - True when the method supplies inherited contract docs.
     */
    private function isDocumentedMethod(ClassMethod $ancestorMethod, string $methodName): bool
    {
        // Match is case-insensitive and requires a docblock; an undocumented ancestor inherits nothing.
        return strtolower($ancestorMethod->name->toString()) === $methodName
            && $ancestorMethod->getDocComment() !== null;
    }

    /**
     * Reports whether a method's own docblock declares inheritdoc.
     *
     * @param ClassMethod $classMethod - Method whose own docblock is scanned for an inheritdoc marker.
     *
     * @return bool - True when inheritdoc is present.
     */
    private function hasInheritDoc(ClassMethod $classMethod): bool
    {
        $doc = $classMethod->getDocComment();

        // Match an inline `{@inheritdoc}`, or `@inheritdoc` as a tag at the start of a docblock line; a sentence that
        // merely mentions `@inheritdoc`, or a longer word such as `@inheritdocs`, is not a marker.
        return $doc !== null && preg_match('/\{@inheritdoc\}|^\s*(?:\/\*\*|\*)?\s*@inheritdoc\b/im', $doc->getText()) === 1;
    }

    /**
     * Reports whether a method carries an #[Override] attribute.
     *
     * @param ClassMethod $classMethod - Method whose attribute groups are scanned for #[Override].
     *
     * @return bool - True when an Override attribute is present.
     */
    private function hasOverrideAttribute(ClassMethod $classMethod): bool
    {
        // Scan each attribute group on the method.
        foreach ($classMethod->attrGroups as $group) {
            // Check each attribute for the Override marker.
            foreach ($group->attrs as $attribute) {
                // PHP's own attribute resolves to the global `Override`; a namespaced class of that name is not it.
                if ($this->resolvedName($attribute->name) === 'override') {
                    // Comparison is case-insensitive so #[Override] and #[override] both signal an override.
                    return true;
                }
            }
        }

        // No attribute named override on any group, so the method does not assert an inherited contract.
        return false;
    }

    /**
     * Walks the parent chain to the enclosing class, or null when the method lives outside a class.
     *
     * @param ClassMethod $classMethod - Method whose ancestor chain is walked outward to its class.
     *
     * @return Class_|null - Enclosing class node, or null outside a class.
     */
    private function enclosingClass(ClassMethod $classMethod): ?Class_
    {
        $parent = $classMethod->getAttribute('parent');

        // Walk outward through the parent chain looking for the enclosing class.
        while ($parent instanceof Node) {
            if ($parent instanceof Class_) {
                // Nearest enclosing Class_ wins; interfaces and traits are skipped by the loop condition.
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        // The chain ended without a Class_, so the method lives outside any class body.
        return null;
    }

    /**
     * Returns a name as PHP resolves it in its namespace, lowercased for PHP's case-insensitive class names.
     *
     * @param Name $name - Name as written in an `extends`, `implements`, or attribute position.
     *
     * @return string - The fully qualified name the parser's name resolver recorded, without a leading backslash;
     *   the name as written when no resolution was recorded.
     */
    private function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ($resolved instanceof Name ? $resolved : $name)->toLowerString();
    }
}
