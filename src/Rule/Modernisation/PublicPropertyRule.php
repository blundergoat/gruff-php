<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class PublicPropertyRule implements RuleInterface
{
    public const ID = 'modernisation.public-property';

    /**
     * Describe the public property modernisation rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Public mutable property',
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Find mutable public properties that expose object state directly.
     *
     * @return list<Finding> Findings for public property declarations.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            if (ModernisationNodeHelper::isDtoClass($class)) {
                continue;
            }

            foreach ($class->getProperties() as $property) {
                if (!$property->isPublic() || $property->isStatic() || $property->isReadonly()) {
                    continue;
                }

                foreach ($property->props as $propertyProperty) {
                    $name = $propertyProperty->name->toString();
                    $findings[] = new Finding(
                        ruleId: self::ID,
                        message: sprintf('Public mutable property $%s exposes state directly.', $name),
                        filePath: $unit->file->displayPath,
                        line: $propertyProperty->getStartLine(),
                        severity: Severity::Warning,
                        pillar: Pillar::Modernisation,
                        tier: RuleTier::V01,
                        confidence: Confidence::High,
                        remediation: 'Prefer constructor-initialized readonly properties or methods that preserve invariants; DTO-style classes are exempt and gruff-php reports only.',
                        metadata: [
                            'property' => $name,
                        ],
                    );
                }
            }
        }

        return $findings;
    }
}
