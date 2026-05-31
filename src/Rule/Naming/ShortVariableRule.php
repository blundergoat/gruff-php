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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory tier: a short name is a smell, never a build-breaker on its own.
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context carrying accepted abbreviations.
     * @return list<Finding> Findings for overly short variable names.
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

        // Hand back the short-name findings gathered across every function-like scope in the unit.
        return $findings;
    }

    /**
     * Find short parameters in one function-like scope.
     *
     * @param RuleDefinition    $definition   Rule identity and defaults stamped onto each emitted finding.
     * @param AnalysisUnit      $analysisUnit Parsed unit supplying the display path and source for locations.
     * @param RuleContext       $ruleContext  Carries the accepted-abbreviation allowlist that suppresses matches.
     * @param FunctionLikeScope $scope        The single function/closure whose parameter list is inspected here.
     * @return list<Finding> Findings for single-character parameters.
     */
    private function parameterFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        RuleContext $ruleContext,
        FunctionLikeScope $scope,
    ): array
    {
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

        // One finding per offending parameter; empty when every parameter name is long enough or allowed.
        return $findings;
    }

    /**
     * Find short local variables in one function-like scope.
     *
     * @param RuleDefinition    $definition   Rule identity and defaults stamped onto each emitted finding.
     * @param AnalysisUnit      $analysisUnit Parsed unit supplying the display path and source for locations.
     * @param RuleContext       $ruleContext  Carries the accepted-abbreviation allowlist that suppresses matches.
     * @param FunctionLikeScope $scope        Scope whose local variables, loop vars, and catch vars are read.
     * @return list<Finding> Findings for single-character local variables.
     */
    private function localVariableFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        RuleContext $ruleContext,
        FunctionLikeScope $scope,
    ): array
    {
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

        // One finding per offending local; loop counters and caught-exception names are filtered out above.
        return $findings;
    }

    /**
     * Build a short-variable finding when the name violates the rule.
     *
     * @param RuleDefinition $definition   Rule identity and defaults stamped onto the finding when one is built.
     * @param AnalysisUnit   $analysisUnit Parsed unit supplying the display path and source for the location.
     * @param RuleContext    $ruleContext  Carries the accepted-abbreviation allowlist that suppresses the finding.
     * @param Node           $node         Declaration node (param or variable) whose start line anchors the finding.
     * @param string         $kind         Human label for the message, one of parameter, property, or variable.
     * @param string         $name         The identifier without its leading dollar; the value being judged short.
     * @param string         $symbol       Enclosing callable label shown in the message so the reader can locate it.
     * @return Finding|null Finding when the identifier is a reportable single-character name.
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        RuleContext $ruleContext,
        Node $node,
        string $kind,
        string $name,
        string $symbol,
    ): ?Finding {
        // Multi-character names and the throwaway `$_` placeholder are by definition not too short.
        if (strlen($name) > 1 || $name === '_') {
            return null;
        }

        // A name the project explicitly allows (config) is intentional and must not be flagged.
        if (in_array($name, $ruleContext->config->acceptedAbbreviations(), true)) {
            return null;
        }

        // Past both guards the name is a genuine single-character violation worth surfacing.
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
     * @param AnalysisUnit $analysisUnit Parsed unit whose raw source is scanned for the name's offset.
     * @param Node         $node         Node whose start line selects which source line to search.
     * @param string       $name         Identifier without its dollar; matched as `$name` to find the column.
     * @return int|null Source column, or null when the name cannot be found on the node line.
     */
    private function columnForName(AnalysisUnit $analysisUnit, Node $node, string $name): ?int
    {
        $lines = preg_split('/\R/', $analysisUnit->source);

        // A malformed split means we cannot index lines, so give up on a precise column.
        if (!is_array($lines)) {
            return null;
        }

        $line = $lines[$node->getStartLine() - 1] ?? null;
        // Node line falls outside the source we have; no line means no column to report.
        if ($line === null) {
            return null;
        }

        $position = strpos($line, '$' . $name);

        // Convert the 0-indexed match to a 1-indexed column; null when the token is not on this line.
        return $position === false ? null : $position + 1;
    }

    /**
     * Collect variable names introduced by foreach loops.
     *
     * @param FunctionLikeScope $scope Scope whose body is searched for `for` loops; nested callables are excluded.
     * @return array<string, true> Names declared in loop init clauses, so allowlisted counters can be exempted.
     */
    private function collectLoopVars(FunctionLikeScope $scope): array
    {
        $vars = [];

        foreach ($this->nodesInScope($scope, static fn (Node $node): bool => $node instanceof For_) as $loop) {
            if (!$loop instanceof For_) {
                continue;
            }

            $this->collectVariablesByName($loop->init, $vars);
        }

        // Set membership is all callers need; the `true` values just mark presence of each loop variable.
        return $vars;
    }

    /**
     * Collect variable names introduced by catch clauses.
     *
     * @param FunctionLikeScope $scope Scope whose body is searched for `catch` clauses; nested callables excluded.
     * @return array<string, true> Caught-exception variable names, so an allowlisted `$e` can be exempted.
     */
    private function collectCatchVars(FunctionLikeScope $scope): array
    {
        $vars = [];

        foreach ($this->nodesInScope($scope, static fn (Node $node): bool => $node instanceof Catch_) as $catch) {
            if ($catch instanceof Catch_ && $catch->var !== null && is_string($catch->var->name)) {
                $vars[$catch->var->name] = true;
            }
        }

        // Presence set keyed by name; callers only test membership, never the stored value.
        return $vars;
    }

    /**
     * Collect variable references keyed by variable name.
     *
     * @param array<Node>        $nodes
     * @param array<string,true> $variables
     * @return void
     */
    private function collectVariablesByName(array $nodes, array &$variables): void
    {
        foreach ($nodes as $node) {
            foreach ($this->nodesMatching([$node], static fn (Node $candidate): bool => $candidate instanceof Variable) as $variable) {
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $variables[$variable->name] = true;
                }
            }
        }
    }

    /**
     * List descendant nodes in the current function-like scope.
     *
     * @param FunctionLikeScope    $scope     Scope whose precomputed body descendants are tested, one level deep.
     * @param callable(Node): bool $predicate Returns true to keep a node; called once per descendant.
     * @return list<Node>
     */
    private function nodesInScope(FunctionLikeScope $scope, callable $predicate): array
    {
        $matches = [];

        foreach ($scope->bodyDescendants as $node) {
            if ($predicate($node)) {
                $matches[] = $node;
            }
        }

        // Descendants are scanned in document order, so matches come back in source order.
        return $matches;
    }

    /**
     * Filter descendant nodes with a predicate.
     *
     * @param list<Node>           $nodes     Roots to walk; each is traversed recursively until a nested callable.
     * @param callable(Node): bool $predicate Returns true to keep a node; applied at every visited descendant.
     * @return list<Node>
     */
    private function nodesMatching(array $nodes, callable $predicate): array
    {
        $matches = [];

        foreach ($nodes as $node) {
            $this->collectMatchingNodes($node, $predicate, $matches);
        }

        // Flattened matches from every root, in the order the recursive walk encountered them.
        return $matches;
    }

    /**
     * Append descendant nodes that satisfy a predicate.
     *
     * @param Node                 $node      Subtree root to inspect and recurse into for the current call.
     * @param callable(Node): bool $predicate Returns true to keep a node; tested before descending.
     * @param list<Node>           $matches   Accumulator appended to in place, so recursion shares one result list.
     * @return void
     */
    private function collectMatchingNodes(Node $node, callable $predicate, array &$matches): void
    {
        // Stop at any nested callable: its variables belong to that inner scope, not this one.
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
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
     * @param Node $node Parent whose declared sub-node slots are flattened into child AST nodes.
     * @return list<Node>
     */
    private function childNodes(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $this->collectChildNodes($node->{$name}, $children);
        }

        // Only real Node children survive; scalars and nulls in sub-node slots are dropped by the collector.
        return $children;
    }

    /**
     * Append traversable child nodes to the current collection.
     *
     * @param mixed      $subNode  A raw sub-node slot: a Node, an array of them, or a scalar/null to ignore.
     * @param list<Node> $children Accumulator appended to in place so the recursion builds one flat child list.
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            // Base case: a lone Node is collected and has no further slots to flatten here.
            return;
        }

        // Scalars, strings, and nulls are leaf attributes, not children, so there is nothing to collect.
        if (!is_array($subNode)) {
            return;
        }

        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
    }

    /**
     * Resolve the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope Scope being labelled; its node decides named vs synthetic resolution.
     * @return string Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            // Named functions and methods carry a real identifier; reuse the shared resolver for it.
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        // Closures and arrow functions are anonymous, so synthesise a kind@line label callers can locate.
        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
