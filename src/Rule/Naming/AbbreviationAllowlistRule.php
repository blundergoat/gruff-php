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
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Requires short lowercase abbreviations to be declared in project config.
 *
 * M51 keeps this separate from ShortVariableRule: single-character variables are
 * a local clarity problem, while 2-5 character abbreviations need an explicit
 * project vocabulary through allowlists.acceptedAbbreviations.
 */
final readonly class AbbreviationAllowlistRule implements RuleInterface
{
    /** Stable identifier for the abbreviation allowlist rule. */
    public const ID = 'naming.abbreviation-allowlist';

    /** Names that should never be treated as project abbreviations. */
    private const DEFAULT_IGNORED_NAMES = ['this'];

    /**
     * Describe the abbreviation allowlist rule.
     *
     * @return RuleDefinition - id, pillar, tier, severity, and the default 2-3 character length band
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Abbreviation allowlist',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
            defaultOptions:  ['ignoredNames' => self::DEFAULT_IGNORED_NAMES, 'minLength' => 2, 'maxLength' => 3],
            description:     'Flags short lowercase identifiers that are not declared in acceptedAbbreviations.',
        );
    }

    /**
     * Find undeclared lowercase abbreviations on properties, parameters, and locals.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying accepted abbreviations.
     *
     * @return list<Finding> - one finding per undeclared abbreviation; empty when every short name is sanctioned
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $ignored    = $this->lowercaseList($ruleContext->settingsFor($definition)->stringListOption('ignoredNames'));
        $minLength  = $this->lengthOption($ruleContext, $definition, 'minLength', 2);
        $maxLength  = $this->lengthOption($ruleContext, $definition, 'maxLength', 3);
        $accepted   = $this->lowercaseList($ruleContext->config->acceptedAbbreviations());
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Property::class) as $property) {
            array_push(
                $findings,
                ...$this->propertyFindings(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    property:     $property,
                    ignored:      $ignored,
                    accepted:     $accepted,
                    minLength:    $minLength,
                    maxLength:    $maxLength,
                ),
            );
        }

        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            array_push(
                $findings,
                ...$this->scopeFindings(
                    definition:   $definition,
                    analysisUnit: $analysisUnit,
                    scope:        $scope,
                    ignored:      $ignored,
                    accepted:     $accepted,
                    minLength:    $minLength,
                    maxLength:    $maxLength,
                ),
            );
        }

        return $findings;
    }

    /**
     * Build abbreviation findings for properties declared in one property statement.
     *
     * @param RuleDefinition $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit   $analysisUnit - Parsed unit that owns the property declaration.
     * @param Property       $property - Property statement whose individual props are inspected.
     * @param list<string>   $ignored - Lowercased built-in names that are never reported.
     * @param list<string>   $accepted - Lowercased project abbreviations that suppress findings.
     * @param int            $minLength - Inclusive lower bound for abbreviation length.
     * @param int            $maxLength - Inclusive upper bound for abbreviation length.
     *
     * @return list<Finding> - property abbreviation findings in declaration order
     */
    private function propertyFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Property $property,
        array $ignored,
        array $accepted,
        int $minLength,
        int $maxLength,
    ): array {
        $findings = [];

        foreach ($property->props as $prop) {
            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $prop,
                identifier:   ['kind' => 'property', 'name' => $prop->name->toString(), 'symbol' => '$' . $prop->name->toString()],
                ignored:      $ignored,
                accepted:     $accepted,
                minLength:    $minLength,
                maxLength:    $maxLength,
            );
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Build abbreviation findings for parameters and local variables inside one callable scope.
     *
     * @param RuleDefinition    $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit      $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope $scope - Callable scope whose parameters and locals are inspected.
     * @param list<string>      $ignored - Lowercased built-in names that are never reported.
     * @param list<string>      $accepted - Lowercased project abbreviations that suppress findings.
     * @param int               $minLength - Inclusive lower bound for abbreviation length.
     * @param int               $maxLength - Inclusive upper bound for abbreviation length.
     *
     * @return list<Finding> - parameter and local abbreviation findings in source order
     */
    private function scopeFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $ignored,
        array $accepted,
        int $minLength,
        int $maxLength,
    ): array {
        return [
            ...$this->parameterFindings(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                scope:        $scope,
                ignored:      $ignored,
                accepted:     $accepted,
                minLength:    $minLength,
                maxLength:    $maxLength,
            ),
            ...$this->localVariableFindings(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                scope:        $scope,
                ignored:      $ignored,
                accepted:     $accepted,
                minLength:    $minLength,
                maxLength:    $maxLength,
            ),
        ];
    }

    /**
     * Build abbreviation findings for parameters inside one callable scope.
     *
     * @param RuleDefinition    $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit      $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope $scope - Callable scope whose parameters are inspected.
     * @param list<string>      $ignored - Lowercased built-in names that are never reported.
     * @param list<string>      $accepted - Lowercased project abbreviations that suppress findings.
     * @param int               $minLength - Inclusive lower bound for abbreviation length.
     * @param int               $maxLength - Inclusive upper bound for abbreviation length.
     *
     * @return list<Finding> - parameter abbreviation findings in declaration order
     */
    private function parameterFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $ignored,
        array $accepted,
        int $minLength,
        int $maxLength,
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
                node:         $param,
                identifier:   ['kind' => 'parameter', 'name' => $param->var->name, 'symbol' => $symbol],
                ignored:      $ignored,
                accepted:     $accepted,
                minLength:    $minLength,
                maxLength:    $maxLength,
            );
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Build abbreviation findings for local variables inside one callable scope.
     *
     * @param RuleDefinition    $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit      $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope $scope - Callable scope whose locals are inspected.
     * @param list<string>      $ignored - Lowercased built-in names that are never reported.
     * @param list<string>      $accepted - Lowercased project abbreviations that suppress findings.
     * @param int               $minLength - Inclusive lower bound for abbreviation length.
     * @param int               $maxLength - Inclusive upper bound for abbreviation length.
     *
     * @return list<Finding> - local variable abbreviation findings in discovery order
     */
    private function localVariableFindings(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        FunctionLikeScope $scope,
        array $ignored,
        array $accepted,
        int $minLength,
        int $maxLength,
    ): array {
        $findings     = [];
        $symbol       = $this->symbol($scope);
        $exemptLocals = $this->exemptLocalNames($scope);

        foreach ($scope->localVariables as $name => $variable) {
            if (isset($exemptLocals[$name])) {
                continue;
            }

            $finding = $this->finding(
                definition:   $definition,
                analysisUnit: $analysisUnit,
                node:         $variable,
                identifier:   ['kind' => 'variable', 'name' => $name, 'symbol' => $symbol],
                ignored:      $ignored,
                accepted:     $accepted,
                minLength:    $minLength,
                maxLength:    $maxLength,
            );
            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Build a finding for one identifier, or null when it passes every abbreviation gate.
     *
     * @param RuleDefinition                                         $definition - Rule metadata supplying id, severity, and tier for any finding
     *                                                                             raised.
     * @param AnalysisUnit                                           $analysisUnit - Parsed unit, used only for its display path on the emitted
     *                                                                             finding.
     * @param Node                                                   $node - Declaration node whose start line the finding points at.
     * @param array{kind: string, name: string, symbol: string|null} $identifier - Kind label, raw name, and owning
     *                                                                             symbol; symbol is null for top-level locals.
     * @param list<string>                                           $ignored - Lowercased built-in names that are never reported (e.g. this).
     * @param list<string>                                           $accepted - Lowercased project vocabulary from acceptedAbbreviations that
     *                                                                             suppresses a finding.
     * @param int                                                    $minLength - Inclusive lower bound; shorter names fall to ShortVariableRule
     *                                                                             instead.
     * @param int                                                    $maxLength - Inclusive upper bound; longer names are treated as real words, not
     *                                                                             abbreviations.
     *
     * @return Finding|null - a finding for an undeclared abbreviation; null when out of scope or sanctioned
     */
    private function finding(
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
        Node $node,
        array $identifier,
        array $ignored,
        array $accepted,
        int $minLength,
        int $maxLength,
    ): ?Finding {
        $kind      = $identifier['kind'];
        $name      = $identifier['name'];
        $symbol    = $identifier['symbol'];
        $lowerName = strtolower($name);
        // Only lowercase alphabetic identifiers can contain unapproved short abbreviations.
        if ($lowerName !== $name || !preg_match('/^[a-z]+$/', $name)) {
            // Mixed-case or non-alphabetic names are out of scope; null means "not an abbreviation", not "approved".
            return null;
        }

        $length = strlen($name);
        if ($length < $minLength || $length > $maxLength) {
            // Outside the configured abbreviation length band, so this name is somebody else's concern.
            return null;
        }

        if (in_array($lowerName, $ignored, true) || in_array($lowerName, $accepted, true)) {
            // The name is built-in (this) or declared project vocabulary, so it is sanctioned and produces no finding.
            return null;
        }

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s "$%s" is a short lowercase name not declared in acceptedAbbreviations.', ucfirst($kind), $name),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Rename the identifier or add the abbreviation to allowlists.acceptedAbbreviations with a documented meaning.',
            metadata:    ['identifierKind' => $kind, 'identifierName' => $name, 'minLength' => $minLength, 'maxLength' => $maxLength],
        );
    }

    /**
     * Read a positive integer rule option, falling back when configuration is not numeric.
     *
     * @param RuleContext    $ruleContext - Source of resolved settings for this rule.
     * @param RuleDefinition $definition - Rule whose settings bag the option is read from.
     * @param string         $name - Option key to read, such as minLength or maxLength.
     * @param int            $default - Value used when the option is absent or not an integer.
     *
     * @return int - the configured length clamped to at least 1, or the default when absent or non-integer
     */
    private function lengthOption(RuleContext $ruleContext, RuleDefinition $definition, string $name, int $default): int
    {
        $optionValue = $ruleContext->settingsFor($definition)->option($name);

        // Clamp to at least 1 so a misconfigured zero or negative length can never match every name.
        return is_int($optionValue) ? max(1, $optionValue) : $default;
    }

    /**
     * Collect local names exempt from abbreviation checks.
     *
     * @param FunctionLikeScope $scope - Scope whose body is scanned for loop and catch variable declarations.
     *
     * @return array<string, true> - set of loop and catch variable names exempt from findings, keyed for fast lookup
     */
    private function exemptLocalNames(FunctionLikeScope $scope): array
    {
        $names = [];

        foreach ($scope->bodyDescendants as $scopeNode) {
            if ($scopeNode instanceof For_ || $scopeNode instanceof Foreach_ || $scopeNode instanceof Catch_) {
                $this->collectExemptLocalNames($scopeNode, $names);
            }
        }

        return $names;
    }

    /**
     * Add loop and catch variables that are conventional enough to skip abbreviation findings.
     *
     * @param Node                $node - Loop or catch node to inspect; other node kinds contribute nothing.
     * @param array<string, true> $names - Accumulator mutated in place; matched variable names are added as keys.
     *
     * @return void
     */
    private function collectExemptLocalNames(Node $node, array &$names): void
    {
        if ($node instanceof For_) {
            $this->collectVariableNames($node->init, $names);
        }

        if ($node instanceof Foreach_) {
            $foreachVariables = [$node->valueVar];
            if ($node->keyVar instanceof Node) {
                $foreachVariables[] = $node->keyVar;
            }

            $this->collectVariableNames($foreachVariables, $names);
        }

        if ($node instanceof Catch_ && $node->var instanceof Variable && is_string($node->var->name)) {
            $names[$node->var->name] = true;
        }
    }

    /**
     * Collect local variable names from a node list.
     *
     * @param array<Node>         $nodes - Loop init or foreach key/value nodes to walk for variable references.
     * @param array<string, true> $names - Accumulator mutated in place; each discovered variable name is added as a key.
     *
     * @return void
     */
    private function collectVariableNames(array $nodes, array &$names): void
    {
        foreach ($nodes as $node) {
            $this->collectVariableName($node, $names);
        }
    }

    /**
     * Record a local variable name when the node is a variable reference.
     *
     * @param Node                $node - Node to test and then recurse into; only string-named variables are recorded.
     * @param array<string, true> $names - Accumulator mutated in place; each discovered variable name is added as a key.
     *
     * @return void
     */
    private function collectVariableName(Node $node, array &$names): void
    {
        if ($node instanceof Variable && is_string($node->name)) {
            $names[$node->name] = true;
        }

        foreach ($this->childNodes($node) as $child) {
            $this->collectVariableName($child, $names);
        }
    }

    /**
     * List direct child nodes that can be recursively traversed.
     *
     * @param Node $node - Parent node whose sub-node slots are flattened into child nodes.
     *
     * @return list<Node> - immediate child nodes only, sub-node arrays unwrapped; the caller drives deeper recursion
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
     * @param mixed      $subNode - A sub-node slot value: a Node, an array of them, or a scalar that is skipped.
     * @param list<Node> $children - Accumulator mutated in place; discovered Node instances are appended.
     *
     * @return void
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            // A single node is a leaf for this pass; stop so we do not descend past the immediate children.
            return;
        }

        if (!is_array($subNode)) {
            // Scalars and nulls hold no child nodes, so there is nothing to collect.
            return;
        }

        foreach ($subNode as $childSubNode) {
            $this->collectChildNodes($childSubNode, $children);
        }
    }

    /**
     * Resolve the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope whose node determines whether a real name or a synthetic label is used.
     *
     * @return string - the qualified name for a method or function, or a kind@line label for a closure or arrow fn
     */
    private function symbol(FunctionLikeScope $scope): string
    {
        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return CyclomaticComplexityRule::resolveSymbol($scope->node);
        }

        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }

    /**
     * Normalize string lists for case-insensitive comparisons.
     *
     * @param list<string> $values - Input strings to lowercase for case-insensitive allowlist comparison.
     *
     * @return list<string> - same entries lowercased, order preserved; empty input yields an empty list
     */
    private function lowercaseList(array $values): array
    {
        return array_map(static fn (string $optionValue): string => strtolower($optionValue), $values);
    }
}
