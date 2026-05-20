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
     * @return RuleDefinition Rule metadata and defaults.
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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context carrying accepted abbreviations.
     * @return list<Finding> Findings for undeclared abbreviations.
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
            foreach ($property->props as $prop) {
                $finding = $this->finding(
                    definition: $definition,
                    analysisUnit:       $analysisUnit,
                    node:       $prop,
                    identifier: ['kind' => 'property', 'name' => $prop->name->toString(), 'symbol' => '$' . $prop->name->toString()],
                    ignored:    $ignored,
                    accepted:   $accepted,
                    minLength:  $minLength,
                    maxLength:  $maxLength,
                );
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        foreach ((new FunctionLikeScopeWalker())->scopes($analysisUnit->statements) as $scope) {
            $symbol = $this->symbol($scope);
            foreach ($scope->node->params as $param) {
                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $finding = $this->finding(
                    definition: $definition,
                    analysisUnit:       $analysisUnit,
                    node:       $param,
                    identifier: ['kind' => 'parameter', 'name' => $param->var->name, 'symbol' => $symbol],
                    ignored:    $ignored,
                    accepted:   $accepted,
                    minLength:  $minLength,
                    maxLength:  $maxLength,
                );
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }

            $exemptLocals = $this->exemptLocalNames($scope);
            foreach ($scope->localVariables as $name => $variable) {
                if (isset($exemptLocals[$name])) {
                    continue;
                }

                $finding = $this->finding(
                    definition: $definition,
                    analysisUnit:       $analysisUnit,
                    node:       $variable,
                    identifier: ['kind' => 'variable', 'name' => $name, 'symbol' => $symbol],
                    ignored:    $ignored,
                    accepted:   $accepted,
                    minLength:  $minLength,
                    maxLength:  $maxLength,
                );
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array{kind: string, name: string, symbol: string|null} $identifier
     * @param list<string>                                           $ignored
     * @param list<string>                                           $accepted
     * @return Finding|null Finding for an undeclared abbreviation.
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
            metadata:    ['identifierKind' => $kind, 'identifierName' => $name, 'minLength' => $minLength, 'maxLength' => $maxLength],
        );
    }

    /**
     * Read a positive integer rule option, falling back when configuration is not numeric.
     *
     * @return int Option value clamped to at least 1.
     */
    private function lengthOption(RuleContext $ruleContext, RuleDefinition $definition, string $name, int $default): int
    {
        $optionValue = $ruleContext->settingsFor($definition)->option($name);

        return is_int($optionValue) ? max(1, $optionValue) : $default;
    }

    /**
     * @return array<string, true>
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
     * @param array<string, true> $names
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
     * @param array<Node>          $nodes
     * @param array<string, true>  $names
     * @return void
     */
    private function collectVariableNames(array $nodes, array &$names): void
    {
        foreach ($nodes as $node) {
            $this->collectVariableName($node, $names);
        }
    }

    /**
     * @param array<string, true> $names
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

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function lowercaseList(array $values): array
    {
        return array_map(static fn (string $optionValue): string => strtolower($optionValue), $values);
    }
}
