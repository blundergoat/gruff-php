<?php
declare(strict_types=1);
namespace GruffPhp\Rules\Naming;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use WeakMap;
/**
 * Discovers isolated function-like scopes for naming rules.
 */
final class FunctionLikeScopeWalker
{
    /** @var WeakMap<Node, array{count: int, scopes: list<FunctionLikeScope>}>|null */
    private static ?WeakMap $cache = null;
    /**
     * Build function-like scopes from top-level statements.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<Node> $statements - Top-level AST nodes for one parsed unit; the first node keys the scope cache.
     *
     * @return list<FunctionLikeScope> - One scope per function-like, including nested ones, in discovery order.
     */
    public function scopes(array $statements): array
    {
        // User view: choose the findings list branch for this case.
        // User view: an empty value becomes a clear findings list fallback.
        if ($statements === []) {
            return [];
        }
        $cache  = self::$cache ??= new WeakMap();
        // User view: missing data becomes a safe findings list default.
        $cached = $cache[$statements[0]] ?? null;
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($cached !== null && $cached['count'] === count($statements)) {
            // Same first node and statement count: the unit is unchanged, so reuse the memoised scopes.
            return $cached['scopes'];
        }
        $scopes = [];
        // User view: add each item that can appear in findings list.
        foreach ($statements as $statement) {
            $this->discoverScopes($statement, $scopes);
        }
        $cache[$statements[0]] = ['count' => count($statements), 'scopes' => $scopes];
        return $scopes;
    }
    /**
     * Recursively collect function-like scopes, descending only into scope bodies.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node                    $node - Current AST node being visited in the depth-first walk.
     * @param list<FunctionLikeScope> $scopes - Accumulator appended to in place as scopes are discovered.
     *
     * @return void
     */
    private function discoverScopes(Node $node, array &$scopes): void
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            $scopes[] = $this->scopeFor($node);
            // User view: add each item that can appear in findings list.
            foreach ($this->bodyNodes($node) as $child) {
                $this->discoverScopes($child, $scopes);
            }
            // This node opened a scope and its body is already walked, so stop before re-descending its children.
            return;
        }
        // User view: add each item that can appear in findings list.
        foreach ($this->childNodes($node) as $child) {
            $this->discoverScopes($child, $scopes);
        }
    }
    /**
     * Build one isolated scope description for a function-like node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Function-like node whose own scope is described.
     *
     * @return FunctionLikeScope - Scope with parameters and local variables separated.
     */
    private function scopeFor(ClassMethod|Function_|Closure|ArrowFunction $node): FunctionLikeScope
    {
        $parameterNames  = $this->parameterNames($node);
        $bodyDescendants = $this->bodyDescendants($node);

        // Build the scope with parameters and body locals kept apart so naming rules can treat them differently.
        return new FunctionLikeScope(
            $node,
            $this->kind($node),
            $parameterNames,
            $this->localVariables($bodyDescendants, $parameterNames, $node),
            $bodyDescendants,
        );
    }
    /**
     * Return parameter names keyed for fast exclusion checks.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose declared parameters are collected.
     *
     * @return array<string, true> - Parameter names declared by the node.
     */
    private function parameterNames(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        $names = [];
        // User view: add each item that can appear in findings list.
        foreach ($node->params as $param) {
            // User view: choose the findings list branch for this case.
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[$param->var->name] = true;
            }
        }
        return $names;
    }
    /**
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param list<Node>                                  $bodyDescendants - Descendant nodes in this scope body.
     * @param array<string, true>                         $parameterNames - Names to skip; parameters are not locals.
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Owning node; its `use` captures are skipped.
     *
     * @return array<string, Variable> - First occurrence of each genuinely local variable, keyed by name.
     */
    private function localVariables(array $bodyDescendants, array $parameterNames, ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        $variables = [];
        $excluded  = $parameterNames;
        // User view: choose the findings list branch for this case.
        if ($node instanceof Closure) {
            // User view: add each item that can appear in findings list.
            foreach ($node->uses as $use) {
                // User view: choose the findings list branch for this case.
                if (is_string($use->var->name)) {
                    $excluded[$use->var->name] = true;
                }
            }
        }
        // User view: add each item that can appear in findings list.
        foreach ($bodyDescendants as $child) {
            // User view: choose the findings list branch for this case.
            if ($child instanceof Variable && is_string($child->name) && !isset($excluded[$child->name])) {
                $variables[$child->name] ??= $child;
            }
        }
        return $variables;
    }

    /**
     * List all descendant nodes inside a function-like body.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose body subtree is flattened.
     *
     * @return list<Node> - Every body descendant, excluding any nested function-like and its contents.
     */
    private function bodyDescendants(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        $descendants = [];

        // User view: add each item that can appear in findings list.
        foreach ($this->bodyNodes($node) as $child) {
            $this->collectBodyDescendants($child, $descendants);
        }

        return $descendants;
    }

    /**
     * Append descendant nodes from a function-like body.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node       $node - Current node in the body walk.
     * @param list<Node> $descendants - Accumulator appended to in place with the in-scope nodes.
     *
     * @return void
     */
    private function collectBodyDescendants(Node $node, array &$descendants): void
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            // A nested callable owns its own scope, so prune it and its contents from the parent's descendants.
            return;
        }

        $descendants[] = $node;

        // User view: add each item that can appear in findings list.
        foreach ($this->childNodes($node) as $child) {
            $this->collectBodyDescendants($child, $descendants);
        }
    }
    /**
     * Return immediate body nodes for any supported function-like node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose direct body nodes are returned.
     *
     * @return list<Node> - Body nodes to scan.
     */
    private function bodyNodes(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        // User view: choose the findings list branch for this case.
        if ($node instanceof ArrowFunction) {
            // An arrow function has a single expression body rather than a statement list, so wrap it.
            return [$node->expr];
        }
        // Other function-likes carry a statement list; reindex it so callers get a clean zero-based list.
        // User view: missing data becomes a safe findings list default.
        return array_values($node->stmts ?? []);
    }
    /**
     * Name the function-like node shape for synthetic symbols.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose kind label is derived.
     *
     * @return string - One of method, function, closure, or arrow.
     */
    private function kind(ClassMethod|Function_|Closure|ArrowFunction $node): string
    {
        // The label feeds synthetic symbol names for anonymous scopes, so it must stay stable across releases.
        return match (true) {
            $node instanceof ClassMethod => 'method',
            $node instanceof Function_ => 'function',
            $node instanceof Closure => 'closure',
            default => 'arrow',
        };
    }
    /**
     * Return direct child nodes for recursive body traversal.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Parent node whose sub-node slots are scanned for child nodes.
     *
     * @return list<Node> - Child AST nodes.
     */
    private function childNodes(Node $node): array
    {
        $children = [];
        // User view: add each item that can appear in findings list.
        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $children);
        }
        return $children;
    }
    /**
     * Append traversable child nodes to the current collection.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param mixed      $subNode - A single sub-node slot value: a Node, an array of them, or a scalar to ignore.
     * @param list<Node> $children - Accumulator appended to in place with any Node found in the slot.
     *
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        // User view: choose the findings list branch for this case.
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            // A scalar slot holding a single node has nothing deeper to recurse into.
            return;
        }
        // User view: choose the findings list branch for this case.
        if (!is_array($subNode)) {
            // Scalars such as names, flags, and null carry no child nodes, so there is nothing to collect.
            return;
        }
        // User view: add each item that can appear in findings list.
        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
    }
}
