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
 * Flags a property that is only ever assigned once, in the constructor of a final class, so the user can
 * consider marking it `readonly` and letting the engine enforce that immutability.
 *
 * Runs per file on PHP 8.1+ targets. It limits itself to eligible shapes - a final, non-readonly class
 * with no parent and no traits - then reports each typed property the constructor assigns and no later
 * method mutates or unsets. Advisory only: reflection or hydration may rely on mutability, so gruff-php
 * suggests rather than gates.
 */
final readonly class ReadonlyPropertyCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for readonly property candidate findings.
     */
    public const ID = 'modernisation.readonly-property-candidate';

    /**
     * Describes the readonly-property-candidate rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (advisory severity, medium confidence).
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
     * Reports each constructor-only property in a final class that could become readonly.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context supplying the target PHP version.
     *
     * @return list<Finding> - One finding per readonly candidate; empty on pre-8.1 targets or when no property qualifies.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.1)) {
            // The readonly modifier landed in PHP 8.1; below that target the candidate cannot be acted on.
            return [];
        }

        $findings = [];

        // Inspect every class declared in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            array_push($findings, ...$this->classFindings($analysisUnit, $class));
        }

        return $findings;
    }

    /**
     * Builds the readonly-candidate findings for one eligible final class.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit used to anchor finding paths.
     * @param Stmt\Class_  $class - Class declaration being inspected.
     *
     * @return list<Finding> - property findings for constructor-only assignments in this class
     */
    private function classFindings(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        // Only a self-contained final class is safe to suggest readonly for.
        if (!$this->isEligibleClass($class)) {
            return [];
        }

        $constructorAssignments = $this->constructorAssignments($class);
        $lateAssignments        = $this->lateAssignments($class);
        $findings               = [];

        // Check each property the class declares.
        foreach ($class->getProperties() as $property) {
            // A static, already-readonly, or untyped property is not a candidate.
            if ($property->isStatic() || $property->isReadonly() || $property->type === null) {
                continue;
            }

            // One declaration can name several properties, so weigh each.
            foreach ($property->props as $propertyProperty) {
                $finding = $this->propertyFinding($analysisUnit, $propertyProperty, $constructorAssignments, $lateAssignments);
                // Keep only the properties that actually qualified.
                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Reports whether a class shape can safely receive property-level readonly suggestions.
     *
     * @param Stmt\Class_ $class - Class declaration being inspected.
     *
     * @return bool - true when the class is final, non-readonly, has no parent, and uses no traits
     */
    private function isEligibleClass(Stmt\Class_ $class): bool
    {
        return $class->isFinal()
            && !$class->isReadonly()
            && $class->extends === null
            && $class->getTraitUses() === [];
    }

    /**
     * Builds a readonly-candidate finding for one property, or null when the property is not eligible.
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
        // A default value, no constructor write, or a later write each rules the property out.
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
     * Collects the property names the constructor assigns via `$this->prop = ...`.
     *
     * @param Stmt\Class_ $class - Final class under inspection; only its `__construct` body is scanned.
     *
     * @return array<string, true> - Names of properties assigned via `$this->prop = ...` inside the constructor.
     */
    private function constructorAssignments(Stmt\Class_ $class): array
    {
        $assignments = [];
        $constructor = null;
        // Find the constructor among the class methods.
        foreach ($class->getMethods() as $method) {
            // Stop at the constructor; that is the only body we read here.
            if (strtolower($method->name->toString()) === '__construct') {
                $constructor = $method;
                break;
            }
        }

        if (!$constructor instanceof Stmt\ClassMethod) {
            // No constructor means no constructor-only assignment, so no property qualifies as a candidate here.
            return [];
        }

        $nodeFinder = new NodeFinder();
        // Record each `$this->prop = ...` the constructor performs.
        foreach ($nodeFinder->findInstanceOf($constructor->stmts ?? [], Expr\Assign::class) as $assign) {
            $name = ModernisationNodeHelper::propertyFetchName($assign->var);
            // Track only assignments to a named `$this` property.
            if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($assign->var)) {
                $assignments[$name] = true;
            }
        }

        // The set of `$this` properties the constructor assigns, keyed by name for O(1) candidate lookups.
        return $assignments;
    }

    /**
     * Collects the property names mutated or unset after construction, which veto a readonly suggestion.
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

        // Scan every method for writes that happen after construction.
        foreach ($class->getMethods() as $method) {
            // Skip the constructor; its assignments are the allowed ones.
            if (strtolower($method->name->toString()) === '__construct') {
                continue;
            }

            // Any assignment outside the constructor is a late write.
            foreach ($nodeFinder->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
                $this->recordPropertyMutation($assign->var, $assignments);
            }

            // An unset after construction also counts as a late mutation.
            foreach ($nodeFinder->findInstanceOf($method->stmts ?? [], Stmt\Unset_::class) as $unset) {
                // One unset can clear several targets.
                foreach ($unset->vars as $unsetTarget) {
                    $this->recordPropertyMutation($unsetTarget, $assignments);
                }
            }
        }

        // The set of properties written after construction; any name here vetoes a readonly suggestion.
        return $assignments;
    }

    /**
     * Resolves a write target to its base `$this` property and records the mutation.
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
        // Peel off array-index writes so `$this->items[] = x` still credits the underlying property.
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }

        $name = ModernisationNodeHelper::propertyFetchName($target);
        // Record the mutation only for a named `$this` property.
        if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($target)) {
            $assignments[$name] = true;
        }
    }
}
