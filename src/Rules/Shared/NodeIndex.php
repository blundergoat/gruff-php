<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Shared;

use GruffPhp\Engine\Parser\AnalysisUnit;
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
 * Caches AST node lookups and function-body metrics for rule scans.
 * A unit walk builds preorder buckets for concrete classes and supported ancestors and interfaces.
 * Streaming callers evict the unit's buckets before releasing its AST; body caches use node keys.
 */
final class NodeIndex
{
    /**
     * Caches preorder node buckets under each analysis unit.
     * The universal Node and NodeAbstract types are not indexed.
     *
     * @var WeakMap<AnalysisUnit, array<class-string<Node>, list<Node>>>|null - Unit buckets, or null before indexing begins.
     */
    private static ?WeakMap $cache = null;

    /**
     * Caches descendants reached through a function, method, or closure's statements.
     * Entries are keyed by the body-owning node and exclude that node itself.
     *
     * @var WeakMap<Node, list<Node>>|null - Body descendant lists, or null before the first body lookup.
     */
    private static ?WeakMap $bodyCache = null;

    /**
     * Cache keyed on function-like nodes for logical statement line counts.
     *
     * @var WeakMap<Node, int>|null - Cached counts, or null before the first count request.
     */
    private static ?WeakMap $logicalLineCountCache = null;

    /**
     * Process-wide cache of class hierarchy keys per concrete node class, so
     * each visitor instance can reuse parent/interface lookups across files.
     *
     * @var array<class-string<Node>, list<class-string<Node>>> - Reusable index keys, excluding the universal node types.
     */
    private static array $hierarchyCache = [];

    /**
     * Remove a unit's class-index entry before its AST is released.
     * Node-keyed body and line-count caches are left to WeakMap lifetime handling.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to remove from the cache.
     *
     * @return void - Removes the unit's class-index entry if it exists.
     */
    public static function evictUnit(AnalysisUnit $analysisUnit): void
    {

        if (self::$cache !== null) {
            unset(self::$cache[$analysisUnit]);
        }
    }

    /**
     * Return cached preorder nodes for a concrete class or indexed ancestor/interface.
     * The universal Node and NodeAbstract types are not indexed.
     *
     * @template T of Node
     * @param AnalysisUnit $analysisUnit - Parsed unit whose AST is indexed.
     * @param class-string<T> $class - Concrete or supported ancestor/interface type whose bucket is requested.
     *
     * @return list<T> - Matching nodes in preorder; empty when the requested type has no indexed bucket.
     */
    public static function nodesOf(AnalysisUnit $analysisUnit, string $class): array
    {
        $byClass = self::index($analysisUnit);
        /** @var list<T> $nodes - Cached entries are keyed by the exact requested class-string. */

        $nodes = $byClass[$class] ?? [];

        return $nodes;
    }

    /**
     * Returns the union of nodes matching any of several classes, deduplicated, in preorder.
     *
     * @param AnalysisUnit             $analysisUnit - Parsed unit whose AST is indexed.
     * @param list<class-string<Node>> $classes - Node classes to union from the per-unit index.
     *
     * @return list<Node> - union of matching nodes in preorder, deduplicated so a node matching several classes appears once; empty when none match.
     */
    public static function nodesOfAny(AnalysisUnit $analysisUnit, array $classes): array
    {
        $byClass = self::index($analysisUnit);

        if (count($classes) === 1) {
            // Single class needs no dedup, so hand back its cached preorder list directly.
            return $byClass[$classes[0]] ?? [];
        }

        $combined = [];
        // Union the buckets for each requested class.
        foreach ($classes as $class) {
            // Skip a class the unit never used.
            if (!isset($byClass[$class])) {
                continue;
            }
            // Key by object id so a node matching two classes is kept only once.
            foreach ($byClass[$class] as $node) {
                $combined[spl_object_id($node)] = $node;
            }
        }

        return array_values($combined);
    }

    /**
     * Returns every descendant inside a function/method/closure body in preorder, empty for anything else.
     *
     * @param Node $node - Function-like node whose statements should be walked.
     *
     * @return list<Node> - body descendants in preorder; empty when the node has no body or is not function-like.
     */
    public static function bodyDescendants(Node $node): array
    {
        if (!$node instanceof ClassMethod && !$node instanceof Function_ && !$node instanceof Closure) {

            return [];
        }


        return self::bodyIndex($node);
    }

