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

final readonly class DocsInheritanceHelper
{
    /**
     * @param list<Node\Stmt> $statements
     */
    public function hasInheritedContractDoc(ClassMethod $method, array $statements, NodeFinder $finder): bool
    {
        if ($this->hasInheritDoc($method) || $this->hasOverrideAttribute($method)) {
            return true;
        }

        $class = $this->enclosingClass($method);
        if (!$class instanceof Class_) {
            return false;
        }

        $ancestorNames = [];
        if ($class->extends instanceof Name) {
            $ancestorNames[] = $this->shortName($class->extends);
        }

        foreach ($class->implements as $interface) {
            $ancestorNames[] = $this->shortName($interface);
        }

        if ($ancestorNames === []) {
            return false;
        }

        $methodName = strtolower($method->name->toString());

        foreach ($finder->findInstanceOf($statements, ClassLike::class) as $candidate) {
            if (!$candidate instanceof Class_ && !$candidate instanceof Interface_) {
                continue;
            }

            $candidateName = $candidate->name?->toString();
            if ($candidateName === null || !in_array($candidateName, $ancestorNames, true)) {
                continue;
            }

            foreach ($candidate->getMethods() as $ancestorMethod) {
                if (strtolower($ancestorMethod->name->toString()) === $methodName && $ancestorMethod->getDocComment() !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasInheritDoc(ClassMethod $method): bool
    {
        $doc = $method->getDocComment();

        return $doc !== null && preg_match('/@inheritdoc|{@inheritdoc}/i', $doc->getText()) === 1;
    }

    private function hasOverrideAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $shortName = strtolower($this->shortName($attribute->name));
                if ($shortName === 'override') {
                    return true;
                }
            }
        }

        return false;
    }

    private function enclosingClass(ClassMethod $method): ?Class_
    {
        $parent = $method->getAttribute('parent');

        while ($parent instanceof Node) {
            if ($parent instanceof Class_) {
                return $parent;
            }

            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    private function shortName(Name $name): string
    {
        $parts = $name->getParts();

        return $parts[array_key_last($parts)];
    }
}
