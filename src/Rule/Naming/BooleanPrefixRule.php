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
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;

/**
 * Detects bool-returning callables without boolean-style names.
 */
final readonly class BooleanPrefixRule implements RuleInterface
{
    /**
     * Stable identifier for the boolean prefix rule.
     */
    public const ID = 'naming.boolean-prefix';

    /**
     * Prefixes that make boolean-returning callables read as predicates.
     */
    private const GOOD_PREFIXES = [
        'is', 'has', 'can', 'should', 'will', 'was', 'does', 'allows',
        'all', 'contains', 'extends', 'invokes', 'matches', 'refers', 'returns',
        'looks', 'supports', 'touches', 'uses',
    ];

    /**
     * State adjectives that are clear for typed boolean properties and parameters.
     */
    private const STATE_ADJECTIVES = [
        'active', 'enabled', 'disabled', 'applicable', 'generated', 'interactive',
        'emitted', 'visible', 'available', 'valid', 'strict', 'silent',
    ];

    /**
     * Describe the boolean method prefix rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Boolean method prefix',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                'allowedPrefixes' => self::GOOD_PREFIXES,
                'stateAdjectiveAllowlist' => self::STATE_ADJECTIVES,
            ],
        );
    }

    /**
     * Find bool-returning functions and methods without a boolean-style prefix.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for poorly named boolean callables.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition      = $this->definition();
        $settings        = $context->settingsFor($definition);
        $prefixes        = $settings->stringListOption('allowedPrefixes');
        $stateAdjectives = array_map(static fn (string $name): string => strtolower($name), $settings->stringListOption('stateAdjectiveAllowlist'));
        $finder          = new NodeFinder();
        $nodes           = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            $symbol = CyclomaticComplexityRule::resolveSymbol($node);
            array_push(
                $findings,
                ...$this->functionLikeFindings($definition, $unit, $node, $symbol, $prefixes),
                ...$this->parameterFindings($definition, $unit, $node, $symbol, $prefixes, $stateAdjectives),
            );
        }

        foreach ($finder->findInstanceOf($unit->statements, Property::class) as $property) {
            if (!$this->isBoolType($property->type)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $name = $prop->name->toString();
                if ($this->hasBooleanStyleName($name, $prefixes, $stateAdjectives)) {
                    continue;
                }

                $findings[] = $this->identifierFinding(
                    definition: $definition,
                    unit:       $unit,
                    node:       $prop,
                    kind:       'property',
                    name:       $name,
                    symbol:     '$' . $name,
                );
            }
        }

        return $findings;
    }

    /**
     * Find bool-returning functions and methods without a boolean-style prefix.
     *
     * @param list<string> $prefixes Configured predicate prefixes.
     * @return list<Finding> Findings for bool-returning callables.
     */
    private function functionLikeFindings(
        RuleDefinition $definition,
        AnalysisUnit $unit,
        ClassMethod|Function_ $node,
        string $symbol,
        array $prefixes,
    ): array
    {
        if (!$this->isBoolType($node->getReturnType())) {
            return [];
        }

        $name = $node->name->toString();
        if ($this->hasAllowedPrefix($name, $prefixes)) {
            return [];
        }

        return [
            new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s returns bool but does not use a boolean prefix (is, has, can, should, will).', $symbol),
                filePath:    $unit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $symbol,
                remediation: 'Rename to use a boolean prefix, e.g. isActive(), hasPermission().',
            ),
        ];
    }

    /**
     * Find typed bool parameters without a boolean-style prefix or state adjective.
     *
     * @param list<string> $prefixes        Configured predicate prefixes.
     * @param list<string> $stateAdjectives Configured state-adjective names.
     * @return list<Finding> Findings for bool parameters.
     */
    private function parameterFindings(
        RuleDefinition $definition,
        AnalysisUnit $unit,
        ClassMethod|Function_ $node,
        string $symbol,
        array $prefixes,
        array $stateAdjectives,
    ): array {
        $findings = [];

        foreach ($node->params as $param) {
            if (!$this->isBoolType($param->type) || !$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $name = $param->var->name;
            if ($this->hasBooleanStyleName($name, $prefixes, $stateAdjectives)) {
                continue;
            }

            $findings[] = $this->identifierFinding(
                definition: $definition,
                unit:       $unit,
                node:       $param,
                kind:       $param->flags === 0 ? 'parameter' : 'property',
                name:       $name,
                symbol:     $symbol,
            );
        }

        return $findings;
    }

    /**
     * Build a finding for a typed boolean property or parameter.
     *
     * @return Finding Finding for a boolean identifier without clear predicate naming.
     */
    private function identifierFinding(
        RuleDefinition $definition,
        AnalysisUnit $unit,
        Node $node,
        string $kind,
        string $name,
        ?string $symbol,
    ): Finding {
        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('%s "$%s" is typed bool but does not use a boolean prefix or approved state adjective.', ucfirst($kind), $name),
            filePath:    $unit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Rename to use a boolean prefix such as is/has/can, or configure stateAdjectiveAllowlist for clear state adjectives.',
            metadata:    [
                'identifierKind' => $kind,
                'identifierName' => $name,
            ],
        );
    }

    /**
     * Check whether a declaration type is bool or nullable bool.
     *
     * @return bool True when the type resolves to bool, including ?bool and bool|null.
     */
    private function isBoolType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            return $this->isBoolType($type->type);
        }

        if ($type instanceof Identifier) {
            return $type->toLowerString() === 'bool';
        }

        if ($type instanceof Name) {
            return strtolower($type->toString()) === 'bool';
        }

        if ($type instanceof UnionType) {
            $nonNull = array_values(array_filter(
                $type->types,
                static fn (Node $arm): bool => !($arm instanceof Identifier && $arm->toLowerString() === 'null'),
            ));

            return count($nonNull) === 1 && $this->isBoolType($nonNull[0]);
        }

        return false;
    }

    /**
     * Check whether a typed boolean identifier is already clear.
     *
     * @param list<string> $prefixes        Configured predicate prefixes.
     * @param list<string> $stateAdjectives Configured state-adjective names.
     * @return bool True when the identifier is allowed.
     */
    private function hasBooleanStyleName(string $name, array $prefixes, array $stateAdjectives): bool
    {
        return $this->hasAllowedPrefix($name, $prefixes) || in_array(strtolower($name), $stateAdjectives, true);
    }

    /**
     * Check whether the callable name starts with a configured predicate prefix.
     *
     * @param list<string> $prefixes Configured predicate prefixes.
     * @return bool True when the name has an allowed prefix followed by a word boundary.
     */
    private function hasAllowedPrefix(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            if (strlen($name) === strlen($prefix)) {
                return true;
            }

            $nextChar = $name[strlen($prefix)];
            if ($nextChar >= 'A' && $nextChar <= 'Z') {
                return true;
            }
        }

        return false;
    }
}
