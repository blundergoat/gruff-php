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

        return $findings;
    }

    /**
     * Find short parameters in one function-like scope.
     *
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

        return $findings;
    }

    /**
     * Find short local variables in one function-like scope.
     *
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

        return $findings;
    }

    /**
     * Build a short-variable finding when the name violates the rule.
     *
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
        if (strlen($name) > 1 || $name === '_') {
            return null;
        }

        if (in_array($name, $ruleContext->config->acceptedAbbreviations(), true)) {
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
     * @return int|null Source column, or null when the name cannot be found on the node line.
     */
    private function columnForName(AnalysisUnit $analysisUnit, Node $node, string $name): ?int
    {
        $lines = preg_split('/\R/', $analysisUnit->source);

        if (!is_array($lines)) {
            return null;
        }

        $line = $lines[$node->getStartLine() - 1] ?? null;
        if ($line === null) {
            return null;
        }

        $position = strpos($line, '$' . $name);

        return $position === false ? null : $position + 1;
    }

    /**
     * Collect variable names introduced by foreach loops.
     *
     * @return array<string, true>
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

        return $vars;
    }

    /**
     * Collect variable names introduced by catch clauses.
     *
     * @return array<string, true>
     */
    private function collectCatchVars(FunctionLikeScope $scope): array
    {
        $vars = [];

        foreach ($this->nodesInScope($scope, static fn (Node $node): bool => $node instanceof Catch_) as $catch) {
            if ($catch instanceof Catch_ && $catch->var !== null && is_string($catch->var->name)) {
                $vars[$catch->var->name] = true;
            }
        }

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
     * @param callable(Node): bool $predicate
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

        return $matches;
    }

    /**
     * Filter descendant nodes with a predicate.
     *
     * @param list<Node>           $nodes
     * @param callable(Node): bool $predicate
     * @return list<Node>
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
     * @param callable(Node): bool $predicate
     * @param list<Node>           $matches
     * @return void
     */
    private function collectMatchingNodes(Node $node, callable $predicate, array &$matches): void
    {
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
     * @return list<Node>
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
     * @param list<Node> $children
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            return;
        }

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
     * @return string Named callable symbol or synthetic closure/arrow label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }
}
