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
 * Flags a public mutable property that lets any caller read and overwrite object state directly, so the
 * user can move to readonly properties or accessor methods that keep the class's invariants intact.
 *
 * Runs per file over every class, skipping DTO-style data carriers where public fields are the whole
 * point. Each remaining public, non-static, non-readonly property is reported at warning - gruff-php only
 * surfaces it, it never rewrites the property for you.
 */
final readonly class PublicPropertyRule implements RuleInterface
{
    /**
     * Stable rule identifier for public property findings.
     */
    public const ID = 'modernisation.public-property';

    /**
     * Describes the public-property modernisation rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
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
     * Reports each public mutable property that exposes state a caller could overwrite.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per exposed public mutable property; empty when every class guards its state.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Inspect every class declared in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            // A DTO exists to carry public data, so its public fields are intentional, not a leak.
            if (ModernisationNodeHelper::isDtoClass($class)) {
                continue;
            }

            // Check each property the class declares.
            foreach ($class->getProperties() as $property) {
                // Only a plain public, non-static, non-readonly property exposes overwritable state.
                if (!$property->isPublic() || $property->isStatic() || $property->isReadonly()) {
                    continue;
                }

                // One declaration can name several properties, so report each name separately.
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
