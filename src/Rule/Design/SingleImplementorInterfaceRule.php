<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Design;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\ProjectRuleInterface;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\ComplexType;
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
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Detects project interfaces that have only one concrete implementation.
 */
final readonly class SingleImplementorInterfaceRule implements ProjectRuleInterface
{
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
     * @return RuleDefinition Rule metadata, defaults, and options.
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
                'externalNamespacePrefixes' => self::DEFAULT_EXTERNAL_PREFIXES,
                'frameworkAttributePrefixes' => self::DEFAULT_FRAMEWORK_ATTRIBUTE_PREFIXES,
                'treatMockUsageAsImplementor' => false,
                'additionalExcludedPaths' => [],
            ],
        );
    }

    /**
     * Analyse all project units for interfaces with exactly one concrete implementor.
     *
     * @param list<AnalysisUnit> $units   Parsed project units to analyse together.
     * @param RuleContext        $ruleContext Rule context carrying config and settings.
     *
     * @return list<Finding> Findings for interfaces that lack substitutability value.
     */
    public function analyseProject(array $units, RuleContext $ruleContext): array
    {
        $settings                   = $ruleContext->settingsFor($this->definition());
        $externalPrefixes           = array_map(static fn (string $prefix): string => strtolower($prefix), $settings->stringListOption('externalNamespacePrefixes'));
        $frameworkAttributePrefixes = array_map(static fn (string $prefix): string => strtolower($prefix), $settings->stringListOption('frameworkAttributePrefixes'));
        $excludedPaths              = $settings->stringListOption('additionalExcludedPaths');

        $eligibleUnits = $this->filterEligibleUnits($units, $excludedPaths);
        $resolvedUnits = $this->resolveNames($eligibleUnits);

        $projectTypes = $this->collectProjectTypes($resolvedUnits);

        return $this->buildFindings(
            interfaces:                 $projectTypes['interfaces'],
            implementations:            $projectTypes['implementations'],
            typeReferences:             $projectTypes['typeReferences'],
            extendedInterfaces:         $projectTypes['extendedInterfaces'],
            externalPrefixes:           $externalPrefixes,
            frameworkAttributePrefixes: $frameworkAttributePrefixes,
        );
    }

    /**
     * @param list<array{unit: AnalysisUnit, statements: list<Node\Stmt>}> $resolvedUnits
     * @return array{
     *     interfaces: array<string, array{fqn: string, unit: AnalysisUnit, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * }
     */
    private function collectProjectTypes(array $resolvedUnits): array
    {
        $projectTypes = [
            'interfaces' => [],
            'implementations' => [],
            'typeReferences' => [],
            'extendedInterfaces' => [],
        ];

        foreach ($resolvedUnits as $resolved) {
            $this->collectUnitTypes($resolved['unit'], $resolved['statements'], $projectTypes);
        }

        return $projectTypes;
    }

    /**
     * @param list<Node\Stmt> $statements
     * @param array{
     *     interfaces: array<string, array{fqn: string, unit: AnalysisUnit, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * } $projectTypes
     * @return void
     */
    private function collectUnitTypes(AnalysisUnit $analysisUnit, array $statements, array &$projectTypes): void
    {
        $nodeFinder = new NodeFinder();

        foreach ($nodeFinder->find($statements, static fn (Node $node): bool => $node instanceof Interface_ || $node instanceof Class_) as $node) {
            if ($node instanceof Interface_) {
                $this->recordInterface($node, $analysisUnit, $projectTypes);
                continue;
            }

            if ($node instanceof Class_) {
                $this->recordClass($node, $analysisUnit, $nodeFinder, $projectTypes);
            }
        }
    }

    /**
     * @param array{
     *     interfaces: array<string, array{fqn: string, unit: AnalysisUnit, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * } $projectTypes
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
            'fqn' => $fqn,
            'unit' => $analysisUnit,
            'line' => $interface->getStartLine(),
            'extends' => $extends,
            'attributes' => $this->resolveAttributes(array_values($interface->attrGroups)),
        ];

        foreach ($extends as $parentFqn) {
            $projectTypes['extendedInterfaces'][$parentFqn] = true;
        }
    }

    /**
     * @param array{
     *     interfaces: array<string, array{fqn: string, unit: AnalysisUnit, line: int, extends: list<string>, attributes: list<string>}>,
     *     implementations: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     typeReferences: array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>,
     *     extendedInterfaces: array<string, true>
     * } $projectTypes
     * @return void
     */
    private function recordClass(Class_ $class, AnalysisUnit $analysisUnit, NodeFinder $nodeFinder, array &$projectTypes): void
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
                'classFqn' => $classFqn,
                'unit' => $analysisUnit,
                'line' => $class->getStartLine(),
            ];
        }

        foreach ($this->collectClassTypeReferences($class, $classFqn, $nodeFinder) as $reference) {
            $projectTypes['typeReferences'][$reference['targetFqn']][] = [
                'classFqn' => $reference['classFqn'],
                'unit' => $analysisUnit,
                'line' => $reference['line'],
            ];
        }
    }

    /**
     * @param array<string, array{fqn: string, unit: AnalysisUnit, line: int, extends: list<string>, attributes: list<string>}> $interfaces
     * @param array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>                                       $implementations
     * @param array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>>                                       $typeReferences
     * @param array<string, true>                                                                                               $extendedInterfaces
     * @param list<string>                                                                                                      $externalPrefixes
     * @param list<string>                                                                                                      $frameworkAttributePrefixes
     * @return list<Finding>
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
                ruleId:  $definition->id,
                message: sprintf(
                    'Interface %s has one implementor (%s) and no external type-hint usage; consider removing the interface and depending on the class directly.',
                    $fqn,
                    $implementorFqn,
                ),
                filePath:    $interface['unit']->file->displayPath,
                line:        $interface['line'],
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $fqn,
                remediation: 'Either delete the interface and depend on the concrete class, add a second implementor / external type-hint usage that justifies the abstraction, or configure additionalExcludedPaths when the interface comes from copied vendor/framework code.',
                metadata:    [
                    'interfaceFqn' => $fqn,
                    'implementorCount' => $implementorCount,
                    'implementorFqn' => $implementorFqn,
                    'externalUsageCount' => $externalUsages,
                    'decision' => sprintf('flagged: %d implementor, %d external usages', $implementorCount, $externalUsages),
                ],
            );
        }

        return $findings;
    }

    /**
     * @param list<AnalysisUnit> $units
     * @param list<string>       $excludedPaths
     * @return list<AnalysisUnit>
     */
    private function filterEligibleUnits(array $units, array $excludedPaths): array
    {
        return array_values(array_filter(
            $units,
            static function (AnalysisUnit $analysisUnit) use ($excludedPaths): bool {
                $display = $analysisUnit->file->displayPath;

                if (str_starts_with($display, 'vendor/')) {
                    return false;
                }

                foreach ($excludedPaths as $excluded) {
                    if (str_starts_with($display, $excluded)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * @param list<AnalysisUnit> $units
     * @return list<array{unit: AnalysisUnit, statements: list<Node\Stmt>}>
     */
    private function resolveNames(array $units): array
    {
        $resolved = [];

        foreach ($units as $unit) {
            $nodeTraverser = new NodeTraverser();
            $nodeTraverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
            /** @var list<Node\Stmt> $resolvedStatements NameResolver preserves the statement list shape. */
            $resolvedStatements = $nodeTraverser->traverse($unit->statements);
            $resolved[]         = ['unit' => $unit, 'statements' => $resolvedStatements];
        }

        return $resolved;
    }

    /**
     * Resolve the fully qualified declaration name for a class or interface.
     *
     * @return string|null Fully qualified name, or null for anonymous declarations.
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
     * @param array<Name>|null $names
     * @return list<string>
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
     * @return string Fully qualified or original name without leading slash.
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
     * @param list<AttributeGroup> $attrGroups
     * @return list<string>
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
     * @return list<array{targetFqn: string, classFqn: string, line: int}>
     */
    private function collectClassTypeReferences(Class_ $class, string $classFqn, NodeFinder $nodeFinder): array
    {
        $references = [];

        foreach ($nodeFinder->find([$class], static fn (Node $node): bool => $node instanceof Param || $node instanceof ClassMethod || $node instanceof Property) as $node) {
            if ($node instanceof Param) {
                $type = $node->type;
            } elseif ($node instanceof ClassMethod) {
                $type = $node->returnType;
            } elseif ($node instanceof Property) {
                $type = $node->type;
            } else {
                continue;
            }

            foreach ($this->namesFromType($type) as $name) {
                $references[] = [
                    'targetFqn' => $this->resolveName($name),
                    'classFqn' => $classFqn,
                    'line' => $node->getStartLine(),
                ];
            }
        }

        return $references;
    }

    /**
     * @return list<Name>
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
     * @param list<string> $extends
     * @param list<string> $externalPrefixes
     *
     * @return bool True when any parent interface matches an external prefix.
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
     * @param list<string> $attributes
     * @param list<string> $frameworkAttributePrefixes
     *
     * @return bool True when any attribute matches a framework prefix.
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
