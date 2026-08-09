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
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Flags a public mutable property that lets any caller read and overwrite object state directly, so the
 * user can move to readonly properties or accessor methods that keep the class's invariants intact.
 *
 * Runs per file over every class, skipping readonly owners and DTO-style data carriers. Readonly owners
 * cannot expose mutable properties, while DTO public state is intentional. Each remaining public,
 * non-static, non-readonly declared or promoted property is reported at warning - gruff-php only surfaces
 * it, it never rewrites the property for you.
 */
final readonly class PublicPropertyRule implements RuleInterface
{
    /**
     * Stable rule identifier for public property findings.
     */
    public const ID = 'modernisation.public-property';

    /**
     * Describes the public-property modernisation rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults (warning severity, high confidence).
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:                 self::ID,
            name:               'Public mutable property',
            pillar:             Pillar::Modernisation,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Warning,
            confidence:         Confidence::High,
            defaultOptions:     ['allowedClasses' => []],
            optionDescriptions: [
                'allowedClasses' => 'Fully qualified classes whose intentionally mutable public state is an explicit lifecycle or integration contract.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Internal lifecycle containers that expose read-mostly parsed state but deliberately clear it after analysis to release memory.',
                    'mitigation' => 'Add the exact fully qualified class to options.allowedClasses after verifying external mutation is part of the intended contract.',
                ],
            ],
        );
    }

    /**
     * Reports each public mutable declared or promoted property that exposes overwritable state.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext  - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per exposed public mutable property; empty when every class guards its state.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings       = $ruleContext->settingsFor($this->definition());
        $allowedClasses = $this->normalizedClassSet($settings->stringListOption('allowedClasses'));
        $findings       = [];

        // Inspect every class declared in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $className = $this->resolvedClassName($class);

            // Readonly classes cannot expose mutable instance state, while DTO public fields are intentional.
            if (
                $class->isReadonly()
                || ModernisationNodeHelper::isDtoClass($class)
                || ($className !== null && isset($allowedClasses[strtolower($className)]))
            ) {
                continue;
            }

            array_push(
                $findings,
                ...$this->declaredPropertyFindings($analysisUnit, $class),
                ...$this->promotedPropertyFindings($analysisUnit, $class),
            );
        }

        return $findings;
    }

    /**
     * Normalizes configured class names into an exact case-insensitive lookup set.
     *
     * @param list<string> $classNames - Fully qualified class names supplied by rule config.
     *
     * @return array<string, true> - Lowercase class names without a leading namespace separator.
     */
    private function normalizedClassSet(array $classNames): array
    {
        $normalized = [];

        foreach ($classNames as $className) {
            $className = strtolower(ltrim(trim($className), '\\'));
            if ($className !== '') {
                $normalized[$className] = true;
            }
        }

        return $normalized;
    }

    /**
     * Resolves a declaration to the exact name configured by a consumer.
     *
     * @param Stmt\Class_ $class - Named or anonymous class declaration.
     *
     * @return string|null - Fully qualified name without a leading separator, or null for anonymous classes.
     */
    private function resolvedClassName(Stmt\Class_ $class): ?string
    {
        $namespacedName = $class->namespacedName ?? null;
        if ($namespacedName instanceof Node\Name) {
            return ltrim($namespacedName->toString(), '\\');
        }

        return $class->name?->toString();
    }

    /**
     * Reports mutable public properties declared with property statements.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit providing the reported display path.
     * @param Stmt\Class_  $class        - Non-readonly, non-DTO class whose declarations are inspected.
     *
     * @return list<Finding> - One finding per public mutable declared property.
     */
    private function declaredPropertyFindings(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        $findings = [];

        // Check each property the class declares.
        foreach ($class->getProperties() as $property) {
            // Only a plain public, non-static, non-readonly property exposes overwritable state.
            if (!$property->isPublic() || $property->isStatic() || $property->isReadonly()) {
                continue;
            }

            // One declaration can name several properties, so report each name separately.
            foreach ($property->props as $propertyProperty) {
                $findings[] = $this->finding($analysisUnit, $propertyProperty, $propertyProperty->name->toString());
            }
        }

        return $findings;
    }

    /**
     * Reports mutable public properties promoted by a constructor.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit providing the reported display path.
     * @param Stmt\Class_  $class        - Non-readonly, non-DTO class whose constructor is inspected.
     *
     * @return list<Finding> - One finding per public mutable promoted property.
     */
    private function promotedPropertyFindings(AnalysisUnit $analysisUnit, Stmt\Class_ $class): array
    {
        // Constructor-promoted properties are parameter nodes, not property statements.
        $constructor = $class->getMethod('__construct');
        if ($constructor === null) {
            return [];
        }

        $findings = [];

        foreach ($constructor->params as $parameter) {
            // Only a public mutable promotion exposes writable object state.
            if (!$parameter->isPromoted() || !$parameter->isPublic() || $parameter->isReadonly()) {
                continue;
            }

            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $findings[] = $this->finding($analysisUnit, $parameter, $parameter->var->name);
        }

        return $findings;
    }

    /**
     * Builds a public-property finding for one mutable declaration or promoted parameter.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit providing the reported display path.
     * @param Node         $node         - Property node whose start line anchors the finding.
     * @param string       $name         - Property name used by the message and metadata.
     *
     * @return Finding - Fixed-shape warning for one exposed mutable property.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $name): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('Public mutable property $%s exposes state directly.', $name),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Modernisation,
            tier:        RuleTier::V01,
            confidence:  Confidence::High,
            remediation: 'Prefer constructor-initialized readonly properties or methods that preserve invariants. If public mutation is an intentional lifecycle contract, add the fully qualified class to `rules.modernisation.public-property.options.allowedClasses`.',
            metadata:    [
                'property' => $name,
            ],
        );
    }
}
