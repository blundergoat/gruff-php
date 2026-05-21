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
     * @return RuleDefinition Rule metadata and defaults.
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
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for promotable constructor assignments.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        if (!ModernisationNodeHelper::supportsPhp($ruleContext, 8.0)) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach ($this->candidateClasses($analysisUnit, $nodeFinder) as $class) {
            array_push($findings, ...$this->findingsForClass($analysisUnit, $class));
        }

        return $findings;
    }

    /**
     * @return list<Stmt\Class_>
     */
    private function candidateClasses(AnalysisUnit $analysisUnit, NodeFinder $nodeFinder): array
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
     * @return bool True when the class shape is supported by this heuristic.
     */
    private function canPromoteClass(Stmt\Class_ $class): bool
    {
        return $class->extends === null
            && $class->getTraitUses() === []
            && $this->constructor($class) instanceof Stmt\ClassMethod;
    }

    /**
     * @return list<Finding>
     */
    private function findingsForClass(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        $constructor = $this->constructor($class);
        if (!$constructor instanceof Stmt\ClassMethod) {
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
     * @return list<Expr\Assign>
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
     * @param array<string, true> $properties
     * @param array<string, true> $lateAssignments
     *
     * @return string|null Property name that can be promoted, or null when not eligible.
     */
    private function promotableProperty(
        Expr\Assign $assign,
        Stmt\ClassMethod $constructor,
        array $properties,
        array $lateAssignments,
    ): ?string {
        $property = ModernisationNodeHelper::propertyFetchName($assign->var);

        if (
            $property === null
            || !ModernisationNodeHelper::isThisPropertyFetch($assign->var)
            || !isset($properties[$property])
        ) {
            return null;
        }

        if (!$assign->expr instanceof Expr\Variable || $assign->expr->name !== $property) {
            return null;
        }

        if (isset($lateAssignments[$property]) || !$this->hasPlainConstructorParameter($constructor, $property)) {
            return null;
        }

        return $property;
    }

    /**
     * Return the class constructor when one is declared.
     *
     * @return Stmt\ClassMethod|null Constructor method, or null when absent.
     */
    private function constructor(Stmt\Class_ $class): ?Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $classMethod) {
            if (strtolower($classMethod->name->toString()) === '__construct') {
                return $classMethod;
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
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
     * @return array<string, true>
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

        return $assignments;
    }

    /**
     * Check whether the constructor has an unpromoted parameter matching the property.
     *
     * @return bool True when the matching parameter has no promotion flags.
     */
    private function hasPlainConstructorParameter(Stmt\ClassMethod $constructor, string $property): bool
    {
        foreach ($constructor->params as $parameter) {
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $property && $parameter->flags === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the finding for a promotable property assignment.
     *
     * @return Finding Constructor promotion finding.
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
                'property' => $property,
                'requiresPhp' => 8.0,
            ],
        );
    }
}
