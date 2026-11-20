<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Scope-bounded AST walker used by the identifier-quality rule to gather the descendant nodes inside a
 * single function-like scope. Stops at function-like boundaries (closures, arrow functions, nested methods)
 * so the parent scope's locals do not pick up declarations from inner callables.
 *
 * Keeping scopes separate is what lets the naming rules attribute a badly named variable to the exact method
 * or closure that declared it, instead of blaming an unrelated outer function.
 */
final readonly class IdentifierAstWalker
{
    /**
     * Collects matching roots and descendants without entering function-like nodes.
     *
     * @param list<Node>           $nodes - Roots to inspect; function-like roots and their contents are skipped.
     * @param callable(Node): bool $predicate - Predicate applied to each node within the traversable scope.
     *
     * @return list<Node> - Matching node occurrences in depth-first root order; empty when none match.
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
     * Appends matching nodes from this subtree, excluding function-like boundaries and their contents.
     *
     * @param Node                 $node - Current subtree root; function-like nodes are skipped without testing the predicate.
     * @param callable(Node): bool $predicate - Predicate that selects nodes to append.
     * @param list<Node>           $matches - Accumulator receiving matches in depth-first order.
     *
     * @return void - Appends matches to $matches in place.
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
     * Lists the direct child nodes that can be recursively traversed.
     *
     * @param Node $node - Parent node whose declared sub-node slots are flattened into traversable children.
     *
     * @return list<Node> - Direct child nodes in slot order, including nodes inside arrays; empty when none exist.
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
     * Appends the traversable child nodes found in one sub-node slot.
     *
     * @param mixed      $subNode - One sub-node slot value: a Node, an array of them, or a scalar/null that is skipped.
     * @param list<Node> $children - Accumulator mutated in place; discovered child nodes are appended in traversal order.
     *
     * @return void - Appends discovered nodes to $children in place.
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
