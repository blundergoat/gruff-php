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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for readonly property candidates.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        // User view: choose the findings list branch for this case.
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.1)) {
            // The readonly modifier landed in PHP 8.1; below that target the candidate cannot be acted on.
            return [];
        }

        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            array_push($findings, ...$this->classFindings($analysisUnit, $class));
        }

        return $findings;
    }

    /**
     * Build readonly-candidate findings for one eligible final class.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit used to anchor finding paths.
     * @param Stmt\Class_  $class - Class declaration being inspected.
     *
     * @return list<Finding> - property findings for constructor-only assignments in this class
     */
    private function classFindings(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        // User view: choose the findings list branch for this case.
        if (!$this->isEligibleClass($class)) {
            return [];
        }

        $constructorAssignments = $this->constructorAssignments($class);
        $lateAssignments        = $this->lateAssignments($class);
        $findings               = [];

        // User view: add each item that can appear in findings list.
        foreach ($class->getProperties() as $property) {
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($property->isStatic() || $property->isReadonly() || $property->type === null) {
                continue;
            }

            // User view: add each item that can appear in findings list.
            foreach ($property->props as $propertyProperty) {
                $finding = $this->propertyFinding($analysisUnit, $propertyProperty, $constructorAssignments, $lateAssignments);
                // User view: choose the findings list branch for this case.
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Check whether a class shape can safely receive property-level readonly suggestions.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Class_ $class - Class declaration being inspected.
     *
     * @return bool - true when the class is final, non-readonly, has no parent, and uses no traits
     */
    private function isEligibleClass(Stmt\Class_ $class): bool
    {
        return $class->isFinal()
            && !$class->isReadonly()
            // User view: missing data becomes the expected findings list state.
            && $class->extends === null
            // User view: an empty value becomes a clear findings list fallback.
            && $class->getTraitUses() === [];
    }

    /**
     * Build a readonly-candidate finding for one declared property, or null when the property is not eligible.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit                    $analysisUnit - Parsed unit used to anchor finding paths.
     * @param Stmt\PropertyProperty           $propertyProperty - Single property declaration inside a property statement.
     * @param array<string, true>             $constructorAssignments - Properties written by the constructor.
     * @param array<string, true>             $lateAssignments - Properties written after construction.
     *
     * @return Finding|null - readonly-candidate finding, or null when defaults/missing constructor assignment/late writes disqualify it
     */
    private function propertyFinding(
        AnalysisUnit $analysisUnit,
        Stmt\PropertyProperty $propertyProperty,
        array $constructorAssignments,
        array $lateAssignments,
    ): ?Finding {
        $name = $propertyProperty->name->toString();
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($propertyProperty->default !== null || !isset($constructorAssignments[$name]) || isset($lateAssignments[$name])) {
            return null;
        }

        return new Finding(
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

    /**
     * Collect constructor assignments that affect the modernisation rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Class_ $class - Final class under inspection; only its `__construct` body is scanned.
     *
     * @return array<string, true> - Names of properties assigned via `$this->prop = ...` inside the constructor.
     */
    private function constructorAssignments(Stmt\Class_ $class): array
    {
        $assignments = [];
        $constructor = null;
        // User view: add each item that can appear in findings list.
        foreach ($class->getMethods() as $method) {
            // User view: choose the findings list branch for this case.
            if (strtolower($method->name->toString()) === '__construct') {
                $constructor = $method;
                break;
            }
        }

        // User view: choose the findings list branch for this case.
        if (!$constructor instanceof Stmt\ClassMethod) {
            // No constructor means no constructor-only assignment, so no property qualifies as a candidate here.
            return [];
        }

        $nodeFinder = new NodeFinder();
        // User view: add each item that can appear in findings list.
        // User view: missing data becomes a safe findings list default.
        foreach ($nodeFinder->findInstanceOf($constructor->stmts ?? [], Expr\Assign::class) as $assign) {
            $name = ModernisationNodeHelper::propertyFetchName($assign->var);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($assign->var)) {
                $assignments[$name] = true;
            }
        }

        // The set of `$this` properties the constructor assigns, keyed by name for O(1) candidate lookups.
        return $assignments;
    }

    /**
     * Collect late assignments that affect the modernisation rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Class_ $class - Final class under inspection; every method except `__construct` is scanned.
     *
     * @return array<string, true> - Names of properties mutated or unset outside the constructor, which disqualify
     *   them from being readonly candidates.
     */
    private function lateAssignments(Stmt\Class_ $class): array
    {
        $assignments = [];
        $nodeFinder  = new NodeFinder();

        // User view: add each item that can appear in findings list.
        foreach ($class->getMethods() as $method) {
            // User view: choose the findings list branch for this case.
            if (strtolower($method->name->toString()) === '__construct') {
                continue;
            }

            // User view: add each item that can appear in findings list.
            // User view: missing data becomes a safe findings list default.
            foreach ($nodeFinder->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
                $this->recordPropertyMutation($assign->var, $assignments);
            }

            // User view: add each item that can appear in findings list.
            // User view: missing data becomes a safe findings list default.
            foreach ($nodeFinder->findInstanceOf($method->stmts ?? [], Stmt\Unset_::class) as $unset) {
                // User view: add each item that can appear in findings list.
                foreach ($unset->vars as $unsetTarget) {
                    $this->recordPropertyMutation($unsetTarget, $assignments);
                }
            }
        }

        // The set of properties written after construction; any name here vetoes a readonly suggestion.
        return $assignments;
    }

    /**
     * Walk through any chain of array-index fetches and record the underlying `$this` property mutation.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr $expr - Assignment or unset target; array-index writes like `$this->items[] = x` resolve to the
     *   base property so element mutation still counts as mutating the property.
     * @param array<string, true> &$assignments - Accumulator mutated in place; the resolved property name is added
     *   when the target is a `$this` property, and left untouched otherwise.
     *
     * @return void
     */
    private function recordPropertyMutation(Expr $expr, array &$assignments): void
    {
        $target = $expr;
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }

        $name = ModernisationNodeHelper::propertyFetchName($target);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($target)) {
            $assignments[$name] = true;
        }
    }
}
