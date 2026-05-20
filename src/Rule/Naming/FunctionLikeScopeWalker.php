<?php
declare(strict_types=1);
namespace GruffPhp\Rule\Naming;
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
     * @param list<Node> $statements
     * @return list<FunctionLikeScope>
     */
    public function scopes(array $statements): array
    {
        if ($statements === []) {
            return [];
        }
        $cache  = self::$cache ??= new WeakMap();
        $cached = $cache[$statements[0]] ?? null;
        if ($cached !== null && $cached['count'] === count($statements)) {
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
     * Recursively collect function-like scopes, descending only into scope bodies.
     *
     * @param list<FunctionLikeScope> $scopes
     * @return void
     */
    private function discoverScopes(Node $node, array &$scopes): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            $scopes[] = $this->scopeFor($node);
            foreach ($this->bodyNodes($node) as $child) {
                $this->discoverScopes($child, $scopes);
            }
            return;
        }
        foreach ($this->childNodes($node) as $child) {
            $this->discoverScopes($child, $scopes);
        }
    }
    /**
     * Build one isolated scope description for a function-like node.
     *
     * @return FunctionLikeScope Scope with parameters and local variables separated.
     */
    private function scopeFor(ClassMethod|Function_|Closure|ArrowFunction $node): FunctionLikeScope
    {
        $parameterNames  = $this->parameterNames($node);
        $bodyDescendants = $this->bodyDescendants($node);

        return new FunctionLikeScope(
            $node,
            $this->kind($node),
            $parameterNames,
            $this->localVariables($bodyDescendants, $parameterNames, $node),
            $bodyDescendants,
        );
    }
    /** @return array<string, true> */
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
     * @param list<Node>            $bodyDescendants Descendant nodes in this scope body.
     * @param array<string, true> $parameterNames
     * @return array<string, Variable>
     */
    private function localVariables(array $bodyDescendants, array $parameterNames, ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        $variables = [];
        $excluded  = $parameterNames;
        if ($node instanceof Closure) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $excluded[$use->var->name] = true;
                }
            }
        }
        foreach ($bodyDescendants as $child) {
            if ($child instanceof Variable && is_string($child->name) && !isset($excluded[$child->name])) {
                $variables[$child->name] ??= $child;
            }
        }
        return $variables;
    }

    /**
     * @return list<Node>
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
     * @param list<Node> $descendants
     * @return void
     */
    private function collectBodyDescendants(Node $node, array &$descendants): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            return;
        }

        $descendants[] = $node;

        foreach ($this->childNodes($node) as $child) {
            $this->collectBodyDescendants($child, $descendants);
        }
    }
    /** @return list<Node> */
    private function bodyNodes(ClassMethod|Function_|Closure|ArrowFunction $node): array
    {
        if ($node instanceof ArrowFunction) {
            return [$node->expr];
        }
        return array_values($node->stmts ?? []);
    }
    /**
     * Name the function-like node shape for synthetic symbols.
     *
     * @return string One of method, function, closure, or arrow.
     */
    private function kind(ClassMethod|Function_|Closure|ArrowFunction $node): string
    {
        return match (true) {
            $node instanceof ClassMethod => 'method',
            $node instanceof Function_ => 'function',
            $node instanceof Closure => 'closure',
            default => 'arrow',
        };
    }
    /** @return list<Node> */
    private function childNodes(Node $node): array
    {
        $children = [];
        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $children);
        }
        return $children;
    }
    /**
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
