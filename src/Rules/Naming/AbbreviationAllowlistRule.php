<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Naming;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\FunctionComplexity;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Requires a short lowercase abbreviation to be declared in project config before it is accepted, so the
 * user builds an explicit, documented vocabulary instead of scattering ad-hoc short names.
 *
 * Kept deliberately separate from ShortVariableRule: a single-character variable is a local clarity problem,
 * while a 2-3 character abbreviation needs an explicit project vocabulary through
 * allowlists.acceptedAbbreviations. High confidence, advisory severity.
 */
final readonly class AbbreviationAllowlistRule implements RuleInterface
{
    /** Full configuration path for documented project abbreviations. */
    private const ACCEPTED_ABBREVIATIONS_CONFIGURATION_KEY = 'allowlists.acceptedAbbreviations';

    /** Stable identifier for the abbreviation allowlist rule. */
    public const ID = 'naming.abbreviation-allowlist';

    /** Names that should never be treated as project abbreviations. */
    private const DEFAULT_IGNORED_NAMES = ['this'];

    /**
     * Describes the abbreviation-allowlist rule for the registry and reports.
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
            falsePositiveShapes: [
                [
                    'shape' => 'A short healthcare or project abbreviation such as `dob` is intentional domain vocabulary.',
                    'mitigation' => 'Document intentional vocabulary in `allowlists.acceptedAbbreviations`; rename an unclear or disposable name such as `tmp` instead.',
                ],
            ],
        );
    }

    /**
     * Reports unapproved lowercase names in the configured abbreviation band on properties, parameters, and locals.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying accepted abbreviations.
     *
     * @return list<Finding> - One per matching property, parameter, or distinct local name per scope; may be empty.
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
     * Builds abbreviation findings for the properties declared in one property statement.
     *
     * @param RuleDefinition $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit   $analysisUnit - Parsed unit that owns the property declaration.
     * @param Property       $property - Property statement whose individual props are inspected.
     * @param list<string>   $ignored - Lowercased names excluded by the ignoredNames option.
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

        // One declaration can name several properties, so check each in turn.
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
     * Builds abbreviation findings for the parameters and local variables in one callable scope.
     *
     * @param RuleDefinition    $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit      $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope $scope - Callable scope whose parameters and locals are inspected.
     * @param list<string>      $ignored - Lowercased names excluded by the ignoredNames option.
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
     * Builds abbreviation findings for the parameters in one callable scope.
     *
     * @param RuleDefinition    $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit      $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope $scope - Callable scope whose parameters are inspected.
     * @param list<string>      $ignored - Lowercased names excluded by the ignoredNames option.
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
     * Builds abbreviation findings for the local variables in one callable scope.
     *
     * @param RuleDefinition    $definition - Rule metadata used to populate emitted findings.
     * @param AnalysisUnit      $analysisUnit - Parsed unit that owns the callable scope.
     * @param FunctionLikeScope $scope - Callable scope whose locals are inspected.
     * @param list<string>      $ignored - Lowercased names excluded by the ignoredNames option.
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
            // Variables used by loop headers and catch clauses are exempt from abbreviation findings.
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
     * Builds a finding for an unapproved name in the configured abbreviation band.
     *
     * @param RuleDefinition $definition - Metadata applied to an emitted finding.
     * @param AnalysisUnit $analysisUnit - Supplies the finding's display path.
     * @param Node $node - Supplies the finding's start line.
     * @param array{kind: string, name: string, symbol: string|null} $identifier - Kind label, raw name, and symbol copied to the finding.
     * @param list<string> $ignored - Lowercased names excluded by the ignoredNames option.
     * @param list<string> $accepted - Lowercased project abbreviations exempted by configuration.
     * @param int $minLength - Inclusive lower length bound; shorter names are skipped.
     * @param int $maxLength - Inclusive upper length bound; longer names are skipped.
     *
     * @return Finding|null - A finding for an unapproved lowercase alphabetic name in the length band; otherwise null.
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
        // This rule judges only lowercase alphabetic identifiers as short abbreviations.
        if ($lowerName !== $name || !preg_match('/^[a-z]+$/', $name)) {
            // Mixed-case or non-alphabetic names are out of scope; null means "not an abbreviation", not "approved".
            return null;
        }

        $length = strlen($name);
        if ($length < $minLength || $length > $maxLength) {

            return null;
        }

        if (in_array($lowerName, $ignored, true) || in_array($lowerName, $accepted, true)) {

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
            metadata:    array_merge(
                ['identifierKind' => $kind, 'identifierName' => $name, 'minLength' => $minLength, 'maxLength' => $maxLength],
                RemediationAction::Consider->metadata(self::ACCEPTED_ABBREVIATIONS_CONFIGURATION_KEY),
            ),
        );
    }

    /**
     * Reads an integer length option, clamping it to at least one or using the supplied default.
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


        return is_int($optionValue) ? max(1, $optionValue) : $default;
    }

    /**
     * Collects the local names exempt from abbreviation checks.
     *
     * @param FunctionLikeScope $scope - Scope whose body is scanned for loop and catch variable declarations.
     *
     * @return array<string, true> - Loop and catch variable names exempt from findings, keyed for membership checks.
     */
    private function exemptLocalNames(FunctionLikeScope $scope): array
    {
        $names = [];


        foreach ($scope->bodyDescendants as $scopeNode) {
            // Variables used by loop headers and catch clauses are exempt from abbreviation findings.
            if ($scopeNode instanceof For_ || $scopeNode instanceof Foreach_ || $scopeNode instanceof Catch_) {
                $this->collectExemptLocalNames($scopeNode, $names);
            }
        }

        return $names;
    }

    /**
     * Adds the loop and catch variables that are conventional enough to skip abbreviation findings.
     *
     * @param Node                $node - Loop or catch node to inspect; other node kinds contribute nothing.
     * @param array<string, true> $names - Accumulator mutated in place; matched variable names are added as keys.
     *
     * @return void - Updates $names with the collected exemption names.
     */
    private function collectExemptLocalNames(Node $node, array &$names): void
    {
        // A for loop's init clause declares induction variables.
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
     * Collects the local variable names from a node list.
     *
     * @param array<Node>         $nodes - Loop init or foreach key/value nodes to walk for variable references.
     * @param array<string, true> $names - Accumulator mutated in place; each discovered variable name is added as a key.
     *
     * @return void - Adds string-named variable references from the supplied subtrees to $names.
     */
    private function collectVariableNames(array $nodes, array &$names): void
    {

        foreach ($nodes as $node) {
            $this->collectVariableName($node, $names);
        }
    }

    /**
     * Collects string-named variable references from the node and its descendants.
     *
     * @param Node                $node - Node to test and then recurse into; only string-named variables are recorded.
     * @param array<string, true> $names - Accumulator mutated in place; each discovered variable name is added as a key.
     *
     * @return void - Adds discovered variable names to $names without removing existing entries.
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
     * Lists the direct child nodes that can be recursively traversed.
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
     * Appends the traversable child nodes found in one sub-node slot.
     *
     * @param mixed      $subNode - A sub-node slot value: a Node, an array of them, or a scalar that is skipped.
     * @param list<Node> $children - Accumulator mutated in place; discovered Node instances are appended.
     *
     * @return void - Appends Node instances to $children, flattening arrays without visiting node descendants.
     */
    private function collectChildNodes(mixed $subNode, array &$children): void
    {
        if ($subNode instanceof Node) {
            $children[] = $subNode;
            // A single node is a leaf for this pass; stop so we do not descend past the immediate children.
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
     * Resolves the human-readable symbol for a function-like scope.
     *
     * @param FunctionLikeScope $scope - Scope whose node determines whether a real name or a synthetic label is used.
     *
     * @return string - A method or function label from resolveSymbol(), otherwise a kind@line label.
     */
    private function symbol(FunctionLikeScope $scope): string
    {

        if ($scope->node instanceof ClassMethod || $scope->node instanceof Function_) {
            return FunctionComplexity::resolveSymbol($scope->node);
        }

        // Closures and arrow functions have no name, so fall back to a kind@line label.
        return sprintf('%s@%d', $scope->kind, $scope->node->getStartLine());
    }

    /**
     * Normalises a string list to lowercase for case-insensitive comparisons.
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
