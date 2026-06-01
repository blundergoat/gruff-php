<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeFinder;

/**
 * Resolves inherited method documentation contracts for docs rules.
 */
final readonly class DocsInheritanceHelper
{
    /**
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
     * Return short parent and implemented interface names for a class.
     *
     * @param Class_ $class - Class declaration whose direct parent and interfaces should be collected.
     *
     * @return list<string> - Ancestor names declared directly on the class.
     */
    private function ancestorNames(Class_ $class): array
    {
        $ancestorNames = [];
        if ($class->extends instanceof Name) {
            $ancestorNames[] = $this->shortName($class->extends);
        }

        foreach ($class->implements as $interface) {
            $ancestorNames[] = $this->shortName($interface);
        }

        // Direct parent plus implemented interfaces only; transitive ancestors are not resolved here.
        return $ancestorNames;
    }

    /**
     * Check direct ancestors in the same parsed unit for a documented method contract.
     *
     * @param list<string>    $ancestorNames - Short names of direct ancestors.
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

        // No same-unit ancestor documents this method, so the override carries no inherited contract.
        return false;
    }

    /**
     * Check whether a class-like node is a named direct ancestor candidate.
     *
     * @param ClassLike    $candidate - Class-like node found in the unit, tested against the ancestor list.
     * @param list<string> $ancestorNames - Short names of direct ancestors.
     *
     * @return bool - True when the candidate should be searched for methods.
     */
    private function isNamedAncestorCandidate(ClassLike $candidate, array $ancestorNames): bool
    {
        if (!$candidate instanceof Class_ && !$candidate instanceof Interface_) {
            // Traits and enums cannot be ancestors, so they never supply inherited method contracts.
            return false;
        }

        $candidateName = $candidate->name?->toString();

        // Anonymous classes (null name) and unrelated types are excluded; only listed ancestors match.
        return $candidateName !== null && in_array($candidateName, $ancestorNames, true);
    }

    /**
     * Check whether an ancestor method matches the target name and has PHPDoc.
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
     * Check whether a method docblock declares inheritdoc.
     *
     * @param ClassMethod $classMethod - Method whose own docblock is scanned for an inheritdoc marker.
     *
     * @return bool - True when inheritdoc is present.
     */
    private function hasInheritDoc(ClassMethod $classMethod): bool
    {
        $doc = $classMethod->getDocComment();

        // Match both block `@inheritdoc` and inline `{@inheritdoc}` inheritance markers.
        return $doc !== null && preg_match('/@inheritdoc|{@inheritdoc}/i', $doc->getText()) === 1;
    }

    /**
     * Check whether a method has an Override attribute.
     *
     * @param ClassMethod $classMethod - Method whose attribute groups are scanned for #[Override].
     *
     * @return bool - True when an Override attribute is present.
     */
    private function hasOverrideAttribute(ClassMethod $classMethod): bool
    {
        foreach ($classMethod->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $shortName = strtolower($this->shortName($attribute->name));
                if ($shortName === 'override') {
                    // Comparison is case-insensitive so #[Override] and #[override] both signal an override.
                    return true;
                }
            }
        }

        // No attribute named override on any group, so the method does not assert an inherited contract.
        return false;
    }

    /**
     * Walk parent attributes to find the enclosing class.
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
                // Nearest enclosing Class_ wins; interfaces and traits are skipped by the loop condition.
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        // The chain ended without a Class_, so the method lives outside any class body.
        return null;
    }

    /**
     * Return the final segment of a name node.
     *
     * @param Name $name - Possibly namespaced name whose last segment is wanted.
     *
     * @return string - Unqualified name.
     */
    private function shortName(Name $name): string
    {
        $parts = $name->getParts();

        // Last segment is the unqualified short name, dropping any namespace prefix.
        return $parts[array_key_last($parts)];
    }
}
