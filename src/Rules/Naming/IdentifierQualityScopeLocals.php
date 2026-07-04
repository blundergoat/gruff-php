<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;

/**
 * Computes the candidate local-variable sets the identifier-quality rule judges, one function-like scope at
 * a time.
 *
 * Provides the frequently-used locals worth naming well, the variables a rule should exempt because a loop
 * or catch clause introduced them, and the generic loop variables whose long bodies still deserve a better
 * name. Every query is scoped so a nested closure's variables never leak into the enclosing function.
 */
final readonly class IdentifierQualityScopeLocals
{
    /**
     * Selects the local variables used often enough to be worth judging for name quality.
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

        // Weigh each local the scope declares.
        foreach ($scope->localVariables as $name => $variable) {
            // Skip a name that surrounding rule logic already exempted.
            if (isset($excludedNames[$name])) {
                continue;
            }

            // Keep only locals read often enough to be worth naming carefully.
            if (($counts[$name] ?? 0) >= $minScopeReferences) {
                $variables[$name] = $variable;
            }
        }

        return $variables;
    }

    /**
     * Lists the variables introduced by loop constructs.
     *
     * @param FunctionLikeScope $scope - Scope to scan for loop-induction and foreach key/value variables.
     *
     * @return array<string, true> - loop-introduced variables keyed by name
     */
    public static function loopVariables(FunctionLikeScope $scope): array
    {
        $variables = [];

        // Look at each for and foreach loop in the scope.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof For_ || $node instanceof Foreach_) as $loop) {
            // A for loop's init clause can declare induction variables.
            if ($loop instanceof For_) {
                self::collectVariablesByName($loop->init, $variables);
            }

            // A foreach binds its key and value variables.
            if ($loop instanceof Foreach_) {
                // Record each bound key or value name.
                foreach ([$loop->keyVar, $loop->valueVar] as $variable) {
                    // Only a plainly named variable can be tracked by name.
                    if ($variable instanceof Variable && is_string($variable->name)) {
                        $variables[$variable->name] = true;
                    }
                }
            }
        }

        return $variables;
    }

    /**
     * Lists the variables introduced by catch clauses.
     *
     * @param FunctionLikeScope $scope - Scope to scan for catch-clause exception variables.
     *
     * @return array<string, true> - catch-introduced variables keyed by name
     */
    public static function catchVariables(FunctionLikeScope $scope): array
    {
        $variables = [];

        // Look at each catch clause in the scope.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Catch_) as $catch) {
            // The predicate already guarantees this; the guard narrows the type for the analyzer.
            if (!$catch instanceof Catch_) {
                continue;
            }

            // Record the named exception variable when the clause has one.
            if ($catch->var instanceof Variable && is_string($catch->var->name)) {
                $variables[$catch->var->name] = true;
            }
        }

        return $variables;
    }

    /**
     * Selects the generic loop variables whose foreach body is long enough to deserve a better name.
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

        // Look at each foreach loop in the scope.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Foreach_) as $foreach) {
            // The predicate already guarantees this; the guard narrows the type for the analyzer.
            if (!$foreach instanceof Foreach_) {
                continue;
            }

            // A short body, or the canonical key/value idiom, needs no better name.
            if (count($foreach->stmts) < $loopBodyThreshold || self::isCanonicalMapLoop($foreach)) {
                continue;
            }

            // Weigh the loop's key and value variables.
            foreach ([$foreach->keyVar, $foreach->valueVar] as $variable) {
                // Skip anything without a plain string name.
                if (!$variable instanceof Variable || !is_string($variable->name)) {
                    continue;
                }

                $name = strtolower($variable->name);
                // Flag only the names configured as generic.
                if (in_array($name, $genericTokens, true)) {
                    $variables[$variable->name] ??= $variable;
                }
            }
        }

        return $variables;
    }

    /**
     * Reports whether a foreach loop uses the canonical `$key => $value` map-iteration idiom.
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
     * Counts the local-variable references inside one function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope whose variable occurrences are tallied.
     *
     * @return array<string, int> - local variable read counts keyed by variable name
     */
    private static function localVariableReferenceCounts(FunctionLikeScope $scope): array
    {
        $counts = [];

        // Tally every variable occurrence in the scope.
        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Variable) as $variable) {
            // Count only plainly named variables.
            if ($variable instanceof Variable && is_string($variable->name)) {
                $counts[$variable->name] = ($counts[$variable->name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Adds the variable names found under the given AST nodes to an output set.
     *
     * @param array<Node>         $nodes - AST nodes to scan for variable references.
     * @param array<string, true> $variables - Output set keyed by variable name.
     *
     * @return void
     */
    private static function collectVariablesByName(array $nodes, array &$variables): void
    {
        $walker = new IdentifierAstWalker();
        // Walk each supplied node in turn.
        foreach ($nodes as $node) {
            // Record each variable the scope-bounded walker finds beneath it.
            foreach ($walker->nodesMatching([$node], static fn(Node $candidate): bool => $candidate instanceof Variable) as $variable) {
                // Keep only plainly named variables.
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $variables[$variable->name] = true;
                }
            }
        }
    }

    /**
     * Filters the pre-walked body descendants down to the nodes matching a predicate.
     *
     * @param FunctionLikeScope    $scope - Scope whose pre-walked body descendants are filtered.
     * @param callable(Node): bool $predicate - Predicate that selects matching descendants.
     *
     * @return list<Node> - descendant nodes in source order
     */
    private static function nodesInScope(FunctionLikeScope $scope, callable $predicate): array
    {
        $matches = [];

        // Filter the pre-walked descendants down to the caller's matches.
        foreach ($scope->bodyDescendants as $node) {
            // Keep the nodes the predicate accepts.
            if ($predicate($node)) {
                $matches[] = $node;
            }
        }

        return $matches;
    }
}
