<?php

declare(strict_types=1);

namespace GruffPhp\Rule;

use GruffPhp\Parser\AnalysisUnit;
use PhpParser\Node;
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
}
