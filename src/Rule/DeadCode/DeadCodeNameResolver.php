<?php

declare(strict_types=1);

namespace GruffPhp\Rule\DeadCode;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Resolves declaration and reference names from PHP-Parser metadata.
 */
final readonly class DeadCodeNameResolver
{
    /**
     * Resolve a class-like declaration FQN.
     *
     * @param Class_|Interface_|Trait_|Enum_ $node - Class-like declaration.
     *
     * @return string|null - declaration FQN, or null for anonymous classes
     */
    public function classLikeDeclarationFqn(Class_|Interface_|Trait_|Enum_ $node): ?string
    {
        if ($node instanceof Class_ && $node->isAnonymous()) {
            return null;
        }

        $name = $node->namespacedName ?? null;
        if (!$name instanceof Name) {
            return null;
        }

        return ltrim($name->toString(), '\\');
    }

    /**
     * Resolve a function declaration FQN.
     *
     * @param Function_ $function - Function declaration.
     *
     * @return string - fully qualified function name without a leading slash
     */
    public function functionDeclarationFqn(Function_ $function): string
    {
        $namespace = $this->namespacePrefixForNode($function);

        return $namespace . $function->name->toString();
    }

    /**
     * Resolve a standalone constant declaration FQN.
     *
     * @param Node\Const_ $constant - Constant declaration.
     *
     * @return string - fully qualified constant name without a leading slash
     */
    public function constantDeclarationFqn(Node\Const_ $constant): string
    {
        $namespace = $this->namespacePrefixForNode($constant);

        return $namespace . $constant->name->toString();
    }

    /**
     * Resolve a class-name reference.
     *
     * @param Name $name - Name node to resolve.
     * @param Node $originNode - Node where the reference appears.
     *
     * @return string|null - resolved FQN, or null when the name is contextual/dynamic
     */
    public function resolveClassName(Name $name, Node $originNode): ?string
    {
        $lower = strtolower($name->toString());
        if ($lower === 'self' || $lower === 'static') {
            return $this->enclosingClassFqn($originNode);
        }

        if ($lower === 'parent') {
            return null;
        }

        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }

        return ltrim($name->toString(), '\\');
    }

    /**
     * Resolve a function or constant name, including namespace fallback.
     *
     * @param Name $name - Function/constant name.
     * @param Node $originNode - Node where the reference appears.
     *
     * @return list<string> - candidate FQNs; unqualified names include current-namespace and global fallback forms
     */
    public function resolveFunctionOrConstantName(Name $name, Node $originNode): array
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return [ltrim($resolved->toString(), '\\')];
        }

        $raw = ltrim($name->toString(), '\\');
        if ($name->isFullyQualified()) {
            return [$raw];
        }

        $namespace = $this->namespacePrefixForNode($originNode);
        if ($namespace === '') {
            return [$raw];
        }

        if ($name->isUnqualified()) {
            return [$namespace . $raw, $raw];
        }

        return [$namespace . $raw];
    }

    /**
     * Resolve the enclosing function-like declaration FQN.
     *
     * @param Node $node - Node whose parents are searched.
     *
     * @return string|null - enclosing function-like FQN, or null outside function scope
     */
    public function enclosingFunctionFqn(Node $node): ?string
    {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current instanceof Function_) {
                return $this->functionDeclarationFqn($current);
            }

            if ($current instanceof Stmt\ClassMethod) {
                $classFqn = $this->enclosingClassFqn($current);
                return $classFqn === null ? null : $classFqn . '::' . $current->name->toString();
            }

            $current = $current->getAttribute('parent');
        }

        return null;
    }

    /**
     * Resolve the namespace prefix for a node.
     *
     * @param Node $node - Node whose parents are searched.
     *
     * @return string - namespace prefix with trailing slash, or empty string in the global namespace
     */
    private function namespacePrefixForNode(Node $node): string
    {
        $current = $node;
        while ($current instanceof Node) {
            if ($current instanceof Namespace_ && $current->name instanceof Name) {
                return ltrim($current->name->toString(), '\\') . '\\';
            }

            $current = $current->getAttribute('parent');
        }

        return '';
    }

    /**
     * Resolve the enclosing class-like declaration FQN.
     *
     * @param Node $node - Node whose parents are searched.
     *
     * @return string|null - enclosing class-like FQN, or null outside class-like scope
     */
    public function enclosingClassFqn(Node $node): ?string
    {
        $current = $node;
        while ($current instanceof Node) {
            if ($current instanceof Class_
                || $current instanceof Interface_
                || $current instanceof Trait_
                || $current instanceof Enum_
            ) {
                return $this->classLikeDeclarationFqn($current);
            }

            $current = $current->getAttribute('parent');
        }

        return null;
    }
}
