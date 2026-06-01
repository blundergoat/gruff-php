<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Design;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\ProjectRuleAccumulator;
use GruffPhp\Rule\ProjectRuleInterface;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;

/**
 * Detects project interfaces that have only one concrete implementation.
 *
 * Implements ProjectRuleAccumulator so RuleRegistry can extract project-level
 * type data from each unit incrementally and release each unit's AST as the
 * per-unit phase advances. This keeps peak memory close to one unit's worth
 * rather than the whole project, which matters on large codebases.
 */
final class SingleImplementorInterfaceRule implements ProjectRuleInterface, ProjectRuleAccumulator
{
    /**
     * @var array{
     *     interfaces: array<string, array{fqn: string, displayPath: string, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * }
     */
    private array $accumulated = [
        'interfaces'         => [],
        'implementations'    => [],
        'typeReferences'     => [],
        'extendedInterfaces' => [],
    ];

    /**
     * @var list<string>|null Excluded display path prefixes captured at startProject().
     */
    private ?array $accumulationExcludedPaths = null;

    /**
     * Stable identifier for the single-implementor interface rule.
     */
    public const ID = 'design.single-implementor-interface';

    /**
     * Namespace prefixes treated as external contracts.
     */
    private const DEFAULT_EXTERNAL_PREFIXES = [
        'Psr\\',
        'Symfony\\',
        'Doctrine\\',
        'Twig\\',
        'League\\',
        'PhpParser\\',
        'PHPUnit\\',
        'Stringable',
        'Throwable',
        'Countable',
        'IteratorAggregate',
        'Iterator',
        'JsonSerializable',
        'ArrayAccess',
        'Traversable',
    ];

    /**
     * Attribute prefixes that mark framework extension points.
     */
    private const DEFAULT_FRAMEWORK_ATTRIBUTE_PREFIXES = [
        'Symfony\\',
        'Doctrine\\',
        'Attribute\\AsCommand',
        'Attribute\\AsController',
        'Attribute\\AutoconfigureTag',
        'Attribute\\AsEventSubscriber',
    ];

    /**
     * Describe the single-implementor interface rule.
     *
     * @return RuleDefinition - id, pillar, default Advisory severity, and the overridable prefix/attribute/path allowlists
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Single-implementor interface',
            pillar:          Pillar::Design,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            defaultOptions:  [
                                 'externalNamespacePrefixes'   => self::DEFAULT_EXTERNAL_PREFIXES,
                                 'frameworkAttributePrefixes'  => self::DEFAULT_FRAMEWORK_ATTRIBUTE_PREFIXES,
                                 'treatMockUsageAsImplementor' => false,
                                 'additionalExcludedPaths'     => [],
                             ],
        );
    }

    /**
     * Analyse all project units for interfaces with exactly one concrete implementor.
     *
     * @param list<AnalysisUnit> $units - Parsed project units to analyse together.
     * @param RuleContext        $ruleContext - Rule context carrying config and settings.
     *
     * @return list<Finding> - one per interface left single-implementor and externally unused; empty when none qualify
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array
    {
        $this->startProject($ruleContext);
        foreach ($units as $unit) {
            $this->accumulate($unit, $ruleContext);
        }

        return $this->finishProject($ruleContext);
    }

    /**
     * Reset accumulated state at the start of a streaming project pass.
     *
     * @param RuleContext $ruleContext - Rule context carrying config and settings.
     *
     * @return void
     */
    public function startProject(RuleContext $ruleContext): void
    {
        $settings                        = $ruleContext->settingsFor($this->definition());
        $this->accumulationExcludedPaths = $settings->stringListOption('additionalExcludedPaths');
        $this->accumulated               = [
            'interfaces'         => [],
            'implementations'    => [],
            'typeReferences'     => [],
            'extendedInterfaces' => [],
        ];
    }

