<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Modernisation;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Stmt;

/**
 * Detects public mutable properties that expose object state directly.
 */
final readonly class PublicPropertyRule implements RuleInterface
{
    /**
     * Stable rule identifier for public property findings.
     */
    public const ID = 'modernisation.public-property';

    /**
     * Describe the public property modernisation rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Public mutable property',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find mutable public properties that expose object state directly.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for public property declarations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            if (ModernisationNodeHelper::isDtoClass($class)) {
                continue;
            }

            foreach ($class->getProperties() as $property) {
                if (!$property->isPublic() || $property->isStatic() || $property->isReadonly()) {
                    continue;
                }

                foreach ($property->props as $propertyProperty) {
                    $name       = $propertyProperty->name->toString();
                    $findings[] = new Finding(
                        ruleId:      self::ID,
                        message:     sprintf('Public mutable property $%s exposes state directly.', $name),
                        filePath:    $analysisUnit->file->displayPath,
                        line:        $propertyProperty->getStartLine(),
                        severity:    Severity::Warning,
                        pillar:      Pillar::Modernisation,
                        tier:        RuleTier::V01,
                        confidence:  Confidence::High,
                        remediation: 'Prefer constructor-initialized readonly properties or methods that preserve invariants; DTO-style classes are exempt and gruff-php reports only.',
                        metadata:    [
                            'property' => $name,
                        ],
                    );
                }
            }
        }

        return $findings;
    }
}
