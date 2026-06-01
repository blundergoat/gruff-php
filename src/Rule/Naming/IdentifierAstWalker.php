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
     * @param list<Node>           $nodes - Roots to traverse.
     * @param callable(Node): bool $predicate - Predicate that selects matching descendants.
     *
     * @return list<Node> - every descendant satisfying the predicate, gathered across all roots; empty when none match
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
     * @param Node                 $node - Current node to test; recursion stops at function-like boundaries.
     * @param callable(Node): bool $predicate - Predicate that selects matching descendants.
     * @param list<Node>           $matches - Output list of matching descendant nodes.
     *
     * @return void
     */
    private function collectMatchingNodes(Node $node, callable $predicate, array &$matches): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            // Stop at a function-like boundary so inner-callable declarations stay out of the parent scope.
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
     * @param Node $node - Parent node whose declared sub-node slots are flattened into traversable children.
     *
     * @return list<Node> - the node's immediate child Nodes in sub-node declaration order; empty when it has no Node-valued slots
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
     * @param mixed      $subNode - One sub-node slot value: a Node, an array of them, or a scalar/null that is skipped.
     * @param list<Node> $children - Accumulator mutated in place; discovered child nodes are appended in traversal order.
     *
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;

            // A bare Node is itself a child; record it and do not recurse into a non-array.
            return;
        }

        if (!is_array($subNode)) {
            // Scalars, strings, and null are leaf slot values with no traversable children, so skip them.
            return;
        }

        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
    }
}