    /**
     * Extract interfaces, implementations, and type references from one unit.
     *
     * Stores the per-unit summary on `$this->accumulated`; the AST may be
     * released by the orchestrator as soon as this call returns.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to index.
     * @param RuleContext  $ruleContext - Rule context carrying config and settings.
     *
     * @return void
     */
    public function accumulate(AnalysisUnit $analysisUnit, RuleContext $ruleContext): void
    {
        if ($this->isExcludedUnit($analysisUnit)) {
            return;
        }

        $this->collectUnitTypes($analysisUnit, $this->accumulated);
    }

    /**
     * Produce single-implementor findings from the accumulated state and reset.
     *
     * @param RuleContext $ruleContext - Rule context carrying config and settings.
     *
     * @return list<Finding> - one finding per surviving single-implementor interface; empty when none qualify
     */
    public function finishProject(RuleContext $ruleContext): array
    {
        $settings                   = $ruleContext->settingsFor($this->definition());
        $externalPrefixes           = array_map(static fn(string $prefix): string => strtolower($prefix), $settings->stringListOption('externalNamespacePrefixes'));
        $frameworkAttributePrefixes = array_map(static fn(string $prefix): string => strtolower($prefix), $settings->stringListOption('frameworkAttributePrefixes'));

        $findings = $this->buildFindings(
            interfaces:                 $this->accumulated['interfaces'],
            implementations:            $this->accumulated['implementations'],
            typeReferences:             $this->accumulated['typeReferences'],
            extendedInterfaces:         $this->accumulated['extendedInterfaces'],
            externalPrefixes:           $externalPrefixes,
            frameworkAttributePrefixes: $frameworkAttributePrefixes,
        );

        // Drop accumulated references so the previous project pass does not
        // pin objects after we are done.
        $this->accumulated               = [
            'interfaces'         => [],
            'implementations'    => [],
            'typeReferences'     => [],
            'extendedInterfaces' => [],
        ];
        $this->accumulationExcludedPaths = null;

        return $findings;
    }

