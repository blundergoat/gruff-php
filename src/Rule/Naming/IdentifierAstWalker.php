<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Scope-bounded AST walker used by IdentifierQualityRule. Stops at function-like
 * boundaries (closures, arrow functions, nested methods) so the parent scope's
 * locals do not pick up declarations from inner callables.
 */
final readonly class IdentifierAstWalker
{
    /**
     * @param list<Node>           $nodes     Roots to traverse.
     * @param callable(Node): bool $predicate Predicate that selects matching descendants.
     * @return list<Node> Descendant nodes that match the predicate.
     */
    public function nodesMatching(array $nodes, callable $predicate): array
    {
        $matches = [];

        foreach ($nodes as $node) {
            $this->collectMatchingNodes($node, $predicate, $matches);
        }

        return $matches;
    }

    /**
     * @param callable(Node): bool $predicate Predicate that selects matching descendants.
     * @param list<Node>           $matches   Output list of matching descendant nodes.
     * @return void
     */
    private function collectMatchingNodes(Node $node, callable $predicate, array &$matches): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            return;
        }

        if ($predicate($node)) {
            $matches[] = $node;
        }

        foreach ($this->childNodes($node) as $child) {
            $this->collectMatchingNodes($child, $predicate, $matches);
        }
    }

    /**
     * List direct child nodes that can be recursively traversed.
     *
     * @return list<Node>
     */
    private function childNodes(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $children);
        }

        return $children;
    }

    /**
     * Append traversable child nodes to the current collection.
     *
     * @param list<Node> $children
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            return;
        }

        if (!is_array($subNode)) {
            return;
        }

        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
    }
}
