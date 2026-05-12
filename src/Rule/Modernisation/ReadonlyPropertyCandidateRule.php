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
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects constructor-initialized properties that can be declared readonly.
 */
final readonly class ReadonlyPropertyCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for readonly property candidate findings.
     */
    public const ID = 'modernisation.readonly-property-candidate';

    /**
     * Describe the readonly property candidate rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Readonly property candidate',
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Find constructor-assigned properties that could be readonly.
     *
     * @return list<Finding> Findings for readonly property candidates.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (!ModernisationNodeHelper::supportsPhp($context, 8.1)) {
            return [];
        }

        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            if (!$class->isFinal() || $class->extends !== null || $class->getTraitUses() !== []) {
                continue;
            }

            if ($class->isReadonly()) {
                continue;
            }

            $constructorAssignments = $this->constructorAssignments($class);
            $lateAssignments = $this->lateAssignments($class);

            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->isReadonly() || $property->type === null) {
                    continue;
                }

                foreach ($property->props as $propertyProperty) {
                    $name = $propertyProperty->name->toString();
                    if ($propertyProperty->default !== null || !isset($constructorAssignments[$name]) || isset($lateAssignments[$name])) {
                        continue;
                    }

                    $findings[] = new Finding(
                        ruleId: self::ID,
                        message: sprintf('Property $%s is only assigned in a final class constructor and may be a readonly candidate.', $name),
                        filePath: $unit->file->displayPath,
                        line: $propertyProperty->getStartLine(),
                        severity: Severity::Advisory,
                        pillar: Pillar::Modernisation,
                        tier: RuleTier::V01,
                        confidence: Confidence::Medium,
                        remediation: 'Consider readonly only after confirming no reflection, hydration, or late assignment contract depends on mutability; gruff-php reports only.',
                        metadata: [
                            'property' => $name,
                            'requiresPhp' => 8.1,
                        ],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<string, true>
     */
    private function constructorAssignments(Stmt\Class_ $class): array
    {
        $assignments = [];
        $constructor = null;
        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) === '__construct') {
                $constructor = $method;
                break;
            }
        }

        if (!$constructor instanceof Stmt\ClassMethod) {
            return [];
        }

        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($constructor->stmts ?? [], Expr\Assign::class) as $assign) {
            $name = ModernisationNodeHelper::propertyFetchName($assign->var);
            if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($assign->var)) {
                $assignments[$name] = true;
            }
        }

        return $assignments;
    }

    /**
     * @return array<string, true>
     */
    private function lateAssignments(Stmt\Class_ $class): array
    {
        $assignments = [];
        $finder = new NodeFinder();

        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) === '__construct') {
                continue;
            }

            foreach ($finder->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
                $name = ModernisationNodeHelper::propertyFetchName($assign->var);
                if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($assign->var)) {
                    $assignments[$name] = true;
                }
            }
        }

        return $assignments;
    }
}
