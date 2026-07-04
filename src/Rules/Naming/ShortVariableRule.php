<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a single-character variable, parameter, or promoted property whose name says nothing about what it
 * holds, so the user replaces throwaway names like `$d` or `$q` with something a later reader can follow.
 *
 * Narrow conventions are allowed: loop counters (`$i`, `$j`, ...) inside a `for`, `$e` for a caught
 * exception, the `$_` throwaway, and any name on the project's accepted-abbreviation list. High confidence,
 * advisory severity.
 */
final readonly class ShortVariableRule implements RuleInterface
{
    /**
     * Stable identifier for the short variable rule.
     */
    public const ID = 'naming.short-variable';

    /**
     * One-character names accepted for local loop counters.
     */
    private const LOOP_COUNTER_ALLOWLIST = ['i', 'j', 'k', 'n', 'x', 'y', 'z'];

    /**
     * One-character names accepted for caught exceptions.
     */
    private const CATCH_ALLOWLIST = ['e'];

    /**
     * Describes the short-variable naming rule for the registry and reports.
     *
     * @return RuleDefinition - the rule's identity, pillar, tier, and default Advisory severity used to stamp findings
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Short variable name',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports single-character parameters and locals outside the accepted loop and catch conventions.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying accepted abbreviations.
     *
     * @return list<Finding> - one finding per offending name across every scope; empty when the unit has no short names
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        // Judge each function-like scope for its short parameter and local names.
        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            array_push(
                   $findings,
                ...$this->parameterFindings($definition, $analysisUnit, $ruleContext, $scope),
                ...$this->localVariableFindings($definition, $analysisUnit, $ruleContext, $scope),
            );
        }

        return $findings;
    }

    /**
     * Finds the short parameters in one function-like scope.
     *
     * @param RuleDefinition    $definition - Rule identity and defaults stamped onto each emitted finding.
     * @param AnalysisUnit      $analysisUnit - Parsed unit supplying the display path and source for locations.
     * @param RuleContext       $ruleContext - Carries the accepted-abbreviation allowlist that suppresses matches.
     * @param FunctionLikeScope $scope - The single function/closure whose parameter list is inspected here.
     *
     * @return list<Finding> - one finding per single-character parameter; empty when every name is long enough or allowed
     */
    private function parameterFindings(
        RuleDefinition    $definition,
        AnalysisUnit      $analysisUnit,
        RuleContext       $ruleContext,
        FunctionLikeScope $scope,
    ): array {
        $findings = [];
        $symbol   = $this->symbol($scope);

        // Weigh each declared parameter.
        foreach ($scope->node->params as $param) {
            // Skip anything without a plain string name.
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                ruleContext:  $ruleContext,
                node:         $param,
                kind:         $param->flags === 0 ? 'parameter' : 'property',
                name:         $param->var->name,
                symbol:       $symbol,
            );

            // Keep the parameter only when the name was actually too short.
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Finds the short local variables in one function-like scope.
     *
     * @param RuleDefinition    $definition - Rule identity and defaults stamped onto each emitted finding.
     * @param AnalysisUnit      $analysisUnit - Parsed unit supplying the display path and source for locations.
     * @param RuleContext       $ruleContext - Carries the accepted-abbreviation allowlist that suppresses matches.
     * @param FunctionLikeScope $scope - Scope whose local variables, loop vars, and catch vars are read.
     *
     * @return list<Finding> - one finding per short local; allowlisted loop counters and caught-exception names are skipped
     */
    private function localVariableFindings(
        RuleDefinition    $definition,
        AnalysisUnit      $analysisUnit,
        RuleContext       $ruleContext,
        FunctionLikeScope $scope,
    ): array {
        $findings  = [];
        $symbol    = $this->symbol($scope);
        $loopVars  = $this->collectLoopVars($scope);
        $catchVars = $this->collectCatchVars($scope);

        // Weigh each local the scope declares.
        foreach ($scope->localVariables as $name => $variable) {
            // An allowed loop counter such as `$i` is fine inside a loop.
            if (in_array($name, self::LOOP_COUNTER_ALLOWLIST, true) && isset($loopVars[$name])) {
                continue;
            }

            // An allowed `$e` is fine as a caught exception.
            if (in_array($name, self::CATCH_ALLOWLIST, true) && isset($catchVars[$name])) {
                continue;
            }

            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                ruleContext:  $ruleContext,
                node:         $variable,
                kind:         'variable',
                name:         $name,
                symbol:       $symbol,
            );

            // Keep the local only when the name was actually too short.
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Builds a short-variable finding when the name breaks the rule.
     *
     * @param RuleDefinition $definition - Rule identity and defaults stamped onto the finding when one is built.
     * @param AnalysisUnit   $analysisUnit - Parsed unit supplying the display path and source for the location.
     * @param RuleContext    $ruleContext - Carries the accepted-abbreviation allowlist that suppresses the finding.
     * @param Node           $node - Declaration node (param or variable) whose start line anchors the finding.
     * @param string         $kind - Human label for the message, one of parameter, property, or variable.
     * @param string         $name - The identifier without its leading dollar; the value being judged short.
     * @param string         $symbol - Enclosing callable label shown in the message so the reader can locate it.
     *
     * @return Finding|null - the finding for a reportable single-character name; null means the name is exempt, not an error
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit   $analysisUnit,
        RuleContext    $ruleContext,
        Node           $node,
        string         $kind,
        string         $name,
        string         $symbol,
    ): ?Finding {
        if (strlen($name) > 1 || $name === '_') {
            // Multi-character names and the throwaway `$_` placeholder are by definition not too short.
            return null;
        }

        if (in_array($name, $ruleContext->config->acceptedAbbreviations(), true)) {
            // A name the project explicitly allows (config) is intentional, so emit nothing for it.
            return null;
        }

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s $%s in %s is a single character.', ucfirst($kind), $name, $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            column:      $this->columnForName($analysisUnit, $node, $name),
            symbol:      $symbol,
            remediation: 'Use a descriptive name that communicates the variable\'s purpose.',
            metadata:    ['variable' => $name, 'identifierKind' => $kind],
        );
    }

    /**
     * Returns a best-effort 1-indexed column for a variable or parameter name.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose raw source is scanned for the name's offset.
     * @param Node         $node - Node whose start line selects which source line to search.
     * @param string       $name - Identifier without its dollar; matched as `$name` to find the column.
     *
     * @return int|null - 1-indexed source column of the name; null when it cannot be located on the node's line
     */
    private function columnForName(AnalysisUnit $analysisUnit, Node $node, string $name): ?int
    {
        $lines = preg_split('/\R/', $analysisUnit->source);

        if (!is_array($lines)) {
            // A malformed split means we cannot index lines, so give up on a precise column.
            return null;
        }

        $line = $lines[$node->getStartLine() - 1] ?? null;
        if ($line === null) {
            // Node line falls outside the source we have; no line means no column to report.
            return null;
        }

        $position = strpos($line, '$' . $name);

        return $position === false ? null : $position + 1;
    }

    /**
     * Collects the variable names introduced by `for` loops.
     *
     * @param FunctionLikeScope $scope - Scope whose body is searched for `for` loops; nested callables are excluded.
     *
     * @return array<string, true> - presence set of loop-init variable names; the `true` is a marker, callers test keys only
     */
    private function collectLoopVars(FunctionLikeScope $scope): array
    {
        $vars = [];

        // Look at each for loop in the scope.
        foreach ($this->nodesInScope($scope, static fn(Node $node): bool => $node instanceof For_) as $loop) {
            // The predicate already guarantees this; the guard narrows the type for the analyzer.
            if (!$loop instanceof For_) {
                continue;
            }

            $this->collectVariablesByName($loop->init, $vars);
        }

        return $vars;
    }

    /**
     * Collects the variable names introduced by catch clauses.
     *
     * @param FunctionLikeScope $scope - Scope whose body is searched for `catch` clauses; nested callables excluded.
     *
     * @return array<string, true> - presence set of caught-exception names; the `true` is a marker, callers test keys only
     */
    private function collectCatchVars(FunctionLikeScope $scope): array
    {
        $vars = [];

        // Look at each catch clause in the scope.
        foreach ($this->nodesInScope($scope, static fn(Node $node): bool => $node instanceof Catch_) as $catch) {
            // Record the named exception variable when the clause has one.
            if ($catch instanceof Catch_ && $catch->var !== null && is_string($catch->var->name)) {
                $vars[$catch->var->name] = true;
            }
        }

        return $vars;
    }

    /**
     * Collects the variable references under the given nodes, keyed by name.
     *
     * @param array<Node>        $nodes - AST nodes whose descendants are scanned for variable references.
     * @param array<string,true> $variables - Accumulator mutated in place; each discovered variable name is added as a key.
     *
     * @return void
     */
    private function collectVariablesByName(array $nodes, array &$variables): void
    {
        // Walk each supplied node.
        foreach ($nodes as $node) {
            // Record each variable the scope-bounded walk finds beneath it.
            foreach ($this->nodesMatching([$node], static fn(Node $candidate): bool => $candidate instanceof Variable) as $variable) {
                // Keep only plainly named variables.
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $variables[$variable->name] = true;
                }
            }
        }
    }

    /**
     * Filters the scope's pre-walked descendants down to the predicate matches.
     *
     * @param FunctionLikeScope    $scope - Scope whose precomputed body descendants are tested, one level deep.
     * @param callable(Node): bool $predicate - Returns true to keep a node; called once per descendant.
     *
     * @return list<Node> - matching descendants in source order; empty when no node in the scope satisfies the predicate
     */
    private function nodesInScope(FunctionLikeScope $scope, callable $predicate): array
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

    /**
     * Collects the predicate matches under the given roots.
     *
     * @param list<Node>           $nodes - Roots to walk; each is traversed recursively until a nested callable.
     * @param callable(Node): bool $predicate - Returns true to keep a node; applied at every visited descendant.
     *
     * @return list<Node> - matching nodes flattened across all roots in recursive-walk order; empty when none match
     */
    private function nodesMatching(array $nodes, callable $predicate): array
    {
        $matches = [];

        // Walk each root.
        foreach ($nodes as $node) {
            $this->collectMatchingNodes($node, $predicate, $matches);
        }

        return $matches;
    }

    /**
     * Appends the predicate-matching descendants, stopping at any nested callable.
     *
     * @param Node                 $node - Subtree root to inspect and recurse into for the current call.
     * @param callable(Node): bool $predicate - Returns true to keep a node; tested before descending.
     * @param list<Node>           $matches - Accumulator appended to in place, so recursion shares one result list.
     *
     * @return void
     */
    private function collectMatchingNodes(Node $node, callable $predicate, array &$matches): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            // Stop at any nested callable: its variables belong to that inner scope, not this one.
            return;
        }

        // Keep this node when it matches the predicate.
        if ($predicate($node)) {
            $matches[] = $node;
        }

        // Descend into the node's children, staying inside this scope.
        foreach ($this->childNodes($node) as $child) {
            $this->collectMatchingNodes($child, $predicate, $matches);
        }
    }

    /**
     * Lists the direct child nodes that can be recursively traversed.
     *
     * @param Node $node - Parent whose declared sub-node slots are flattened into child AST nodes.
     *
     * @return list<Node> - direct child AST nodes; scalar and null sub-node slots are dropped, so empty means no Node children
     */
    private function childNodes(Node $node): array
    {
        $children = [];

        // Flatten every sub-node slot the parser exposes for this node.
        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $children);
        }

        return $children;
    }

    /**
     * Appends the traversable child nodes found in one sub-node slot.
     *
     * @param mixed      $subNode - A raw sub-node slot: a Node, an array of them, or a scalar/null to ignore.
     * @param list<Node> $children - Accumulator appended to in place so the recursion builds one flat child list.
     *
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;

            // Base case: a lone Node is collected and has no further slots to flatten here.
            return;
        }

        if (!is_array($subNode)) {
            // Scalars, strings, and nulls are leaf attributes, not children, so there is nothing to collect.
            return;
        }

        // An array slot can hold several children, so recurse into each entry.
        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
    }

    /**
     * Resolves the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope being labelled; its node decides named vs synthetic resolution.
     *
     * @return string - the callable's real name for functions/methods, or a synthesised `kind@line` label for anonymous ones
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        // Named callables resolve to their declared symbol.
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        // Closures and arrow functions have no name, so fall back to a kind@line label.
        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
