<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;

/**
 * Computes local-variable candidate sets for IdentifierQualityRule.
 */
final readonly class IdentifierQualityScopeLocals
{
    /**
     * Select local variables whose reference count is high enough for identifier quality judging.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param FunctionLikeScope   $scope - Scope whose declared locals are the candidate set.
     * @param int                 $minScopeReferences - Read-count floor; locals used fewer times are too transient.
     * @param array<string, true> $excludedNames - Names already exempted by surrounding rule logic.
     *
     * @return array<string, Variable> - local variables keyed by name after exclusions and read-count filtering
     */
    public static function localVariableNames(FunctionLikeScope $scope, int $minScopeReferences, array $excludedNames): array
    {
        $counts    = self::localVariableReferenceCounts($scope);
        $variables = [];

        // User view: add each item that can appear in findings list.
        foreach ($scope->localVariables as $name => $variable) {
            // User view: choose the findings list branch for this case.
            if (isset($excludedNames[$name])) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            // User view: missing data becomes a safe findings list default.
            if (($counts[$name] ?? 0) >= $minScopeReferences) {
                $variables[$name] = $variable;
            }
        }

        return $variables;
    }

    /**
     * List variables introduced by loop constructs.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param FunctionLikeScope $scope - Scope to scan for loop-induction and foreach key/value variables.
     *
     * @return array<string, true> - loop-introduced variables keyed by name
     */
    public static function loopVariables(FunctionLikeScope $scope): array
    {
        $variables = [];

        // User view: add each item that can appear in findings list.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof For_ || $node instanceof Foreach_) as $loop) {
            // User view: choose the findings list branch for this case.
            if ($loop instanceof For_) {
                self::collectVariablesByName($loop->init, $variables);
            }

            // User view: choose the findings list branch for this case.
            if ($loop instanceof Foreach_) {
                // User view: add each item that can appear in findings list.
                foreach ([$loop->keyVar, $loop->valueVar] as $variable) {
                    // User view: choose the findings list branch for this case.
                    if ($variable instanceof Variable && is_string($variable->name)) {
                        $variables[$variable->name] = true;
                    }
                }
            }
        }

        return $variables;
    }

    /**
     * List variables introduced by catch clauses.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param FunctionLikeScope $scope - Scope to scan for catch-clause exception variables.
     *
     * @return array<string, true> - catch-introduced variables keyed by name
     */
    public static function catchVariables(FunctionLikeScope $scope): array
    {
        $variables = [];

        // User view: add each item that can appear in findings list.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Catch_) as $catch) {
            // User view: choose the findings list branch for this case.
            if (!$catch instanceof Catch_) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($catch->var instanceof Variable && is_string($catch->var->name)) {
                $variables[$catch->var->name] = true;
            }
        }

        return $variables;
    }

    /**
     * Select generic loop variables whose foreach body is long enough to demand better names.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param FunctionLikeScope $scope - Scope to scan for long-bodied foreach loops.
     * @param list<string>      $genericTokens - Lowercase loop variable names treated as generic.
     * @param int               $loopBodyThreshold - Statement count where a foreach body demands a meaningful name.
     *
     * @return array<string, Variable> - reportable loop variables keyed by name
     */
    public static function reportableLoopVariableNames(
        FunctionLikeScope $scope,
        array $genericTokens,
        int $loopBodyThreshold,
    ): array {
        $variables = [];

        // User view: add each item that can appear in findings list.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Foreach_) as $foreach) {
            // User view: choose the findings list branch for this case.
            if (!$foreach instanceof Foreach_) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (count($foreach->stmts) < $loopBodyThreshold || self::isCanonicalMapLoop($foreach)) {
                continue;
            }

            // User view: add each item that can appear in findings list.
            foreach ([$foreach->keyVar, $foreach->valueVar] as $variable) {
                // User view: choose the findings list branch for this case.
                if (!$variable instanceof Variable || !is_string($variable->name)) {
                    continue;
                }

                $name = strtolower($variable->name);
                // User view: choose the findings list branch for this case.
                if (in_array($name, $genericTokens, true)) {
                    $variables[$variable->name] ??= $variable;
                }
            }
        }

        return $variables;
    }

    /**
     * Check whether a foreach loop uses the canonical `$key => $value` map-iteration idiom.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Foreach_ $foreach - Loop to test against the key/value idiom.
     *
     * @return bool - true for the conventional key/value map iteration idiom
     */
    private static function isCanonicalMapLoop(Foreach_ $foreach): bool
    {
        // True only for the exact `$key => $value` shape, the one idiom where those generic names read as intentional.
        return $foreach->keyVar instanceof Variable
            && $foreach->valueVar instanceof Variable
            && $foreach->keyVar->name === 'key'
            && $foreach->valueVar->name === 'value';
    }

    /**
     * Count local variable references inside one function-like scope.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param FunctionLikeScope $scope - Scope whose variable occurrences are tallied.
     *
     * @return array<string, int> - local variable read counts keyed by variable name
     */
    private static function localVariableReferenceCounts(FunctionLikeScope $scope): array
    {
        $counts = [];

        // User view: add each item that can appear in findings list.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Variable) as $variable) {
            // User view: choose the findings list branch for this case.
            if ($variable instanceof Variable && is_string($variable->name)) {
                // User view: missing data becomes a safe findings list default.
                $counts[$variable->name] = ($counts[$variable->name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Add variable names found under the supplied AST nodes into an output set.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param array<Node>         $nodes - AST nodes to scan for variable references.
     * @param array<string, true> $variables - Output set keyed by variable name.
     *
     * @return void
     */
    private static function collectVariablesByName(array $nodes, array &$variables): void
    {
        $walker = new IdentifierAstWalker();
        // User view: add each item that can appear in findings list.
        foreach ($nodes as $node) {
            // User view: add each item that can appear in findings list.
            foreach ($walker->nodesMatching([$node], static fn(Node $candidate): bool => $candidate instanceof Variable) as $variable) {
                // User view: choose the findings list branch for this case.
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $variables[$variable->name] = true;
                }
            }
        }
    }

    /**
     * Filter pre-walked body descendants to nodes inside the current function-like scope.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param FunctionLikeScope    $scope - Scope whose pre-walked body descendants are filtered.
     * @param callable(Node): bool $predicate - Predicate that selects matching descendants.
     *
     * @return list<Node> - descendant nodes in source order
     */
    private static function nodesInScope(FunctionLikeScope $scope, callable $predicate): array
    {
        $matches = [];

        // User view: add each item that can appear in findings list.
        foreach ($scope->bodyDescendants as $node) {
            // User view: choose the findings list branch for this case.
            if ($predicate($node)) {
                $matches[] = $node;
            }
        }

        return $matches;
    }
}