    /**
     * Return each body descendant matching any requested class, once, in preorder.
     *
     * @template T of Node
     * @param Node $node - Function-like node whose body should be scanned.
     * @param list<class-string<T>> $classes - Node classes or interfaces accepted by the filter.
     *
     * @return list<T> - Body descendants matching any supplied class, in preorder, each kept once; empty when no classes or no body.
     */
    public static function descendantsOfAny(Node $node, array $classes): array
    {
        if ($classes === [] || self::bodyDescendants($node) === []) {

            return [];
        }

        $matches = [];

        foreach (self::bodyDescendants($node) as $descendant) {

            foreach ($classes as $class) {
                if ($descendant instanceof $class) {
                    $matches[] = $descendant;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Counts the distinct source lines that carry a real statement in a function-like body.
     *
     * @param Node $node - Function-like node whose logical lines should be counted.
     *
     * @return int - Distinct positive statement start lines, excluding Nop nodes; zero when no statements qualify.
     */
    public static function logicalStatementLineCount(Node $node): int
    {
        self::$logicalLineCountCache ??= new WeakMap();
        $cached                      = self::$logicalLineCountCache[$node] ?? null;
        if (is_int($cached)) {

            return $cached;
        }

        $lines = [];

        foreach (self::bodyDescendants($node) as $child) {
            // Skip non-statements and Nop placeholders; they carry no real line.
            if (!$child instanceof Stmt || $child instanceof Nop) {
                continue;
            }

            $line = $child->getStartLine();
            // Exclude unavailable source locations from the logical line count.
            if ($line > 0) {
                $lines[$line] = true;
            }
        }

        $count = count($lines);

        self::$logicalLineCountCache[$node] = $count;

        return $count;
    }

    /**
     * Build or reuse a unit's preorder buckets for supported node hierarchy types.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose AST supplies the cached buckets.
     *
     * @return array<class-string<Node>, list<Node>> - Preorder buckets; Node and NodeAbstract are excluded.
     */
    private static function index(AnalysisUnit $analysisUnit): array
    {
        self::$cache ??= new WeakMap();
        $cached      = self::$cache[$analysisUnit] ?? null;
        if ($cached !== null) {

            return $cached;
        }

        $hierarchyCacheRef = &self::$hierarchyCache;
        $visitor           = new class ($hierarchyCacheRef) extends NodeVisitorAbstract {
            /** @var array<class-string<Node>, list<Node>> - Preorder buckets collected for indexed hierarchy types. */
            private array $nodesByClass = [];

            /**
             * Wires the walk to the shared, process-wide class-hierarchy cache.
             *
             * @param array<class-string<Node>, list<class-string<Node>>> $hierarchyCache - Shared, process-wide cache of class hierarchy keys.
             */
            public function __construct(private array &$hierarchyCache)
            {
            }

            /**
             * Index a visited node under its concrete class and supported ancestor and interface keys.
             *
             * @param Node $node - Node currently being traversed.
             *
             * @return null - Leaves the node unchanged and continues traversal into its children.
             */
            public function enterNode(Node $node): null
            {
                $concrete = $node::class;
                // Compute this concrete class's full key set once, then cache it process-wide.
                if (!isset($this->hierarchyCache[$concrete])) {
                    /** @var list<class-string<Node>> $hierarchy - Node hierarchy keys; narrows the dynamic class name for PHPStan. */
                    $hierarchy = [$concrete];
                    /** @var array<string, class-string<Node>> $parents - Parent class names for this Node implementation. */
                    $parents = class_parents($concrete) ?: [];
                    // Index under each ancestor class too, so a base-class lookup finds the node.
                    foreach ($parents as $parent) {
                        // Skip PhpParser's abstract base; no rule queries by it.
                        if ($parent === 'PhpParser\NodeAbstract') {
                            continue;
                        }
                        $hierarchy[] = $parent;
                    }
                    /** @var array<string, class-string<Node>> $implements - Interface names returned for the concrete node class. */
                    $implements = class_implements($concrete) ?: [];

                    foreach ($implements as $interface) {
                        // Skip the universal Node interface; every node has it, so it is no filter.
                        if ($interface === 'PhpParser\Node') {
                            continue;
                        }
                        $hierarchy[] = $interface;
                    }
                    $this->hierarchyCache[$concrete] = $hierarchy;
                }


                foreach ($this->hierarchyCache[$concrete] as $key) {
                    $this->nodesByClass[$key][] = $node;
                }

                return null;
            }

            /**
             * Return the accumulated buckets for indexed node hierarchy types.
             *
             * @return array<class-string<Node>, list<Node>> - Indexed nodes in preorder within each bucket.
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

        // nodesByClass() preserves the non-null return type because self::$cache offset access remains nullable to static analysis.
        return $visitor->nodesByClass();
    }

    /**
     * Walks a function-like body once and returns its descendants in preorder, memoised against the node.
     *
     * @param Node $node - Function-like node confirmed to carry a body; its statements are walked once and memoised.
     *
     * @return list<Node> - body descendants in preorder; empty when the body holds no statements.
     */
    private static function bodyIndex(Node $node): array
    {
        self::$bodyCache ??= new WeakMap();
        $cached          = self::$bodyCache[$node] ?? null;
        if ($cached !== null) {

            return $cached;
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Node> - Body descendants collected in traversal order. */
            private array $nodes = [];

            /**
             * Appends each visited body descendant to the source-order list.
             *
             * @param Node $node - Node currently being traversed.
             *
             * @return null - the PhpParser visitor signal to leave the node unchanged and keep descending into the body.
             */
            public function enterNode(Node $node): null
            {
                $this->nodes[] = $node;

                return null;
            }

            /**
             * Returns the body descendants in the source order the walk visited them.
             *
             * @return list<Node> - body descendants in the source order the preorder walk visited them.
             */
            public function nodes(): array
            {
                return $this->nodes;
            }
        };

        $statements = [];

        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure) {
            // A body that was never populated yields an empty walk.
            $statements = $node->stmts ?? [];
        }

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($visitor);
        $nodeTraverser->traverse($statements);

        self::$bodyCache[$node] = $visitor->nodes();

        // nodes() preserves the non-null return type because self::$bodyCache offset access remains nullable to static analysis.
        return $visitor->nodes();
    }
}
