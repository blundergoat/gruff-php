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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class ConstructorPromotionCandidateRule implements RuleInterface
{
    public const ID = 'modernisation.constructor-promotion-candidate';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Constructor property promotion candidate',
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (!ModernisationNodeHelper::supportsPhp($context, 8.0)) {
            return [];
        }

        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\Class_::class) as $class) {
            if ($class->extends !== null || $class->getTraitUses() !== []) {
                continue;
            }

            $constructor = $this->constructor($class);
            if (!$constructor instanceof Stmt\ClassMethod) {
                continue;
            }

            $properties = $this->declaredProperties($class);
            $lateAssignments = $this->lateAssignments($class);
            foreach ($constructor->stmts ?? [] as $statement) {
                if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign) {
                    continue;
                }

                $assign = $statement->expr;
                $property = ModernisationNodeHelper::propertyFetchName($assign->var);
                if (
                    $property === null
                    || !ModernisationNodeHelper::isThisPropertyFetch($assign->var)
                    || !isset($properties[$property])
                ) {
                    continue;
                }

                if (!$assign->expr instanceof Expr\Variable || $assign->expr->name !== $property) {
                    continue;
                }

                if (isset($lateAssignments[$property]) || !$this->constructorHasPlainParameter($constructor, $property)) {
                    continue;
                }

                $findings[] = $this->finding($unit, $assign, $property);
            }
        }

        return $findings;
    }

    private function constructor(Stmt\Class_ $class): ?Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) === '__construct') {
                return $method;
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

    private function constructorHasPlainParameter(Stmt\ClassMethod $constructor, string $property): bool
    {
        foreach ($constructor->params as $parameter) {
            if ($parameter->var instanceof Expr\Variable && $parameter->var->name === $property && $parameter->flags === 0) {
                return true;
            }
        }

        return false;
    }

    private function finding(AnalysisUnit $unit, Node $node, string $property): Finding
    {
        return new Finding(
            ruleId: self::ID,
            message: sprintf('Property $%s is assigned directly from the same constructor parameter; PHP 8 property promotion may reduce boilerplate.', $property),
            filePath: $unit->file->displayPath,
            line: $node->getStartLine(),
            severity: Severity::Advisory,
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            confidence: Confidence::Medium,
            remediation: 'Consider constructor property promotion after confirming the rewrite preserves constructor semantics; gruff-php reports only.',
            metadata: [
                'property' => $property,
                'requiresPhp' => 8.0,
            ],
        );
    }
}
