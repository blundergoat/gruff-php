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

final readonly class SingleImplementorInterfaceRule implements ProjectRuleInterface
{
    public const ID = 'design.single-implementor-interface';

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

    private const DEFAULT_FRAMEWORK_ATTRIBUTE_PREFIXES = [
        'Symfony\\',
        'Doctrine\\',
        'Attribute\\AsCommand',
        'Attribute\\AsController',
        'Attribute\\AutoconfigureTag',
        'Attribute\\AsEventSubscriber',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Single-implementor interface',
            pillar: Pillar::Design,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
            defaultOptions: [
                'externalNamespacePrefixes' => self::DEFAULT_EXTERNAL_PREFIXES,
                'frameworkAttributePrefixes' => self::DEFAULT_FRAMEWORK_ATTRIBUTE_PREFIXES,
                'treatMockUsageAsImplementor' => false,
                'additionalExcludedPaths' => [],
            ],
        );
    }

    public function analyseProject(array $units, RuleContext $context): array
    {
        $settings = $context->settingsFor($this->definition());
        $externalPrefixes = $this->lowercaseList($settings->stringListOption('externalNamespacePrefixes'));
        $frameworkAttributePrefixes = $this->lowercaseList($settings->stringListOption('frameworkAttributePrefixes'));
        $excludedPaths = $settings->stringListOption('additionalExcludedPaths');

        $eligibleUnits = $this->filterEligibleUnits($units, $excludedPaths);
        $resolvedUnits = $this->resolveNames($eligibleUnits);

        $interfaces = [];
        $implementations = [];
        $typeReferences = [];
        $extendedInterfaces = [];

        foreach ($resolvedUnits as $resolved) {
            $unit = $resolved['unit'];
            $statements = $resolved['statements'];
            $finder = new NodeFinder();

            /** @var list<Interface_> $interfaceNodes */
            $interfaceNodes = $finder->findInstanceOf($statements, Interface_::class);
            foreach ($interfaceNodes as $interface) {
                if ($interface->name === null) {
                    continue;
                }

                $fqn = $this->declarationFqn($interface);
                if ($fqn === null) {
                    continue;
                }

                $interfaces[$fqn] = [
                    'fqn' => $fqn,
                    'unit' => $unit,
                    'line' => $interface->getStartLine(),
                    'extends' => $this->resolveNameList($interface->extends),
                    'attributes' => $this->resolveAttributes(array_values($interface->attrGroups)),
                ];

                foreach ($this->resolveNameList($interface->extends) as $parentFqn) {
                    $extendedInterfaces[$parentFqn] = true;
                }
            }

            /** @var list<Class_> $classNodes */
            $classNodes = $finder->findInstanceOf($statements, Class_::class);
            foreach ($classNodes as $class) {
                if ($class->name === null) {
                    continue;
                }

                $classFqn = $this->declarationFqn($class);
                if ($classFqn === null) {
                    continue;
                }

                foreach ($this->resolveNameList($class->implements) as $implementedFqn) {
                    $implementations[$implementedFqn][] = [
                        'classFqn' => $classFqn,
                        'unit' => $unit,
                        'line' => $class->getStartLine(),
                    ];
                }
            }

            foreach ($this->collectTypeReferences($statements, $finder) as $reference) {
                $typeReferences[$reference['targetFqn']][] = [
                    'classFqn' => $reference['classFqn'],
                    'unit' => $unit,
                    'line' => $reference['line'],
                ];
            }
        }

        return $this->buildFindings(
            $interfaces,
            $implementations,
            $typeReferences,
            $extendedInterfaces,
            $externalPrefixes,
            $frameworkAttributePrefixes,
        );
    }

    /**
     * @param array<string, array{fqn: string, unit: AnalysisUnit, line: int, extends: list<string>, attributes: list<string>}> $interfaces
     * @param array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>> $implementations
     * @param array<string, list<array{classFqn: string, unit: AnalysisUnit, line: int}>> $typeReferences
     * @param array<string, true> $extendedInterfaces
     * @param list<string> $externalPrefixes
     * @param list<string> $frameworkAttributePrefixes
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
        $findings = [];
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

            $implementors = $implementations[$fqn] ?? [];
            $implementorCount = count($implementors);

            if ($implementorCount !== 1) {
                continue;
            }

            $implementor = $implementors[0];
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
                ruleId: $definition->id,
                message: sprintf(
                    'Interface %s has one implementor (%s) and no external type-hint usage; consider removing the interface and depending on the class directly.',
                    $fqn,
                    $implementorFqn,
                ),
                filePath: $interface['unit']->file->displayPath,
                line: $interface['line'],
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                symbol: $fqn,
                remediation: 'Either delete the interface and depend on the concrete class, or add a second implementor / external type-hint usage that justifies the abstraction.',
                metadata: [
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
     * @param list<string> $excludedPaths
     * @return list<AnalysisUnit>
     */
    private function filterEligibleUnits(array $units, array $excludedPaths): array
    {
        return array_values(array_filter(
            $units,
            static function (AnalysisUnit $unit) use ($excludedPaths): bool {
                $display = $unit->file->displayPath;

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
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
            /** @var list<Node\Stmt> $resolvedStatements */
            $resolvedStatements = $traverser->traverse($unit->statements);
            $resolved[] = ['unit' => $unit, 'statements' => $resolvedStatements];
        }

        return $resolved;
    }

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
     * @param list<Node\Stmt> $statements
     * @return list<array{targetFqn: string, classFqn: string, line: int}>
     */
    private function collectTypeReferences(array $statements, NodeFinder $finder): array
    {
        $references = [];

        /** @var list<Class_> $classNodes */
        $classNodes = $finder->findInstanceOf($statements, Class_::class);
        foreach ($classNodes as $class) {
            $classFqn = $this->declarationFqn($class);
            if ($classFqn === null) {
                continue;
            }

            /** @var list<Param> $params */
            $params = $finder->findInstanceOf([$class], Param::class);
            foreach ($params as $param) {
                foreach ($this->namesFromType($param->type) as $name) {
                    $references[] = [
                        'targetFqn' => $this->resolveName($name),
                        'classFqn' => $classFqn,
                        'line' => $param->getStartLine(),
                    ];
                }
            }

            /** @var list<ClassMethod> $methods */
            $methods = $finder->findInstanceOf([$class], ClassMethod::class);
            foreach ($methods as $method) {
                foreach ($this->namesFromType($method->returnType) as $name) {
                    $references[] = [
                        'targetFqn' => $this->resolveName($name),
                        'classFqn' => $classFqn,
                        'line' => $method->getStartLine(),
                    ];
                }
            }

            /** @var list<Property> $properties */
            $properties = $finder->findInstanceOf([$class], Property::class);
            foreach ($properties as $property) {
                foreach ($this->namesFromType($property->type) as $name) {
                    $references[] = [
                        'targetFqn' => $this->resolveName($name),
                        'classFqn' => $classFqn,
                        'line' => $property->getStartLine(),
                    ];
                }
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

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function lowercaseList(array $items): array
    {
        return array_map(static fn (string $item): string => strtolower($item), $items);
    }
}
