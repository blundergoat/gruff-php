<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Function_;

/**
 * Detects one-character variables outside narrow local conventions.
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
     * Describe the short variable naming rule.
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
     * Find short variable names outside accepted local conventions.
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
     * Find short parameters in one function-like scope.
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

        foreach ($scope->node->params as $param) {
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

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Find short local variables in one function-like scope.
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

        foreach ($scope->localVariables as $name => $variable) {
            if (in_array($name, self::LOOP_COUNTER_ALLOWLIST, true) && isset($loopVars[$name])) {
                continue;
            }

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

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Build a short-variable finding when the name violates the rule.
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
     * Return a best-effort 1-indexed column for a variable or parameter name.
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
     * Collect variable names introduced by foreach loops.
     *
     * @param FunctionLikeScope $scope - Scope whose body is searched for `for` loops; nested callables are excluded.
     *
     * @return array<string, true> - presence set of loop-init variable names; the `true` is a marker, callers test keys only
     */
    private function collectLoopVars(FunctionLikeScope $scope): array
    {
        $vars = [];

        foreach ($this->nodesInScope($scope, static fn(Node $node): bool => $node instanceof For_) as $loop) {
            if (!$loop instanceof For_) {
                continue;
            }

            $this->collectVariablesByName($loop->init, $vars);
        }

        return $vars;
    }

    /**
     * Collect variable names introduced by catch clauses.
     *
     * @param FunctionLikeScope $scope - Scope whose body is searched for `catch` clauses; nested callables excluded.
     *
     * @return array<string, true> - presence set of caught-exception names; the `true` is a marker, callers test keys only
     */
    private function collectCatchVars(FunctionLikeScope $scope): array
    {
        $vars = [];

        foreach ($this->nodesInScope($scope, static fn(Node $node): bool => $node instanceof Catch_) as $catch) {
            if ($catch instanceof Catch_ && $catch->var !== null && is_string($catch->var->name)) {
                $vars[$catch->var->name] = true;
            }
        }

        return $vars;
    }

    /**
     * Collect variable references keyed by variable name.
     *
     * @param array<Node>        $nodes - AST nodes whose descendants are scanned for variable references.
     * @param array<string,true> $variables - Accumulator mutated in place; each discovered variable name is added as a key.
     *
     * @return void
     */
    private function collectVariablesByName(array $nodes, array &$variables): void
    {
        foreach ($nodes as $node) {
            foreach ($this->nodesMatching([$node], static fn(Node $candidate): bool => $candidate instanceof Variable) as $variable) {
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $variables[$variable->name] = true;
                }
            }
        }
    }

    /**
     * List descendant nodes in the current function-like scope.
     *
     * @param FunctionLikeScope    $scope - Scope whose precomputed body descendants are tested, one level deep.
     * @param callable(Node): bool $predicate - Returns true to keep a node; called once per descendant.
     *
     * @return list<Node> - matching descendants in source order; empty when no node in the scope satisfies the predicate
     */
    private function nodesInScope(FunctionLikeScope $scope, callable $predicate): array
    {
        $matches = [];

        foreach ($scope->bodyDescendants as $node) {
            if ($predicate($node)) {
                $matches[] = $node;
            }
        }

        return $matches;
    }

    /**
     * Filter descendant nodes with a predicate.
     *
     * @param list<Node>           $nodes - Roots to walk; each is traversed recursively until a nested callable.
     * @param callable(Node): bool $predicate - Returns true to keep a node; applied at every visited descendant.
     *
     * @return list<Node> - matching nodes flattened across all roots in recursive-walk order; empty when none match
     */
    private function nodesMatching(array $nodes, callable $predicate): array
    {
        $matches = [];

        foreach ($nodes as $node) {
            $this->collectMatchingNodes($node, $predicate, $matches);
        }

        return $matches;
    }

    /**
     * Append descendant nodes that satisfy a predicate.
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

        if ($predicate($node)) {
            $matches[] = $node;
        }

        foreach ($this->childNodes($node) as $child) {
            $this->collectMatchingNodes($child, $predicate, $matches);
        }
    }

    /**
     * List direct child nodes that can be recursively traversed.
     *
     * @param Node $node - Parent whose declared sub-node slots are flattened into child AST nodes.
     *
     * @return list<Node> - direct child AST nodes; scalar and null sub-node slots are dropped, so empty means no Node children
     */
    private function childNodes(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $children);
        }

        return $children;
    }

    /**
     * Append traversable child nodes to the current collection.
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

        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
    }

    /**
     * Resolve the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope being labelled; its node decides named vs synthetic resolution.
     *
     * @return string - the callable's real name for functions/methods, or a synthesised `kind@line` label for anonymous ones
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
