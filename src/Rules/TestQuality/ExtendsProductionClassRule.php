<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

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
use PhpParser\Node\Stmt;

/**
 * Flags a test class (`*Test`/`*Tests`) that extends a non-test base which is not a recognised `*TestCase`
 * - a sign the test inherits from a production class to reach its internals instead of exercising it through
 * its public surface. Runs per class; extra bases are configurable. Error severity, high confidence.
 */
final readonly class ExtendsProductionClassRule implements RuleInterface
{
    /**
     * Stable rule identifier for production inheritance findings.
     */
    public const ID = 'test-quality.extends-production-class';

    /**
     * Exact additional test base-class names accepted by default.
     *
     * Empty by default: the *TestCase suffix (underscores ignored) covers the
     * common shapes, and a project lists its own bases that match neither.
     *
     * @var list<string>
     */
    private const DEFAULT_ADDITIONAL_TEST_BASE_CLASSES = [];

    /**
     * Describes the test-extends-production-class rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Error: a test inheriting production internals couples to private state instead of the public surface.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Test extends production class',
            pillar:          Pillar::TestQuality,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Error,
            confidence:      Confidence::High,
            defaultOptions:  [
                'additionalTestBaseClasses' => self::DEFAULT_ADDITIONAL_TEST_BASE_CLASSES,
            ],
            optionDescriptions: [
                'additionalTestBaseClasses' => 'Exact class names accepted as test bases when they match neither *TestCase shape; compared case-insensitively against the parent short and fully qualified name.',
            ],
            falsePositiveShapes: [
                [
                    'shape' => 'Project test bases that do not end in *TestCase even after ignoring underscores (e.g. IntegrationTestBase).',
                    'mitigation' => 'Add the exact base class name to options.additionalTestBaseClasses.',
                ],
            ],
        );
    }

    /**
     * Reports test classes that inherit directly from production classes.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for tests extending production types.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $settings        = $ruleContext->settingsFor($this->definition());
        $additionalBases = array_map(
            static fn (string $name): string => strtolower(ltrim($name, '\\')),
            $settings->stringListOption('additionalTestBaseClasses'),
        );

        $findings = [];

        // Weigh every class declaration in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString();
            // Skip classes with no name or no parent to inherit from.
            if ($className === null || $class->extends === null) {
                continue;
            }

            // Only *Test / *Tests classes are test classes we judge.
            if (!str_ends_with($className, 'Test') && !str_ends_with($className, 'Tests')) {
                continue;
            }

            $parent      = $class->extends;
            $parentShort = strtolower($parent->getLast());

            // Drop underscores first so snake_case bases such as WC_Unit_Test_Case still spell *TestCase.
            if (str_ends_with(str_replace('_', '', $parentShort), 'testcase')) {
                continue;
            }

            if (in_array($parentShort, $additionalBases, true) || in_array(strtolower($parent->toString()), $additionalBases, true)) {
                // The project declared this exact base as a test base via additionalTestBaseClasses.
                continue;
            }

            $findings[] = new Finding(
                ruleId:  self::ID,
                message: sprintf(
                    '%s extends %s, which is not a recognised test base class.',
                    $className,
                    $parent->toString(),
                ),
                filePath:    $analysisUnit->file->displayPath,
                line:        $class->getStartLine(),
                severity:    Severity::Error,
                pillar:      Pillar::TestQuality,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                symbol:      $className,
                remediation: 'Test classes should extend a *TestCase base. If you need to reach private members, compose the production class as a collaborator and exercise it through its public surface. If the parent is a legitimate test base with another name, add it to `rules.test-quality.extends-production-class.options.additionalTestBaseClasses` in `.gruff-php.yaml`.',
                metadata:    ['parent' => $parent->toString()],
            );
        }

        return $findings;
    }
}
