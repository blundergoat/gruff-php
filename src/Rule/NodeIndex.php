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
     * @param class-string<T> $class
     * @return list<T>
     */
    public static function nodesOf(AnalysisUnit $analysisUnit, string $class): array
    {
        $byClass = self::index($analysisUnit);
        /** @var list<T> $nodes */
        $nodes = $byClass[$class] ?? [];

        return $nodes;
    }

    /**
     * Return nodes that match any of the supplied classes in preorder.
     *
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
     * @return list<Node>
     */
    public static function bodyDescendants(Node $node): array
    {
        return self::bodyIndex($node);
    }

    /**
     * Count distinct non-Nop statement start lines below a function-like body.
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
            public array $byClass = [];

            public function enterNode(Node $node): null
            {
                $this->byClass[$node::class][] = $node;

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($analysisUnit->statements);

        self::$cache[$analysisUnit] = $visitor->byClass;

        return $visitor->byClass;
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
            public array $all = [];

            public function enterNode(Node $node): null
            {
                $this->all[] = $node;

                return null;
            }
        };

        $statements = [];
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure) {
            $statements = $node->stmts ?? [];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($statements);

        self::$bodyCache[$node] = $visitor->all;

        return $visitor->all;
    }
}
