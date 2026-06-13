<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Docs;

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
use PhpParser\Node\Param;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;

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
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Advisory by default so property-doc enforcement is opt-in; Medium confidence because a missing
        // local docblock is unambiguous but the value of documenting trivial properties is team-dependent.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for undocumented properties.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        foreach (NodeIndex::nodesOf($analysisUnit, ClassLike::class) as $classLike) {
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
     * @param ClassLike $classLike - class-like node from the parsed unit; the caller must still
     *   guard `$classLike->name` separately, since anonymous classes are supported but unnamed.
     *
     * @return bool - True when the node should be inspected.
     */
    private function isSupportedClassLike(ClassLike $classLike): bool
    {
        // Only these four kinds can declare properties or a promoting constructor; other ClassLike
        // subtypes (none today, but future parser additions) are skipped rather than mis-inspected.
        return $classLike instanceof Class_
            || $classLike instanceof Trait_
            || $classLike instanceof Interface_
            || $classLike instanceof Enum_;
    }

    /**
     * Find declared properties without PHPDoc.
     *
     * @param ClassLike      $classLike    - node whose `getProperties()` declarations are scanned; a
     *   docblock on the `Property` statement (not the individual prop) is what suppresses the finding.
     * @param string         $className    - short class name the caller already resolved, used to build
     *   the `Class::$prop` symbol; passed in so this method does not re-null-check `$classLike->name`.
     * @param RuleDefinition $definition   - this rule's metadata, copied into each emitted finding so
     *   severity and pillar stay consistent without re-deriving them per property.
     * @param AnalysisUnit   $analysisUnit - parsed unit supplying the display path recorded on findings.
     *
     * @return list<Finding> - Findings for undocumented declared properties.
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
                    analysisUnit: $analysisUnit,
                );
            }
        }

        return $findings;
    }

    /**
     * Build one declared-property PHPDoc finding.
     *
     * @param string         $propertyName - bare property name without the leading `$`; combined with
     *   `$className` to form the `Class::$prop` symbol shown to the reviewer.
     * @param string         $className    - short class name owning the property, for the same symbol.
     * @param int            $line         - 1-based start line of the property statement, so the finding
     *   anchors at the declaration the author must annotate, not at the individual prop expression.
     * @param RuleDefinition $definition   - rule metadata source for the finding's id, severity, pillar,
     *   tier, and confidence.
     * @param AnalysisUnit   $analysisUnit - parsed unit supplying the display path recorded on the finding.
     *
     * @return Finding - Finding for an undocumented declared property.
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
            message:     sprintf('Property %s needs a brief intent description above its declaration (one plain-English line; not a restatement of the type).', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: 'Add a one-line `/** Description. */` block above the property. This rule wants content, not boilerplate - the description should answer "what does this property hold, what invariant does it maintain."',
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
     * @param ClassLike      $classLike    - node whose constructor is searched for promoted parameters;
     *   a promoted param is documented (and so suppressed) by a constructor `@param` of the same name.
     * @param string         $className    - short class name used to build the `Class::__construct($x)`
     *   symbol; passed in so this method need not re-resolve `$classLike->name`.
     * @param RuleDefinition $definition   - rule metadata copied into each emitted finding.
     * @param AnalysisUnit   $analysisUnit - parsed unit supplying the display path recorded on findings.
     *
     * @return list<Finding> - Findings for undocumented promoted properties.
     */
    private function promotedPropertyFindings(
        ClassLike $classLike,
        string $className,
        RuleDefinition $definition,
        AnalysisUnit $analysisUnit,
    ): array {
        $constructor = $this->findConstructor($classLike);
        if ($constructor === null) {
            // No constructor means no promoted properties to check, so report nothing for this class.
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
                analysisUnit: $analysisUnit,
            );
        }

        return $findings;
    }

    /**
     * Return the property name for a promoted constructor parameter.
     *
     * @param Param $param - a single constructor parameter node; treated as promoted only when it carries
     *   visibility flags (`public`/`protected`/`private`), which is what turns a param into a property.
     *
     * @return string|null - Property name, or null when the parameter is not promoted.
     */
    private function promotedPropertyName(Param $param): ?string
    {
        if ($param->flags === 0 || !$param->var instanceof Variable || !is_string($param->var->name)) {
            // Not promoted (no visibility flags) or a variadic/destructured form without a plain name, so
            // there is no property to attribute; null signals "skip", an expected miss rather than an error.
            return null;
        }

        return $param->var->name;
    }

    /**
     * Build one promoted-property PHPDoc finding.
     *
     * @param string         $propertyName - bare promoted-parameter name without the leading `$`, used in
     *   the `Class::__construct($prop)` symbol and the remediation text.
     * @param string         $className    - short class name owning the constructor, for the same symbol.
     * @param int            $line         - 1-based start line of the promoted parameter, so the finding
     *   anchors on the constructor signature where the `@param` tag must be added.
     * @param RuleDefinition $definition   - rule metadata source for the finding's id, severity, pillar,
     *   tier, and confidence.
     * @param AnalysisUnit   $analysisUnit - parsed unit supplying the display path recorded on the finding.
     *
     * @return Finding - Finding for an undocumented promoted property.
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
            message:     sprintf('Promoted property %s needs an @param tag on the constructor with a brief description (one plain-English clause; not a restatement of the type).', $symbol),
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    $definition->defaultSeverity,
            pillar:      $definition->pillar,
            tier:        $definition->tier,
            confidence:  $definition->confidence,
            symbol:      $symbol,
            remediation: sprintf('Add an `@param SomeType $%s Description.` tag to the constructor\'s docblock. This rule wants content, not boilerplate - the description should answer "what does the caller need to satisfy for this promoted property."', $propertyName),
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
     * @param ClassLike $classLike - node whose direct methods are scanned; inherited constructors are not
     *   resolved, since promotion only annotates the constructor physically declared in this class body.
     *
     * @return ClassMethod|null - Constructor, or null when absent.
     */
    private function findConstructor(ClassLike $classLike): ?ClassMethod
    {
        foreach ($classLike->getMethods() as $method) {
            if (strtolower($method->name->toString()) === '__construct') {
                // Match case-insensitively because PHP method names are case-insensitive; hand back the
                // first `__construct` found, which is the only constructor a valid class can declare.
                return $method;
            }
        }

        return null;
    }

    /**
     * List promoted constructor parameters that already have property documentation.
     *
     * @param ClassMethod $constructor - the constructor whose docblock `@param` tags are read; only its
     *   own docblock counts, so a param documented on a parent constructor is not considered covered here.
     *
     * @return list<string> - Names (without `$`) of parameters the constructor docblock already documents;
     *   empty when the constructor has no docblock, which the caller reads as "nothing documented yet".
     */
    private function documentedConstructorParams(ClassMethod $constructor): array
    {
        $doc = $constructor->getDocComment();
        if ($doc === null) {
            // No docblock means no `@param` tags, so every promoted property is treated as undocumented.
            return [];
        }

        // Reuse the shared `@param` parser so promoted-property coverage matches exactly what the
        // missing-param-tag rule recognises, keeping the two documentation rules consistent.
        return MissingParamTagRule::extractParamNames($doc->getText());
    }
}
