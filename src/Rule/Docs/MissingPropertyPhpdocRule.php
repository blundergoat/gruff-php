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
use PhpParser\Node\Param;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;

/**
 * Detects declared and promoted properties that lack usable documentation.
 */
final readonly class MissingPropertyPhpdocRule implements RuleInterface
{
    /**
     * Stable rule identifier for missing property PHPDoc findings.
     */
    public const ID = 'docs.missing-property-phpdoc';

    /**
     * Describe the missing property PHPDoc rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Missing property PHPDoc',
            pillar:          Pillar::Documentation,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find declared and promoted properties that lack local documentation.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for undocumented properties.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $findings   = [];

        foreach ($nodeFinder->findInstanceOf($analysisUnit->statements, ClassLike::class) as $classLike) {
            if (!$this->isSupportedClassLike($classLike) || $classLike->name === null) {
                continue;
            }

            array_push(
                $findings,
                ...$this->declaredPropertyFindings($classLike, $classLike->name->toString(), $definition, $analysisUnit),
                ...$this->promotedPropertyFindings($classLike, $classLike->name->toString(), $definition, $analysisUnit),
            );
        }

        return $findings;
    }

    /**
     * Check whether a class-like node can own declared or promoted properties.
     *
     * @return bool True when the node should be inspected.
     */
    private function isSupportedClassLike(ClassLike $classLike): bool
    {
        return $classLike instanceof Class_
            || $classLike instanceof Trait_
            || $classLike instanceof Interface_
            || $classLike instanceof Enum_;
    }

    /**
     * Find declared properties without PHPDoc.
     *
     * @return list<Finding> Findings for undocumented declared properties.
     */
    private function declaredPropertyFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): array {
        $findings = [];

        foreach ($classLike->getProperties() as $property) {
            if ($property->getDocComment() !== null) {
                continue;
            }

            foreach ($property->props as $propertyProperty) {
                $findings[] = $this->declaredPropertyFinding(
                    propertyName: $propertyProperty->name->toString(),
                    className:    $className,
                    line:         $property->getStartLine(),
                    definition:   $definition,
                    analysisUnit:         $analysisUnit,
                );
            }
        }

        return $findings;
    }

    /**
     * Build one declared-property PHPDoc finding.
     *
     * @return Finding Finding for an undocumented declared property.
     */
    private function declaredPropertyFinding(
        string $propertyName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): Finding {
        $symbol = sprintf('%s::$%s', $className, $propertyName);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Property %s has no PHPDoc.', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Add a docblock describing the property\'s purpose or shape.',
            metadata:    [
                'propertyName' => $propertyName,
                'kind' => 'declared',
                'className' => $className,
            ],
        );
    }

    /**
     * Find promoted constructor properties without matching constructor `@param` docs.
     *
     * @return list<Finding> Findings for undocumented promoted properties.
     */
    private function promotedPropertyFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): array {
        $constructor = $this->findConstructor($classLike);
        if ($constructor === null) {
            return [];
        }

        $documentedParams = $this->documentedConstructorParams($constructor);
        $findings         = [];

        foreach ($constructor->params as $param) {
            $propertyName = $this->promotedPropertyName($param);
            if ($propertyName === null || in_array($propertyName, $documentedParams, true)) {
                continue;
            }

            $findings[] = $this->promotedPropertyFinding(
                propertyName: $propertyName,
                className:    $className,
                line:         $param->getStartLine(),
                definition:   $definition,
                analysisUnit:         $analysisUnit,
            );
        }

        return $findings;
    }

    /**
     * Return the property name for a promoted constructor parameter.
     *
     * @return string|null Property name, or null when the parameter is not promoted.
     */
    private function promotedPropertyName(Param $param): ?string
    {
        if ($param->flags === 0 || !$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        return $param->var->name;
    }

    /**
     * Build one promoted-property PHPDoc finding.
     *
     * @return Finding Finding for an undocumented promoted property.
     */
    private function promotedPropertyFinding(
        string $propertyName,
        string $className,
        int $line,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): Finding {
        $symbol = sprintf('%s::__construct($%s)', $className, $propertyName);

        return new Finding(
            ruleId:      $definition->id,
            message:     sprintf('Promoted property %s has no @param tag on the constructor.', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: sprintf('Add an @param tag for $%s to the constructor\'s docblock.', $propertyName),
            metadata:    [
                'propertyName' => $propertyName,
                'kind' => 'promoted',
                'className' => $className,
            ],
        );
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
