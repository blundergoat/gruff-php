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
     * Process-wide cache of class hierarchy keys per concrete node class, so
     * each visitor instance can reuse parent/interface lookups across files.
     *
     * @var array<class-string<Node>, list<class-string<Node>>>
     */
    private static array $hierarchyCache = [];

    /**
     * Evict every cache entry whose key transitively depends on the given
     * analysis unit. Use this from streaming flows that release the unit's
     * AST immediately after analysis so the index does not pin already-
     * unreachable nodes until the unit itself is garbage collected.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to remove from the cache.
     *
     * @return void
     */
    public static function evictUnit(AnalysisUnit $analysisUnit): void
    {
        if (self::$cache !== null) {
            unset(self::$cache[$analysisUnit]);
        }
    }

    /**
     * Return every node of the given concrete class in preorder.
     *
     * Matches NodeFinder::findInstanceOf semantics for a single class but
     * relies on a shared per-unit cache so repeat queries do no extra work.
     *
     * @template T of Node
     * @param AnalysisUnit    $analysisUnit - Parsed unit whose AST is indexed.
     * @param class-string<T> $class - Exact node class to retrieve from the per-unit index.
     *
     * @return list<T> - matching nodes in document preorder; empty when the unit holds none of that class
     */
    public static function nodesOf(AnalysisUnit $analysisUnit, string $class): array
    {
        $byClass = self::index($analysisUnit);
        /** @var list<T> $nodes Cached entries are keyed by the exact requested class-string. */
        $nodes = $byClass[$class] ?? [];

        // Empty list (not null) when the unit holds no node of that class, so callers can foreach unconditionally.
        return $nodes;
    }

    /**
     * Return nodes that match any of the supplied classes in preorder.
     *
     * @param AnalysisUnit             $analysisUnit - Parsed unit whose AST is indexed.
     * @param list<class-string<Node>> $classes - Node classes to union from the per-unit index.
     *
     * @return list<Node> - union of matching nodes in preorder, deduplicated so a node matching several classes appears once; empty when none match
     */
    public static function nodesOfAny(AnalysisUnit $analysisUnit, array $classes): array
    {
        $byClass = self::index($analysisUnit);

        if (count($classes) === 1) {
            // Single class needs no dedup, so hand back its cached preorder list directly.
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

        // Reindex to a 0-based list; the object-id keys only existed to drop nodes matching more than one class.
        return array_values($combined);
    }

    /**
     * Return every descendant below a function or method body in preorder.
     *
     * @param Node $node - Function-like node whose statements should be walked.
     *
     * @return list<Node> - body descendants in preorder; empty when the node has no body or is not function-like
     */
    public static function bodyDescendants(Node $node): array
    {
        if (!$node instanceof ClassMethod && !$node instanceof Function_ && !$node instanceof Closure) {
            // A node with no body has no descendants to walk, so treat it as empty rather than rejecting the caller.
            return [];
        }

        // Only function-like nodes reach here, so the body cache can safely assume a statement list exists.
        return self::bodyIndex($node);
    }

    /**
     * Return body descendants of a function-like node that are instances of any
     * supplied class, preserving preorder. Use this for scope-bounded rule
     * scans (e.g. test-method scans) that previously called NodeFinder->find
     * with a class-disjunction predicate on a function body.
     *
     * @template T of Node
     * @param Node                  $node - Function-like node whose body should be scanned.
     * @param list<class-string<T>> $classes - Concrete node classes to keep.
     *
     * @return list<T> - body descendants matching any supplied class, in preorder, each kept once; empty when no classes or no body
     */
    public static function descendantsOfAny(Node $node, array $classes): array
    {
        if ($classes === [] || self::bodyDescendants($node) === []) {
            // No filter classes or no body means nothing can match, so skip the scan entirely.
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

        // Each match appears once in body preorder; the inner break stops a node counting per class it satisfies.
        return $matches;
    }

    /**
     * Count distinct non-Nop statement start lines below a function-like body.
     *
     * @param Node $node - Function-like node whose logical lines should be counted.
     *
     * @return int - count of distinct start lines carrying a real statement; Nop placeholders are excluded and statements sharing one physical line
     *             count once
     */
    public static function logicalStatementLineCount(Node $node): int
    {
        self::$logicalLineCountCache ??= new WeakMap();
        $cached                      = self::$logicalLineCountCache[$node] ?? null;
        if (is_int($cached)) {
            // is_int guards the WeakMap miss; a hit means this body was already counted, so reuse it.
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

        // Distinct start lines, so multiple statements sharing one physical line count once toward body size.
        return $count;
    }

    /**
     * Index parsed nodes by concrete PhpParser class.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose full AST is walked once; the result is memoised against it.
     *
     * @return array<class-string<Node>, list<Node>> - map from each concrete, ancestor, and interface class-string to its preorder nodes
     */
    private static function index(AnalysisUnit $analysisUnit): array
    {
        self::$cache ??= new WeakMap();
        $cached      = self::$cache[$analysisUnit] ?? null;
        if ($cached !== null) {
            // A populated entry means this unit was already walked, so reuse it instead of re-traversing the AST.
            return $cached;
        }

        $hierarchyCacheRef = &self::$hierarchyCache;
        $visitor           = new class ($hierarchyCacheRef) extends NodeVisitorAbstract {
            /** @var array<class-string<Node>, list<Node>> */
            private array $nodesByClass = [];

            /**
             * @param array<class-string<Node>, list<class-string<Node>>> $hierarchyCache - Shared, process-wide cache of class hierarchy keys.
             */
            public function __construct(private array &$hierarchyCache)
            {
            }

            /**
             * Add a node to the index under every ancestor class and
             * implemented interface so abstract base lookups still work.
             *
             * @param Node $node - Node currently being traversed.
             *
             * @return null - the PhpParser visitor signal to leave the node in place and keep descending into its children
             */
            public function enterNode(Node $node): null
            {
                $concrete = $node::class;
                if (!isset($this->hierarchyCache[$concrete])) {
                    /** @var list<class-string<Node>> $hierarchy class-string list for index keys; PHPStan cannot narrow the dynamic ::class expression on its own. */
                    $hierarchy = [$concrete];
                    /** @var array<string, class-string<Node>> $parents class_parents() returns string=>string but every entry here is a Node subclass under our traversal. */
                    $parents = class_parents($concrete) ?: [];
                    foreach ($parents as $parent) {
                        if ($parent === 'PhpParser\NodeAbstract') {
                            continue;
                        }
                        $hierarchy[] = $parent;
                    }
                    /** @var array<string, class-string<Node>> $implements class_implements() returns string=>string but every entry here is a Node interface under our traversal. */
                    $implements = class_implements($concrete) ?: [];
                    foreach ($implements as $interface) {
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

                // Null tells PhpParser to leave the node untouched and keep descending; we only observe, never rewrite.
                return null;
            }

            /**
             * Return nodes collected by concrete class, ancestor class, and
             * implemented interface keys.
             *
             * @return array<class-string<Node>, list<Node>> - accumulated index keyed by concrete, ancestor, and interface class-string; preorder within each key
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

        // Return via the accessor rather than re-reading self::$cache: WeakMap offset access is statically
        // nullable, whereas nodesByClass() is typed non-null, so this hands the freshly built index straight
        // back without reintroducing a null branch the caller would have to guard.
        return $visitor->nodesByClass();
    }

    /**
     * Index nodes that appear inside function-like bodies.
     *
     * @param Node $node - Function-like node confirmed to carry a body; its statements are walked once and memoised.
     *
     * @return list<Node> - body descendants in preorder; empty when the body holds no statements
     */
    private static function bodyIndex(Node $node): array
    {
        self::$bodyCache ??= new WeakMap();
        $cached          = self::$bodyCache[$node] ?? null;
        if ($cached !== null) {
            // A populated entry means this body was already walked, so reuse it instead of re-walking the statements.
            return $cached;
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            private array $nodes = [];

            /**
             * Add a body descendant to the source-order list.
             *
             * @param Node $node - Node currently being traversed.
             *
             * @return null - the PhpParser visitor signal to leave the node unchanged and keep descending into the body
             */
            public function enterNode(Node $node): null
            {
                $this->nodes[] = $node;

                // Null tells PhpParser to keep descending; every visited descendant is appended, none rewritten.
                return null;
            }

            /**
             * Return body descendants in source order.
             *
             * @return list<Node> - body descendants in the source order the preorder walk visited them
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

        // Return via the accessor rather than re-reading self::$bodyCache: WeakMap offset access is statically
        // nullable, whereas nodes() is typed non-null, so this hands the freshly built descendant list straight
        // back without reintroducing a null branch the caller would have to guard.
        return $visitor->nodes();
    }
}
