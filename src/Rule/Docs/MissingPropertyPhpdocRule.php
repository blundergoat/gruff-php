<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Docs;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

final readonly class MissingPropertyPhpdocRule implements RuleInterface
{
    public const ID = 'docs.missing-property-phpdoc';

    /**
     * Describe the missing property PHPDoc rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Missing property PHPDoc',
            pillar: Pillar::Documentation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Find declared and promoted properties that lack local documentation.
     *
     * @return list<Finding> Findings for undocumented properties.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, ClassLike::class) as $classLike) {
            if (!$classLike instanceof Class_
                && !$classLike instanceof Trait_
                && !$classLike instanceof Interface_
                && !$classLike instanceof Enum_) {
                continue;
            }

            if ($classLike->name === null) {
                continue;
            }

            $className = $classLike->name->toString();

            foreach ($classLike->getProperties() as $property) {
                if ($property->getDocComment() !== null) {
                    continue;
                }

                foreach ($property->props as $propertyProperty) {
                    $propertyName = $propertyProperty->name->toString();
                    $symbol = sprintf('%s::$%s', $className, $propertyName);

                    $findings[] = new Finding(
                        ruleId: $definition->id,
                        message: sprintf('Property %s has no PHPDoc.', $symbol),
                        filePath: $unit->file->displayPath,
                        line: $property->getStartLine(),
                        severity: $definition->defaultSeverity,
                        pillar: $definition->pillar,
                        tier: $definition->tier,
                        confidence: $definition->confidence,
                        symbol: $symbol,
                        remediation: 'Add a docblock describing the property\'s purpose or shape.',
                        metadata: [
                            'propertyName' => $propertyName,
                            'kind' => 'declared',
                            'className' => $className,
                        ],
                    );
                }
            }

            $constructor = $this->findConstructor($classLike);
            if ($constructor === null) {
                continue;
            }

            $documentedParams = $this->documentedConstructorParams($constructor);

            foreach ($constructor->params as $param) {
                if ($param->flags === 0) {
                    continue;
                }

                if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $propertyName = $param->var->name;
                if (in_array($propertyName, $documentedParams, true)) {
                    continue;
                }

                $symbol = sprintf('%s::__construct($%s)', $className, $propertyName);

                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('Promoted property %s has no @param tag on the constructor.', $symbol),
                    filePath: $unit->file->displayPath,
                    line: $param->getStartLine(),
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    symbol: $symbol,
                    remediation: sprintf('Add an @param tag for $%s to the constructor\'s docblock.', $propertyName),
                    metadata: [
                        'propertyName' => $propertyName,
                        'kind' => 'promoted',
                        'className' => $className,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * Return the constructor method for a class-like declaration.
     *
     * @return ClassMethod|null Constructor, or null when absent.
     */
    private function findConstructor(ClassLike $classLike): ?ClassMethod
    {
        foreach ($classLike->getMethods() as $method) {
            if (strtolower($method->name->toString()) === '__construct') {
                return $method;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function documentedConstructorParams(ClassMethod $constructor): array
    {
        $doc = $constructor->getDocComment();
        if ($doc === null) {
            return [];
        }

        return MissingParamTagRule::extractParamNames($doc->getText());
    }
}
