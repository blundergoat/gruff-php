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
 * Supports documentation rules that let methods rely on contracts declared by parents or interfaces.
 * Resolves methods on same-file direct ancestors and recognises inheritdoc and Override markers.
 *
 * The throws rule uses the broad docblock check; parameter and return rules inspect ancestor tags.
 * Cross-file declarations are not resolved, so callers can use markers as fallback evidence.
 */
final readonly class DocsInheritanceHelper
{
    /**
     * Reports whether a method has an explicit inheritance marker or a documented same-file direct ancestor.
     *
     * @param ClassMethod     $classMethod - Method node whose inherited contract should be inspected.
     * @param list<Node\Stmt> $statements - Parsed statements used to find ancestor declarations.
     * @param NodeFinder      $nodeFinder - Node finder used to search inherited method candidates.
     *
     * @return bool - True when a marker is present or a documented direct ancestor method is found in this unit.
     */
    public function hasInheritedContractDoc(ClassMethod $classMethod, array $statements, NodeFinder $nodeFinder): bool
    {
        if ($this->hasInheritDoc($classMethod) || $this->hasOverrideAttribute($classMethod)) {
            // An explicit @inheritdoc or #[Override] counts as an inherited contract for this rule.
            return true;
        }

        $class = $this->enclosingClass($classMethod);
        if (!$class instanceof Class_) {

            return false;
        }

        $ancestorNames = $this->ancestorNames($class);


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
     * @return ClassMethod|null - First non-private match in unit traversal order, or null if no direct ancestor method matches.
     */
    public function sameFileAncestorMethod(ClassMethod $classMethod, array $statements, NodeFinder $nodeFinder): ?ClassMethod
    {
        $class = $this->enclosingClass($classMethod);
        $ancestorNames = $class instanceof Class_ ? $this->ancestorNames($class) : [];
        if ($ancestorNames === []) {

            return null;
        }

        $methodName = strtolower($classMethod->name->toString());

        foreach ($nodeFinder->findInstanceOf($statements, ClassLike::class) as $candidate) {
            // Only the class's named ancestors are worth searching.
            if (!$this->isNamedAncestorCandidate($candidate, $ancestorNames)) {
                continue;
            }

            // Private methods are not inherited and cannot supply the overriding method's contract.
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
     * @return bool - True when an inheritdoc or Override marker is present and the owning class-like can inherit a contract.
     */
    public function hasInheritanceMarker(ClassMethod $classMethod): bool
    {
        return ($this->hasInheritDoc($classMethod) || $this->hasOverrideAttribute($classMethod))
            && $this->canInheritContract($classMethod);
    }

    /**
     * Reports whether a method's class-like can inherit a contract from a declaration this file may not see.
     * Classes qualify through extends, implements or trait use; enums through implements or trait use.
     * Interfaces qualify through extends; traits qualify because their using class may supply the contract.
     *
     * @param ClassMethod $classMethod - Method whose owning class-like is read.
     *
     * @return bool - True for these inheritance paths; false for standalone classes, enums and interfaces or a missing owner.
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

        if ($class->extends instanceof Name) {
            $ancestorNames[] = $this->resolvedName($class->extends);
        }


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

        foreach ($nodeFinder->findInstanceOf($statements, ClassLike::class) as $candidate) {
            // Only the class's named ancestors are worth searching.
            if (!$this->isNamedAncestorCandidate($candidate, $ancestorNames)) {
                continue;
            }


            foreach ($candidate->getMethods() as $ancestorMethod) {
                if ($this->isDocumentedMethod($ancestorMethod, $methodName)) {
                    // First documented ancestor override is enough; the contract is inherited.
                    return true;
                }
            }
        }


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
            // Only classes and interfaces can be named by extends or implements in this lookup.
            return false;
        }

        // A declaration's namespaced name, when the parser resolved one, keeps a same-named class in another
        // namespace of this file from standing in for the real ancestor.
        $candidateName = ($candidate->namespacedName ?? $candidate->name)?->toLowerString();


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

        foreach ($classMethod->attrGroups as $group) {

            foreach ($group->attrs as $attribute) {
                // PHP's own attribute resolves to the global `Override`; a namespaced class of that name is not it.
                if ($this->resolvedName($attribute->name) === 'override') {
                    // Comparison is case-insensitive so #[Override] and #[override] both signal an override.
                    return true;
                }
            }
        }


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


        while ($parent instanceof Node) {
            if ($parent instanceof Class_) {

                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }


        return null;
    }

    /**
     * Returns the resolved name when available, otherwise the written name, lowercased for case-insensitive class-name comparison.
     *
     * @param Name $name - Name as written in an `extends`, `implements`, or attribute position.
     *
     * @return string - Resolved name or input name when unresolved, lowercased and without a leading backslash.
     */
    private function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ($resolved instanceof Name ? $resolved : $name)->toLowerString();
    }
}
