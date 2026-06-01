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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects constructor assignments that could use property promotion.
 */
final readonly class ConstructorPromotionCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for constructor promotion candidate findings.
     */
    public const ID = 'modernisation.constructor-promotion-candidate';

    /**
     * Describe the constructor-promotion candidate rule.
     *
     * @return RuleDefinition - advisory, medium-confidence metadata that registers the rule and keeps it from gating a build alone
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Constructor property promotion candidate',
            pillar:          Pillar::Modernisation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find constructor assignments that can likely use property promotion.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - one advisory per promotable constructor assignment across the unit's classes; empty on pre-8.0 targets or when none
     *                       qualify
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            // Promotion only exists on PHP 8+; on older targets the suggestion would be invalid, so stay silent.
            return [];
        }

        $findings = [];

        foreach ($this->candidateClasses($analysisUnit) as $class) {
            array_push($findings, ...$this->findingsForClass($analysisUnit, $class));
        }

        return $findings;
    }

    /**
     * Find classes with constructors that may support property promotion.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose class declarations are screened.
     *
     * @return list<Stmt\Class_> - classes whose self-contained constructor shape clears the promotion pre-check; empty when none qualify
     */
    private function candidateClasses(AnalysisUnit $analysisUnit): array
    {
        $classes = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            /** @var Stmt\Class_ $class Finder predicate restricts results to class declarations. */
            if ($this->canPromoteClass($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Check whether a class has a simple shape suitable for promotion suggestions.
     *
     * @param Stmt\Class_ $class - Class declaration to screen before suggesting promotion.
     *
     * @return bool - true only when the class has no parent, no traits, and a declared constructor, so a single-class scan can reason about
     *              promotion safely
     */
    private function canPromoteClass(Stmt\Class_ $class): bool
    {
        // Parent and trait state are invisible to this single-class scan, so restrict to a self-contained constructor.
        return $class->extends === null
               && $class->getTraitUses() === []
               && $this->constructor($class) instanceof Stmt\ClassMethod;
    }

    /**
     * Build promotion findings for constructor assignments in one class.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path for any finding raised here.
     * @param Stmt\Class_  $class - Class whose constructor body is scanned for promotable assignments.
     *
     * @return list<Finding> - one advisory finding per constructor assignment eligible for promotion in this class; empty when none are
     */
    private function findingsForClass(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        $constructor = $this->constructor($class);
        if (!$constructor instanceof Stmt\ClassMethod) {
            // A class with no constructor has nothing to promote, so contribute no findings.
            return [];
        }

        $properties      = $this->declaredProperties($class);
        $lateAssignments = $this->lateAssignments($class);
        $findings        = [];

        foreach ($this->constructorAssignments($constructor) as $assign) {
            $property = $this->promotableProperty($assign, $constructor, $properties, $lateAssignments);

            if ($property !== null) {
                $findings[] = $this->finding($analysisUnit, $assign, $property);
            }
        }

        return $findings;
    }

    /**
     * Collect constructor assignments that affect the modernisation rule.
     *
     * @param Stmt\ClassMethod $constructor - Constructor whose top-level statements are scanned.
     *
     * @return list<Expr\Assign> - the constructor's direct top-level assignment expressions in source order; nested and conditional writes excluded
     */
    private function constructorAssignments(Stmt\ClassMethod $constructor): array
    {
        $assignments = [];

        foreach ($constructor->stmts ?? [] as $statement) {
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign) {
                $assignments[] = $statement->expr;
            }
        }

        return $assignments;
    }

    /**
     * Decide whether one constructor assignment is a safe property-promotion candidate.
     *
     * @param Expr\Assign         $assign - Single `$this->x = $x;` assignment under test.
     * @param Stmt\ClassMethod    $constructor - Constructor that must expose a matching plain parameter.
     * @param array<string, true> $properties - Set of non-static, non-public property names declared on the class.
     * @param array<string, true> $lateAssignments - Names written outside the constructor; presence blocks promotion.
     *
     * @return string|null - name of the property safe to promote; null when the assignment is not a plain same-named parameter copy into a known
     *                     property or a later write would be lost
     */
    private function promotableProperty(
        Expr\Assign      $assign,
        Stmt\ClassMethod $constructor,
        array            $properties,
        array            $lateAssignments,
    ): ?string {
        $property = ModernisationNodeHelper::propertyFetchName($assign->var);

        if (
            $property === null
            || !ModernisationNodeHelper::isThisPropertyFetch($assign->var)
            || !isset($properties[$property])
        ) {
            // Not an assignment into a known instance property of this class, so it cannot be promoted.
            return null;
        }

        if (!$assign->expr instanceof Expr\Variable || $assign->expr->name !== $property) {
            // The value is something other than the same-named parameter, so promotion would change behaviour.
            return null;
        }

        if (isset($lateAssignments[$property]) || !$this->hasPlainConstructorParameter($constructor, $property)) {
            // A later reassignment or an already-promoted parameter means a rewrite would not be equivalent.
            return null;
        }

        return $property;
    }

    /**
     * Return the class constructor when one is declared.
     *
     * @param Stmt\Class_ $class - Class declaration whose methods are searched for a constructor.
     *
     * @return Stmt\ClassMethod|null - the case-insensitively matched __construct method; null means the class has no explicit constructor to promote
     *                               into
     */
    private function constructor(Stmt\Class_ $class): ?Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $classMethod) {
            if (strtolower($classMethod->name->toString()) === '__construct') {
                // Constructor names are case-insensitive in PHP, so the lowercased compare is the reliable match.
                return $classMethod;
            }
        }

        return null;
    }

    /**
     * Index declared property names on the class.
     *
     * @param Stmt\Class_ $class - Class declaration whose property list is indexed.
     *
     * @return array<string, true> - set of non-static, non-public property names declared on the class, keyed by name for O(1) lookup
     */
    private function declaredProperties(Stmt\Class_ $class): array
    {
        $properties = [];
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || $property->isPublic()) {
                continue;
            }

            foreach ($property->props as $propertyProperty) {
                $properties[$propertyProperty->name->toString()] = true;
            }
        }

        return $properties;
    }

    /**
     * Collect late assignments that affect the modernisation rule.
     *
     * @param Stmt\Class_ $class - Class declaration whose non-constructor methods are scanned for property writes.
     *
     * @return array<string, true> - set of property names written outside the constructor, keyed by name; presence of a key blocks promotion
     */
    private function lateAssignments(Stmt\Class_ $class): array
    {
        $assignments = [];
        $nodeFinder  = new NodeFinder();

        foreach ($class->getMethods() as $classMethod) {
            if (strtolower($classMethod->name->toString()) === '__construct') {
                continue;
            }

            foreach ($nodeFinder->findInstanceOf($classMethod->stmts ?? [], Expr\Assign::class) as $assign) {
                $name = ModernisationNodeHelper::propertyFetchName($assign->var);
                if ($name !== null && ModernisationNodeHelper::isThisPropertyFetch($assign->var)) {
                    $assignments[$name] = true;
                }
            }
        }

        // These properties are written after construction, so promoting them would drop the later mutation.
        return $assignments;
    }

    /**
     * Check whether the constructor has an unpromoted parameter matching the property.
     *
     * @param Stmt\ClassMethod $constructor - Constructor whose parameter list is searched.
     * @param string           $property - Property name the parameter must share for a promotion rewrite.
     *
     * @return bool - true when a same-named parameter exists with no visibility modifier, so it is not already promoted and can safely adopt one
     */
    private function hasPlainConstructorParameter(Stmt\ClassMethod $constructor, string $property): bool
    {
        foreach ($constructor->params as $parameter) {
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $property && $parameter->flags === 0) {
                // Zero flags means no visibility modifier, so this parameter is not already promoted and can adopt one.
                return true;
            }
        }

        return false;
    }

    /**
     * Build the finding for a promotable property assignment.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit providing the display path reported to the user.
     * @param Node         $node - Assignment node whose start line anchors the finding.
     * @param string       $property - Property name interpolated into the advisory message and metadata.
     *
     * @return Finding - fixed-shape advisory anchored at the assignment line, carrying the property name in both the human message and machine
     *                 metadata
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $property): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Property $%s is assigned directly from the same constructor parameter; PHP 8 property promotion may reduce boilerplate.', $property),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Advisory,
            pillar:      Pillar::Modernisation,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Consider constructor property promotion after confirming the rewrite preserves constructor semantics; gruff-php reports only.',
            metadata:    [
                             'property'    => $property,
                             'requiresPhp' => 8.0,
                         ],
        );
    }
}
