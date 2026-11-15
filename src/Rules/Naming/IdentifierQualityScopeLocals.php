<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;

/**
 * Collects candidate locals and loop/catch name sets for identifier-quality checks within one function-like scope.
 * Local candidates use occurrence counts; loop sets include for-initializer references and direct foreach bindings.
 *
 * Generic foreach names become reportable at the body-statement threshold, except for the canonical key/value bindings.
 * Nested callable bodies are excluded from the supplied scope's descendants and from initializer traversal.
 */
final readonly class IdentifierQualityScopeLocals
{
    /**
     * Selects local variables meeting the occurrence threshold after name exclusions.
     *
     * @param FunctionLikeScope $scope - Scope whose local variable nodes are the candidate set.
     * @param int $minScopeReferences - Minimum body occurrences, including assignment targets.
     * @param array<string, true> $excludedNames - Names already exempted by surrounding rule logic.
     *
     * @return array<string, Variable> - local variables keyed by name after exclusions and occurrence-count filtering
     */
    public static function localVariableNames(FunctionLikeScope $scope, int $minScopeReferences, array $excludedNames): array
    {
        $counts    = self::localVariableReferenceCounts($scope);
        $variables = [];


        foreach ($scope->localVariables as $name => $variable) {

            if (isset($excludedNames[$name])) {
                continue;
            }


            if (($counts[$name] ?? 0) >= $minScopeReferences) {
                $variables[$name] = $variable;
            }
        }

        return $variables;
    }

    /**
     * Collects variable names referenced in for initializers or bound directly by foreach loops.
     *
     * @param FunctionLikeScope $scope - Scope to scan for initializer references and direct foreach key/value bindings.
     *
     * @return array<string, true> - loop-related names, including non-target initializer references, keyed by name
     */
    public static function loopVariables(FunctionLikeScope $scope): array
    {
        $variables = [];


        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof For_ || $node instanceof Foreach_) as $loop) {

            if ($loop instanceof For_) {
                self::collectVariablesByName($loop->init, $variables);
            }


            if ($loop instanceof Foreach_) {

                foreach ([$loop->keyVar, $loop->valueVar] as $variable) {

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


        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Catch_) as $catch) {
            // The predicate already guarantees this; the guard narrows the type for the analyzer.
            if (!$catch instanceof Catch_) {
                continue;
            }


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


        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Foreach_) as $foreach) {
            // Static analysis does not infer the predicate's narrowed type; remove this guard when it does.
            if (!$foreach instanceof Foreach_) {
                continue;
            }

            // Short bodies and the canonical key/value shape are exempt from generic-name findings.
            if (count($foreach->stmts) < $loopBodyThreshold || self::isCanonicalMapLoop($foreach)) {
                continue;
            }


            foreach ([$foreach->keyVar, $foreach->valueVar] as $variable) {

                if (!$variable instanceof Variable || !is_string($variable->name)) {
                    continue;
                }

                $name = strtolower($variable->name);

                if (in_array($name, $genericTokens, true)) {
                    $variables[$variable->name] ??= $variable;
                }
            }
        }

        return $variables;
    }

    /**
     * Reports whether a foreach loop uses the canonical $key => $value map-iteration idiom.
     *
     * @param Foreach_ $foreach - Loop to test against the key/value idiom.
     *
     * @return bool - true for the conventional key/value map iteration idiom
     */
    private static function isCanonicalMapLoop(Foreach_ $foreach): bool
    {
        // The rule exempts only the exact $key => $value shape from generic-name findings.
        return $foreach->keyVar instanceof Variable
            && $foreach->valueVar instanceof Variable
            && $foreach->keyVar->name === 'key'
            && $foreach->valueVar->name === 'value';
    }

    /**
     * Counts named variable occurrences in the scope body, including assignment targets.
     *
     * @param FunctionLikeScope $scope - Scope whose variable occurrences are tallied.
     *
     * @return array<string, int> - occurrence counts keyed by variable name, including parameters and locals
     */
    private static function localVariableReferenceCounts(FunctionLikeScope $scope): array
    {
        $counts = [];


        foreach (self::nodesInScope($scope, static fn(Node $node): bool => $node instanceof Variable) as $variable) {

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
     * @return void - Adds plainly named variables to the supplied name set.
     */
    private static function collectVariablesByName(array $nodes, array &$variables): void
    {
        $walker = new IdentifierAstWalker();

        foreach ($nodes as $node) {

            foreach ($walker->nodesMatching([$node], static fn(Node $candidate): bool => $candidate instanceof Variable) as $variable) {

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


        foreach ($scope->bodyDescendants as $node) {

            if ($predicate($node)) {
                $matches[] = $node;
            }
        }

        return $matches;
    }
}
