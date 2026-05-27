<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
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
            id:              self::ID,
            name:            'Readonly property candidate',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find constructor-assigned properties that could be readonly.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for readonly property candidates.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.1)) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            if (!$class->isFinal() || $class->extends !== null || $class->getTraitUses() !== []) {
                continue;
            }

            if ($class->isReadonly()) {
                continue;
            }

            $constructorAssignments = $this->constructorAssignments($class);
            $lateAssignments        = $this->lateAssignments($class);

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
                        ruleId:      self::ID,
                        message:     sprintf('Property $%s is only assigned in a final class constructor and may be a readonly candidate.', $name),
                        filePath:    $analysisUnit->file->displayPath,
                        line:        $propertyProperty->getStartLine(),
                        severity:    Severity::Advisory,
                        pillar:      Pillar::Modernisation,
                        tier:        RuleTier::V01,
                        confidence:  Confidence::Medium,
                        remediation: 'Consider readonly only after confirming no reflection, hydration, or late assignment contract depends on mutability; gruff-php reports only.',
                        metadata:    [
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
     * Collect constructor assignments that affect the modernisation rule.
     *
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

        $nodeFinder = new NodeFinder();
        foreach ($nodeFinder->findInstanceOf($constructor->stmts ?? [], Expr\Assign::class) as $assign) {
            $name = ModernisationNodeHelper::propertyFetchName($assign->var);
            if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($assign->var)) {
                $assignments[$name] = true;
            }
        }

        return $assignments;
    }

    /**
     * Collect late assignments that affect the modernisation rule.
     *
     * @return array<string, true>
     */
    private function lateAssignments(Stmt\Class_ $class): array
    {
        $assignments = [];
        $nodeFinder  = new NodeFinder();

        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) === '__construct') {
                continue;
            }

            foreach ($nodeFinder->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
                $this->recordPropertyMutation($assign->var, $assignments);
            }

            foreach ($nodeFinder->findInstanceOf($method->stmts ?? [], Stmt\Unset_::class) as $unset) {
                foreach ($unset->vars as $unsetTarget) {
                    $this->recordPropertyMutation($unsetTarget, $assignments);
                }
            }
        }

        return $assignments;
    }

    /**
     * Walk through any chain of array-index fetches and record the underlying `$this` property mutation.
     *
     * @param array<string, true> &$assignments
     */
    private function recordPropertyMutation(Expr $expr, array &$assignments): void
    {
        $target = $expr;
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }

        $name = ModernisationNodeHelper::propertyFetchName($target);
        if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($target)) {
            $assignments[$name] = true;
        }
    }
}