    /**
     * Apply path exclusions that previously gated filterEligibleUnits.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose display path is matched against vendor and configured exclusions.
     *
     * @return bool - true when the unit is vendor or a configured-out path and contributes no facts; false to index it
     */
    private function isExcludedUnit(AnalysisUnit $analysisUnit): bool
    {
        $display = $analysisUnit->file->displayPath;

        if (str_starts_with($display, 'vendor/')) {
            return true;
        }

        foreach ($this->accumulationExcludedPaths ?? [] as $excluded) {
            if (str_starts_with($display, $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index one unit's interfaces, implementations, and type references into the project-level facts.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to scan; its AST may be released once this returns.
     * @param array{
     *     interfaces: array<string, array{fqn: string, displayPath: string, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * }                   $projectTypes - Accumulator mutated in place with the facts found in this unit.
     *
     * @return void
     */
    private function collectUnitTypes(AnalysisUnit $analysisUnit, array &$projectTypes): void
    {
        foreach (NodeIndex::nodesOf($analysisUnit, Interface_::class) as $interface) {
            $this->recordInterface($interface, $analysisUnit, $projectTypes);
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Class_::class) as $class) {
            if ($class->name === null) {
                continue;
            }
            $this->recordClass($class, $analysisUnit, $projectTypes);
        }

        foreach (NodeIndex::nodesOfAny($analysisUnit, [Param::class, ClassMethod::class, Property::class]) as $node) {
            $class = $this->enclosingClass($node);
            if ($class === null) {
                continue;
            }

            if ($node instanceof Param) {
                $type = $node->type;
            } elseif ($node instanceof ClassMethod) {
                $type = $node->returnType;
            } else {
                /** @var Property $node Filter restricted node classes. */
                $type = $node->type;
            }

            $this->recordClassTypeReference(
                class:        $class,
                type:         $type,
                line:         $node->getStartLine(),
                analysisUnit: $analysisUnit,
                projectTypes: $projectTypes,
            );
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Instanceof_::class) as $instanceof) {
            if (!$instanceof->class instanceof Name) {
                continue;
            }

            $class = $this->enclosingClass($instanceof);
            if ($class === null) {
                continue;
            }

            $this->recordClassTypeReference(
                class:        $class,
                type:         $instanceof->class,
                line:         $instanceof->getStartLine(),
                analysisUnit: $analysisUnit,
                projectTypes: $projectTypes,
            );
        }
    }

    /**
     * Walk parent links to find the enclosing named class for a node.
     *
     * @param Node $node - Node whose `parent` attribute chain is walked outward; relies on the parent visitor
     *                   having run during parsing.
     *
     * @return Class_|null - nearest named class ancestor; null when none exists (e.g. only anonymous-class scopes above)
     */
    private function enclosingClass(Node $node): ?Class_
    {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current instanceof Class_ && $current->name !== null) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }

        return null;
    }

    /**
     * Record one interface declaration plus the parent contracts it extends into the project facts.
     *
     * @param Interface_   $interface - Interface declaration node to register as a project contract.
     * @param AnalysisUnit $analysisUnit - Owning unit, used for the display path and start line stored on the entry.
     * @param array{
     *     interfaces: array<string, array{fqn: string, displayPath: string, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * }                   $projectTypes - Accumulator gaining the interface entry and any parents marked as extended.
     *
     * @return void
     */
    private function recordInterface(Interface_ $interface, AnalysisUnit $analysisUnit, array &$projectTypes): void
    {
        if ($interface->name === null) {
            return;
        }

        $fqn = $this->declarationFqn($interface);
        if ($fqn === null) {
            return;
        }

        $extends                          = $this->resolveNameList($interface->extends);
        $projectTypes['interfaces'][$fqn] = [
            'fqn'         => $fqn,
            'displayPath' => $analysisUnit->file->displayPath,
            'line'        => $interface->getStartLine(),
            'extends'     => $extends,
            'attributes'  => $this->resolveAttributes(array_values($interface->attrGroups)),
        ];

        foreach ($extends as $parentFqn) {
            $projectTypes['extendedInterfaces'][$parentFqn] = true;
        }
    }

    /**
     * Record each interface a concrete class implements so implementor counts can be tallied later.
     *
     * @param Class_       $class - Class declaration node whose `implements` list supplies the edges.
     * @param AnalysisUnit $analysisUnit - Owning unit, used for the display path and start line stored per edge.
     * @param array{
     *     interfaces: array<string, array{fqn: string, displayPath: string, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * }                   $projectTypes - Accumulator gaining one implementation edge per implemented interface.
     *
     * @return void
     */
    private function recordClass(Class_ $class, AnalysisUnit $analysisUnit, array &$projectTypes): void
    {
        if ($class->name === null) {
            return;
        }

        $classFqn = $this->declarationFqn($class);
        if ($classFqn === null) {
            return;
        }

        foreach ($this->resolveNameList($class->implements) as $implementedFqn) {
            $projectTypes['implementations'][$implementedFqn][] = [
                'classFqn'    => $classFqn,
                'displayPath' => $analysisUnit->file->displayPath,
                'line'        => $class->getStartLine(),
            ];
        }
    }

    /**
     * Build findings from the collected project-level rule facts.
     *
     * @param array<string, array{fqn: string, displayPath: string, line: int, extends: list<string>, attributes: list<string>}> $interfaces - Interface facts keyed by FQN.
     * @param array<string, list<array{classFqn: string, displayPath: string, line: int}>>                                       $implementations - Implementation edges keyed by interface FQN.
     * @param array<string, list<array{classFqn: string, displayPath: string, line: int}>>                                       $typeReferences - Type-reference edges keyed by referenced interface FQN.
     * @param array<string, true>                                                                                                $extendedInterfaces - Interfaces that are parents of another interface.
     * @param list<string>                                                                                                       $externalPrefixes - Lower-cased namespace prefixes treated as external contracts.
     * @param list<string>                                                                                                       $frameworkAttributePrefixes - Lower-cased attribute prefixes treated as framework hooks.
     *
     * @return list<Finding> - one finding per interface left single-implementor and externally unused after every exemption filter; empty when none
     *                       remain
     */
    private function buildFindings(
        array $interfaces,
        array $implementations,
        array $typeReferences,
        array $extendedInterfaces,
        array $externalPrefixes,
        array $frameworkAttributePrefixes,
    ): array {
        $findings   = [];
        $definition = $this->definition();

        foreach ($interfaces as $fqn => $interface) {
            if ($this->hasExternalParent($interface['extends'], $externalPrefixes)) {
                continue;
            }

            if ($this->hasFrameworkAttribute($interface['attributes'], $frameworkAttributePrefixes)) {
                continue;
            }

            if (isset($extendedInterfaces[$fqn])) {
                continue;
            }

            $implementors     = $implementations[$fqn] ?? [];
            $implementorCount = count($implementors);

            if ($implementorCount !== 1) {
                continue;
            }

            $implementor    = $implementors[0];
            $implementorFqn = $implementor['classFqn'];

            $externalUsages = 0;
            foreach ($typeReferences[$fqn] ?? [] as $reference) {
                if ($reference['classFqn'] !== $implementorFqn) {
                    $externalUsages++;
                }
            }

            if ($externalUsages !== 0) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf(
                                 'Interface %s has one implementor (%s) and no external type-hint usage; consider removing the interface and depending on the class directly.',
                                 $fqn,
                                 $implementorFqn,
                             ),
                filePath:    $interface['displayPath'],
                line:        $interface['line'],
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $fqn,
                remediation: 'Either delete the interface and depend on the concrete class, add a second implementor / external type-hint usage that justifies the abstraction, or configure additionalExcludedPaths when the interface comes from copied vendor/framework code.',
                metadata:    [
                                 'interfaceFqn'       => $fqn,
                                 'implementorCount'   => $implementorCount,
                                 'implementorFqn'     => $implementorFqn,
                                 'externalUsageCount' => $externalUsages,
                                 'decision'           => sprintf('flagged: %d implementor, %d external usages', $implementorCount, $externalUsages),
                             ],
            );
        }

        return $findings;
    }


