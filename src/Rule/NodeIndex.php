<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Parser\AnalysisUnit;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use WeakMap;

/**
 * Per-unit node lookup cache. Builds one preorder walk per AnalysisUnit and
 * returns the resulting per-class node lists in O(1) for every rule that asks.
 */
final class NodeIndex
{
    /**
     * Cache keyed on AnalysisUnit. Each value is a map of FQCN -> list of
     * matching nodes in preorder, matching NodeFinder semantics.
     *
     * @var WeakMap<AnalysisUnit, array<class-string<Node>, list<Node>>>|null
     */
    private static ?WeakMap $cache = null;

    /**
     * Cache keyed on function-like nodes. Each value stores the node's body
     * descendants only, matching the previous NodeFinder calls over stmts.
     *
     * @var WeakMap<Node, list<Node>>|null
     */
    private static ?WeakMap $bodyCache = null;

    /**
     * Cache keyed on function-like nodes for logical statement line counts.
     *
     * @var WeakMap<Node, int>|null
     */
    private static ?WeakMap $logicalLineCountCache = null;

    /**
     * Return every node of the given concrete class in preorder.
     *
     * Matches NodeFinder::findInstanceOf semantics for a single class but
     * relies on a shared per-unit cache so repeat queries do no extra work.
     *
     * @template T of Node
     * @param AnalysisUnit    $analysisUnit Parsed unit whose AST is indexed.
     * @param class-string<T> $class
     * @return list<T>
     */
    public static function nodesOf(AnalysisUnit $analysisUnit, string $class): array
    {
        $byClass = self::index($analysisUnit);
        /** @var list<T> $nodes Cached entries are keyed by the exact requested class-string. */
        $nodes = $byClass[$class] ?? [];

        return $nodes;
    }

    /**
     * Return nodes that match any of the supplied classes in preorder.
     *
     * @param AnalysisUnit              $analysisUnit Parsed unit whose AST is indexed.
     * @param list<class-string<Node>> $classes
     * @return list<Node>
     */
    public static function nodesOfAny(AnalysisUnit $analysisUnit, array $classes): array
    {
        $byClass = self::index($analysisUnit);

        if (count($classes) === 1) {
            return $byClass[$classes[0]] ?? [];
        }

        $combined = [];
        foreach ($classes as $class) {
            if (!isset($byClass[$class])) {
                continue;
            }
            foreach ($byClass[$class] as $node) {
                $combined[spl_object_id($node)] = $node;
            }
        }

        return array_values($combined);
    }

    /**
     * Return every descendant below a function or method body in preorder.
     *
     * @param Node $node Function-like node whose statements should be walked.
     * @return list<Node>
     */
    public static function bodyDescendants(Node $node): array
    {
        if (!$node instanceof ClassMethod && !$node instanceof Function_ && !$node instanceof Closure) {
            return [];
        }

        return self::bodyIndex($node);
    }

    /**
     * Count distinct non-Nop statement start lines below a function-like body.
     *
     * @param Node $node Function-like node whose logical lines should be counted.
     * @return int Distinct logical statement line count.
     */
    public static function logicalStatementLineCount(Node $node): int
    {
        self::$logicalLineCountCache ??= new WeakMap();
        $cached = self::$logicalLineCountCache[$node] ?? null;
        if (is_int($cached)) {
            return $cached;
        }

        $lines = [];
        foreach (self::bodyDescendants($node) as $child) {
            if (!$child instanceof Stmt || $child instanceof Nop) {
                continue;
            }

            $line = $child->getStartLine();
            if ($line > 0) {
                $lines[$line] = true;
            }
        }

        $count = count($lines);

        self::$logicalLineCountCache[$node] = $count;

        return $count;
    }

    /**
     * @return array<class-string<Node>, list<Node>>
     */
    private static function index(AnalysisUnit $analysisUnit): array
    {
        self::$cache ??= new WeakMap();
        $cached = self::$cache[$analysisUnit] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<class-string<Node>, list<Node>> */
            private array $nodesByClass = [];

            /**
             * Add a node to the concrete-class index.
             *
             * @param Node $node Node currently being traversed.
             * @return null Keeps traversal running.
             */
            public function enterNode(Node $node): null
            {
                $this->nodesByClass[$node::class][] = $node;

                return null;
            }

            /**
             * Return nodes collected by concrete class.
             *
             * @return array<class-string<Node>, list<Node>>
             */
            public function nodesByClass(): array
            {
                return $this->nodesByClass;
            }
        };

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse($analysisUnit->statements);

        self::$cache[$analysisUnit] = $visitor->nodesByClass();

        return $visitor->nodesByClass();
    }

    /**
     * @return list<Node>
     */
    private static function bodyIndex(Node $node): array
    {
        self::$bodyCache ??= new WeakMap();
        $cached = self::$bodyCache[$node] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            private array $nodes = [];

            /**
             * Add a body descendant to the source-order list.
             *
             * @param Node $node Node currently being traversed.
             * @return null Keeps traversal running.
             */
            public function enterNode(Node $node): null
            {
                $this->nodes[] = $node;

                return null;
            }

            /**
             * Return body descendants in source order.
             *
             * @return list<Node>
             */
            public function nodes(): array
            {
                return $this->nodes;
            }
        };

        $statements = [];
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure) {
            $statements = $node->stmts ?? [];
        }

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse($statements);

        self::$bodyCache[$node] = $visitor->nodes();

        return $visitor->nodes();
    }
}
