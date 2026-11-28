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
 * Discovers the isolated function-like scopes in a parsed unit so the naming rules can judge each method,
 * function, closure, and arrow function on its own.
 *
 * Walks the AST depth-first, opening a fresh scope at every function-like boundary and pruning nested
 * callables from their parent's view, so a variable is only ever attributed to the scope that declares it.
 * Results are memoised per unit via a WeakMap keyed on the first statement.
 */
final class FunctionLikeScopeWalker
{
    /** @var WeakMap<Node, array{count: int, scopes: list<FunctionLikeScope>}>|null - Cached scopes by first statement; null before first use. */
    private static ?WeakMap $cache = null;
    /**
     * Builds the function-like scopes for a parsed unit, memoised per unit.
     *
     * @param list<Node> $statements - Top-level AST nodes for one parsed unit; the first node keys the scope cache.
     *
     * @return list<FunctionLikeScope> - One scope per function-like, including nested ones, in discovery order.
     */
    public function scopes(array $statements): array
    {

        if ($statements === []) {
            return [];
        }
        $cache  = self::$cache ??= new WeakMap();
        $cached = $cache[$statements[0]] ?? null;
        if ($cached !== null && $cached['count'] === count($statements)) {
            // Same first node and statement count: the unit is unchanged, so reuse the memoised scopes.
            return $cached['scopes'];
        }
        $scopes = [];

        foreach ($statements as $statement) {
            $this->discoverScopes($statement, $scopes);
        }
        $cache[$statements[0]] = ['count' => count($statements), 'scopes' => $scopes];
        return $scopes;
    }
    /**
     * Collects function-like scopes depth-first, entering only the body of each discovered callable.
     *
     * @param Node                    $node - Current AST node being visited in the depth-first walk.
     * @param list<FunctionLikeScope> $scopes - Accumulator appended to in place as scopes are discovered.
     *
     * @return void - Appends discovered scopes to $scopes in traversal order.
     */
    private function discoverScopes(Node $node, array &$scopes): void
    {

        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            $scopes[] = $this->scopeFor($node);

            foreach ($this->bodyNodes($node) as $child) {
                $this->discoverScopes($child, $scopes);
            }
            // This node opened a scope and its body is already walked, so stop before re-descending its children.
            return;
        }

        foreach ($this->childNodes($node) as $child) {
            $this->discoverScopes($child, $scopes);
        }
    }
    /**
     * Builds one isolated scope description for a function-like node.
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
     * Returns the parameter names keyed for exclusion checks.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose declared parameters are collected.
     *
     * @return array<string, true> - Parameter names declared by the node.
     */
    private function parameterNames(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        $names = [];

        foreach ($node->params as $param) {

            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[$param->var->name] = true;
            }
        }
        return $names;
    }
    /**
     * Collects the first occurrence of each genuinely local variable in the body.
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
        // A closure's use() captures come from the parent scope, so they are not locals here.
        if ($node instanceof Closure) {
            // Exclude each captured variable by name.
            foreach ($node->uses as $use) {
                // A dynamically named capture has no name to exclude.
                if (is_string($use->var->name)) {
                    $excluded[$use->var->name] = true;
                }
            }
        }
        // Record the first sighting of each genuine local.
        foreach ($bodyDescendants as $child) {
            // Keep plainly named variables that are neither a parameter nor a capture.
            if ($child instanceof Variable && is_string($child->name) && !isset($excluded[$child->name])) {
                $variables[$child->name] ??= $child;
            }
        }
        return $variables;
    }

    /**
     * Lists all descendant nodes inside a function-like body.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose body subtree is flattened.
     *
     * @return list<Node> - Every body descendant, excluding any nested function-like and its contents.
     */
    private function bodyDescendants(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        $descendants = [];


        foreach ($this->bodyNodes($node) as $child) {
            $this->collectBodyDescendants($child, $descendants);
        }

        return $descendants;
    }

    /**
     * Appends the in-scope descendant nodes from a function-like body.
     *
     * @param Node       $node - Current node in the body walk.
     * @param list<Node> $descendants - Accumulator appended to in place with the in-scope nodes.
     *
     * @return void - Appends only nodes outside nested callable subtrees to $descendants.
     */
    private function collectBodyDescendants(Node $node, array &$descendants): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            // A nested callable owns its own scope, so prune it and its contents from the parent's descendants.
            return;
        }

        $descendants[] = $node;


        foreach ($this->childNodes($node) as $child) {
            $this->collectBodyDescendants($child, $descendants);
        }
    }
    /**
     * Returns the immediate body nodes for any supported function-like node.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose direct body nodes are returned.
     *
     * @return list<Node> - Body nodes to scan.
     */
    private function bodyNodes(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        if ($node instanceof ArrowFunction) {
            // An arrow function has a single expression body rather than a statement list, so wrap it.
            return [$node->expr];
        }
        // Methods without a body yield an empty list; otherwise callers receive a zero-based statement list.
        return array_values($node->stmts ?? []);
    }
    /**
     * Names the function-like node shape for synthetic symbols.
     *
     * @param ClassMethod|Function_|Closure|ArrowFunction $node - Node whose kind label is derived.
     *
     * @return string - One of method, function, closure, or arrow.
     */
    private function kind(ClassMethod|Function_|Closure|ArrowFunction $node): string
    {
        // The label becomes part of synthetic symbols for anonymous scopes.
        return match (true) {
            $node instanceof ClassMethod => 'method',
            $node instanceof Function_ => 'function',
            $node instanceof Closure => 'closure',
            default => 'arrow',
        };
    }
    /**
     * Returns the direct child nodes for recursive body traversal.
     *
     * @param Node $node - Parent node whose sub-node slots are scanned for child nodes.
     *
     * @return list<Node> - Child AST nodes.
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
     * @param mixed      $subNode - A single sub-node slot value: a Node, an array of them, or a scalar to ignore.
     * @param list<Node> $children - Accumulator appended to in place with any Node found in the slot.
     *
     * @return void - Appends Node instances to $children without traversing their descendants.
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            // Node objects are immediate children here; a later traversal handles their descendants.
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