    /**
     * Resolve the fully qualified declaration name for a class or interface.
     *
     * @param Class_|Interface_ $node - Declaration whose namespaced name is preferred, falling back to the bare name.
     *
     * @return string|null - resolved FQN preferred over the short name; null only for anonymous declarations with no name
     */
    private function declarationFqn(Class_|Interface_ $node): ?string
    {
        $resolved = $node->namespacedName ?? null;

        if ($resolved instanceof Name) {
            return $resolved->toString();
        }

        return $node->name?->toString();
    }

    /**
     * Resolve name list for the design rule.
     *
     * @param array<Name>|null $names - Name nodes (e.g. an extends/implements list), or null when the clause is absent.
     *
     * @return list<string> - fully qualified name strings in source order; empty when the clause was null or had no names
     */
    private function resolveNameList(?array $names): array
    {
        if ($names === null) {
            return [];
        }

        $resolved = [];
        foreach ($names as $name) {
            $resolved[] = $this->resolveName($name);
        }

        return $resolved;
    }

    /**
     * Resolve a name node to its fully qualified form when name resolution has run.
     *
     * @param Name $name - Name node to resolve; its `resolvedName` attribute is used when the resolver populated it.
     *
     * @return string - resolved FQN when the resolver ran, else the name as written; always without a leading slash so keys match across units
     */
    private function resolveName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }

        return ltrim($name->toString(), '\\');
    }

    /**
     * Resolve attributes for the design rule.
     *
     * @param list<AttributeGroup> $attrGroups - Attribute groups declared on the interface being indexed.
     *
     * @return list<string> - flattened fully qualified attribute names across every group; empty when no attributes are present
     */
    private function resolveAttributes(array $attrGroups): array
    {
        $attributes = [];
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributes[] = $this->resolveName($attribute->name);
            }
        }

        return $attributes;
    }

    /**
     * Record that one class type-hints a name, so an interface used only by its lone implementor stays flaggable.
     *
     * @param Class_                           $class - Referencing class; its FQN is the attributed source of the
     *                                                       reference.
     * @param Identifier|Name|ComplexType|null $type - Declared type to mine for names; scalars and null add nothing.
     * @param int                              $line - 1-based source line where the type appears; recorded on
     *                                                       the stored reference entry but not consumed — findings count usages by class FQN and
     *                                                       report the interface's own declaration line, so this is retained for entry
     *                                                       completeness/diagnostics, not as a reported location.
     * @param AnalysisUnit                     $analysisUnit - Owning unit, used for the display path stored per reference.
     * @param array{
     *     interfaces: array<string, array{fqn: string, displayPath: string, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, displayPath: string, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * }                                       $projectTypes - Accumulator gaining one type-reference edge per name found in the type.
     *
     * @return void
     */
    private function recordClassTypeReference(
        Class_                           $class,
        Identifier|Name|ComplexType|null $type,
        int                              $line,
        AnalysisUnit                     $analysisUnit,
        array                            &$projectTypes,
    ): void {
        $classFqn = $this->declarationFqn($class);
        if ($classFqn === null) {
            return;
        }

        foreach ($this->namesFromType($type) as $name) {
            $projectTypes['typeReferences'][$this->resolveName($name)][] = [
                'classFqn'    => $classFqn,
                'displayPath' => $analysisUnit->file->displayPath,
                'line'        => $line,
            ];
        }
    }

    /**
     * Extract referenced names from a type declaration.
     *
     * @param Identifier|Name|ComplexType|null $type - Declared type to walk; nullable and union/intersection types
     *                                               recurse into their members.
     *
     * @return list<Name> - class-like name nodes referenced by the type, flattened across union/intersection members; empty for null, scalar, or
     *                    unhandled types
     */
    private function namesFromType(Identifier|Name|ComplexType|null $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof Identifier) {
            return [];
        }

        if ($type instanceof Name) {
            return [$type];
        }

        if ($type instanceof NullableType) {
            return $this->namesFromType($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];
            foreach ($type->types as $member) {
                foreach ($this->namesFromType($member) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * Decide whether an interface inherits from an external contract we should not flag.
     *
     * @param list<string> $extends - Fully qualified parent interface names of the interface under test.
     * @param list<string> $externalPrefixes - Lower-cased namespace prefixes that mark third-party contracts.
     *
     * @return bool - true when a parent interface matches an external prefix, marking the interface an extension point to keep
     */
    private function hasExternalParent(array $extends, array $externalPrefixes): bool
    {
        foreach ($extends as $parent) {
            $parentLower = strtolower($parent);
            foreach ($externalPrefixes as $prefix) {
                if (str_starts_with($parentLower, $prefix) || $parentLower === rtrim($prefix, '\\')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Decide whether an interface carries a framework attribute that marks it as an extension point.
     *
     * @param list<string> $attributes - Fully qualified attribute names declared on the interface.
     * @param list<string> $frameworkAttributePrefixes - Lower-cased attribute prefixes that mark framework hooks.
     *
     * @return bool - true when an attribute matches a framework prefix, meaning the framework wires the interface so it is exempt
     */
    private function hasFrameworkAttribute(array $attributes, array $frameworkAttributePrefixes): bool
    {
        foreach ($attributes as $attribute) {
            $attributeLower = strtolower($attribute);
            foreach ($frameworkAttributePrefixes as $prefix) {
                if (str_starts_with($attributeLower, $prefix) || str_contains($attributeLower, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

}
