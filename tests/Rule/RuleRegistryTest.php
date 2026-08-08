<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use Closure;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSelection;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Complexity\CognitiveComplexityRule;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Complexity\HalsteadVolumeRule;
use GruffPhp\Rules\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rules\Complexity\NestingDepthRule;
use GruffPhp\Rules\DeadCode\UnusedPrivateConstantRule;
use GruffPhp\Rules\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rules\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rules\Modernisation\ConstructorPromotionCandidateRule;
use GruffPhp\Rules\Modernisation\FirstClassCallableCandidateRule;
use GruffPhp\Rules\Modernisation\ForbiddenGlobalAccessRule;
use GruffPhp\Rules\Modernisation\MatchExpressionCandidateRule;
use GruffPhp\Rules\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rules\Modernisation\NamedArgumentOpportunityRule;
use GruffPhp\Rules\Modernisation\PublicPropertyRule;
use GruffPhp\Rules\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rules\Naming\AbbreviationAllowlistRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Naming\NegativeBooleanRule;
use GruffPhp\Rules\Naming\SuffixHungarianRule;
use GruffPhp\Rules\Shared\ProjectRuleAccumulator;
use GruffPhp\Rules\Contracts\ProjectRuleInterface;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use GruffPhp\Rules\Security\DebugModeEnabledRule;
use GruffPhp\Rules\Security\DependencyComposerPathRule;
use GruffPhp\Rules\Security\DependencyComposerScriptRule;
use GruffPhp\Rules\Security\DependencyComposerUnpinnedRule;
use GruffPhp\Rules\Security\DependencyComposerVcsRule;
use GruffPhp\Rules\Security\DisabledSslVerificationRule;
use GruffPhp\Rules\Security\ErrorSuppressionRule;
use GruffPhp\Rules\Security\ExtractCompactUserInputRule;
use GruffPhp\Rules\Security\GithubActionsRiskyWorkflowRule;
use GruffPhp\Rules\Security\HeaderInjectionRule;
use GruffPhp\Rules\Security\InsecureRandomRule;
use GruffPhp\Rules\Security\PathTraversalFileAccessRule;
use GruffPhp\Rules\Security\PermissiveCorsRule;
use GruffPhp\Rules\Security\ProcessCommandConstructionRule;
use GruffPhp\Rules\Security\ReflectedXssRule;
use GruffPhp\Rules\Security\RequestControlledUrlRule;
use GruffPhp\Rules\Security\SensitiveDataLoggingRule;
use GruffPhp\Rules\Security\SilentCatchRule;
use GruffPhp\Rules\Security\SqlConcatenationRule;
use GruffPhp\Rules\Security\UnsafeArchiveExtractionRule;
use GruffPhp\Rules\Security\UnsafeXmlLoadingRule;
use GruffPhp\Rules\Security\UnsafeUnserializeRule;
use GruffPhp\Rules\Security\VariableIncludeRule;
use GruffPhp\Rules\Security\WeakCryptoRule;
use GruffPhp\Rules\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rules\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rules\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rules\SensitiveData\GcpServiceAccountKeyRule;
use GruffPhp\Rules\SensitiveData\HardcodedEnvValueRule;
use GruffPhp\Rules\SensitiveData\HighEntropyStringRule;
use GruffPhp\Rules\SensitiveData\JwtTokenRule;
use GruffPhp\Rules\SensitiveData\PhiPatternRule;
use GruffPhp\Rules\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rules\SensitiveData\PrivateKeyRule;
use GruffPhp\Rules\SensitiveData\UrlEmbeddedCredentialsRule;
use GruffPhp\Rules\Size\AverageMethodLengthRule;
use GruffPhp\Rules\Size\ClassLengthRule;
use GruffPhp\Rules\Size\FileLengthRule;
use GruffPhp\Rules\Size\MethodLengthRule;
use GruffPhp\Rules\Size\ParameterCountRule;
use GruffPhp\Rules\Size\PropertyCountRule;
use GruffPhp\Rules\Size\PublicMethodCountRule;
use GruffPhp\Rules\TestQuality\ConditionalTestLogicRule;
use GruffPhp\Rules\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rules\TestQuality\EagerTestRule;
use GruffPhp\Rules\TestQuality\ExcessiveMockingRule;
use GruffPhp\Rules\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rules\TestQuality\MockOnlyTestRule;
use GruffPhp\Rules\TestQuality\MysteryGuestRule;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use GruffPhp\Rules\TestQuality\PrivateReflectionRule;
use GruffPhp\Rules\TestQuality\SetupBloatRule;
use GruffPhp\Rules\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rules\TestQuality\SleepInTestRule;
use GruffPhp\Rules\TestQuality\StaticAnalysisRedundantTestRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rules\TestQuality\TrivialAssertionRule;
use GruffPhp\Rules\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rules\Waste\CommentedOutCodeRule;
use GruffPhp\Rules\Waste\EmptyClassRule;
use GruffPhp\Rules\Waste\EmptyMethodRule;
use GruffPhp\Rules\Waste\OneLineMethodRule;
use GruffPhp\Rules\Waste\UnreachableCodeRule;
use GruffPhp\Rules\Waste\UnusedImportRule;
use GruffPhp\Rules\Waste\UnusedParameterRule;
use GruffPhp\Engine\Source\SourceFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the default rule registry: stable rule IDs and definitions, enabled-rule execution, disabled-rule skipping, project-level finding
 * deduplication, duplicate-ID rejection, and listable descriptions.
 */
final class RuleRegistryTest extends TestCase
{
    /**
     * Verify default registry contains stable rule ids.
     *
     * @return void
     */
    public function testDefaultRegistryContainsStableRuleIds(): void
    {
        $registry        = RuleRegistry::defaults();
        $expectedRuleIds = [
            CognitiveComplexityRule::ID, CyclomaticComplexityRule::ID,
            HalsteadVolumeRule::ID, MaintainabilityIndexRule::ID,
            NestingDepthRule::ID,
            UnusedPrivateConstantRule::ID,
            UnusedPrivateMethodRule::ID, UnusedPrivatePropertyRule::ID,
            CommentedOutCodeRule::ID, EmptyClassRule::ID,
            EmptyMethodRule::ID, OneLineMethodRule::ID,
            UnreachableCodeRule::ID, UnusedImportRule::ID,
            UnusedParameterRule::ID, AbbreviationAllowlistRule::ID,
            IdentifierQualityRule::ID, NegativeBooleanRule::ID,
            SuffixHungarianRule::ID,
            ConstructorPromotionCandidateRule::ID,
            FirstClassCallableCandidateRule::ID, ForbiddenGlobalAccessRule::ID,
            MatchExpressionCandidateRule::ID, MixedTypeOveruseRule::ID,
            NamedArgumentOpportunityRule::ID, PublicPropertyRule::ID,
            ReadonlyPropertyCandidateRule::ID, ApiKeyPatternRule::ID,
            AwsAccessKeyRule::ID, DatabaseUrlPasswordRule::ID,
            GcpServiceAccountKeyRule::ID,
            HardcodedEnvValueRule::ID, HighEntropyStringRule::ID,
            JwtTokenRule::ID, PhiPatternRule::ID,
            PiiTestFixtureRule::ID, PrivateKeyRule::ID,
            UrlEmbeddedCredentialsRule::ID,
            DangerousFunctionCallRule::ID, DebugModeEnabledRule::ID,
            DependencyComposerPathRule::ID, DependencyComposerScriptRule::ID,
            DependencyComposerUnpinnedRule::ID, DependencyComposerVcsRule::ID,
            DisabledSslVerificationRule::ID,
            ErrorSuppressionRule::ID, ExtractCompactUserInputRule::ID,
            GithubActionsRiskyWorkflowRule::ID, HeaderInjectionRule::ID,
            InsecureRandomRule::ID, PathTraversalFileAccessRule::ID,
            PermissiveCorsRule::ID,
            ProcessCommandConstructionRule::ID, ReflectedXssRule::ID,
            RequestControlledUrlRule::ID,
            SensitiveDataLoggingRule::ID, SilentCatchRule::ID, SqlConcatenationRule::ID,
            UnsafeArchiveExtractionRule::ID, UnsafeXmlLoadingRule::ID,
            UnsafeUnserializeRule::ID, VariableIncludeRule::ID,
            WeakCryptoRule::ID, ConditionalTestLogicRule::ID,
            DataProviderAnnotationRule::ID, EagerTestRule::ID,
            ExcessiveMockingRule::ID, MagicNumberAssertionRule::ID,
            MockOnlyTestRule::ID, MysteryGuestRule::ID, NoAssertionsRule::ID,
            PrivateReflectionRule::ID, SetupBloatRule::ID,
            SkippedWithoutReasonRule::ID, SleepInTestRule::ID,
            StaticAnalysisRedundantTestRule::ID,
            SutNotCalledRule::ID, TestLongerThanSutRule::ID,
            TestNamingConsistencyRule::ID, TrivialAssertionRule::ID,
            TrivialSnapshotRule::ID, AverageMethodLengthRule::ID,
            ClassLengthRule::ID, FileLengthRule::ID,
            MethodLengthRule::ID, ParameterCountRule::ID,
            PropertyCountRule::ID, PublicMethodCountRule::ID,
        ];
        $missingRuleIds = array_values(array_filter(
                                            $expectedRuleIds,
                                            static fn(string $ruleId): bool => !$registry->has($ruleId),
                                        ));

        self::assertSame([], $missingRuleIds);
    }

    /**
     * Verify runs enabled rules over parsed files.
     *
     * @return void
     */
    public function testRunsEnabledRulesOverParsedFiles(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            FileLengthRule::ID,
            new RuleSettings(true, ['warning' => 3, 'error' => 999]),
        );

        $allFindings = $registry->analyse(
            [$this->parseFixture('tests/Fixtures/Source/mixed/alpha.php')],
            new RuleContext(__DIR__ . '/../..', $config),
        );

        $findings = array_values(array_filter($allFindings, static fn($finding) => $finding->ruleId === FileLengthRule::ID));

        self::assertCount(1, $findings);
        self::assertSame(FileLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(Pillar::Size, $findings[0]->pillar);
        // 11 substantive lines: the fixture's blank and comment-only lines are free under file-length.
        self::assertSame(['lines' => 11, 'threshold' => 3, 'thresholdType' => 'warning'], $findings[0]->metadata);
    }

    /**
     * Verify skips disabled rules.
     *
     * @return void
     */
    public function testSkipsDisabledRules(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            FileLengthRule::ID,
            new RuleSettings(false, ['warning' => 3, 'error' => 999]),
        );

        $allFindings = $registry->analyse(
            [$this->parseFixture('tests/Fixtures/Source/mixed/alpha.php')],
            new RuleContext(__DIR__ . '/../..', $config),
        );

        $fileLengthFindings = array_filter($allFindings, static fn($finding) => $finding->ruleId === FileLengthRule::ID);
        self::assertSame([], array_values($fileLengthFindings));
    }

    /**
     * Verify deduplicates project level findings across units.
     *
     * @return void
     */
    public function testDeduplicatesProjectLevelFindingsAcrossUnits(): void
    {
        $ruleRegistry = new RuleRegistry([$this->duplicateProjectRule()]);
        $config       = AnalysisConfig::fromRegistry($ruleRegistry);

        $findings = $ruleRegistry->analyse(
            [
                $this->parseFixture('tests/Fixtures/Source/mixed/alpha.php'),
                $this->parseFixture('tests/Fixtures/Source/mixed/nested/beta.php'),
            ],
            new RuleContext(__DIR__ . '/../..', $config),
        );

        self::assertCount(1, $findings);
        self::assertSame('test.project-level', $findings[0]->ruleId);
        self::assertSame('README.md', $findings[0]->filePath);
    }

    /**
     * Verify the project-rule accumulator seam observes full project context while per-unit analysis stays scoped.
     *
     * @return void
     */
    public function testProjectRuleAccumulatorSeamObservesFullProjectContext(): void
    {
        $ruleRegistry = new RuleRegistry([$this->accumulatingProjectRule()]);
        $config       = AnalysisConfig::fromRegistry($ruleRegistry);
        $alphaUnit    = $this->parseFixture('tests/Fixtures/Source/mixed/alpha.php');
        $betaUnit     = $this->parseFixture('tests/Fixtures/Source/mixed/nested/beta.php');

        self::assertTrue($ruleRegistry->hasEnabledProjectRules($config));
        self::assertSame(['test.accumulating-project-rule'], $ruleRegistry->enabledProjectRuleIds($config));

        $findings = $ruleRegistry->analyse(
            [$alphaUnit],
            new RuleContext(__DIR__ . '/../..', $config),
            [$alphaUnit, $betaUnit],
        );

        self::assertCount(1, $findings);
        self::assertSame('test.accumulating-project-rule', $findings[0]->ruleId);
        self::assertSame(
            'Accumulated: tests/Fixtures/Source/mixed/alpha.php, tests/Fixtures/Source/mixed/nested/beta.php.',
            $findings[0]->message,
        );
    }

    /**
     * Verify rejects duplicate rule ids.
     *
     * @return void
     */
    public function testRejectsDuplicateRuleIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate rule id "test.duplicate".');

        new RuleRegistry([
                             $this->fakeRule('test.duplicate'),
                             $this->fakeRule('test.duplicate'),
                         ]);
    }

    /**
     * Verify default rules have listable descriptions.
     *
     * @return void
     */
    public function testDefaultRulesHaveListableDescriptions(): void
    {
        $missingDescriptionIds = array_values(array_map(
                                                  static fn($rule): string => $rule->definition()->id,
                                                  array_filter(
                                                      RuleRegistry::defaults()->all(),
                                                      static fn($rule): bool => trim($rule->definition()->description()) === '',
                                                  ),
                                              ));

        self::assertSame([], $missingDescriptionIds);
    }

    /**
     * Verify default rule definitions keep stable reporting and config metadata.
     *
     * @return void
     */
    public function testDefaultRuleDefinitionsStayStable(): void
    {
        $definitions = array_map(static function ($rule): array {
            $definition = $rule->definition();

            $single = $definition->severityThreshold;

            // The hash asserts the listable definition surface, not object identity.
            return [
                'id' => $definition->id,
                'name' => $definition->name,
                'description' => $definition->description(),
                'pillar' => $definition->pillar->value,
                'secondaryPillars' => array_map(static fn(Pillar $pillar): string => $pillar->value, $definition->secondaryPillars),
                'tier' => $definition->tier->value,
                'defaultSeverity' => $definition->defaultSeverity->value,
                'confidence' => $definition->confidence->value,
                'defaultThresholds' => $definition->defaultThresholds,
                'defaultSeverityThreshold' => $single === null
                    ? null
                    : ['threshold' => $single->threshold, 'severity' => $single->severity->value],
                'defaultEnabled' => $definition->isEnabledByDefault,
                'defaultOptions' => $definition->defaultOptions,
            ];
        }, RuleRegistry::defaults()->all());

        usort($definitions, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
        $json = json_encode($definitions, JSON_THROW_ON_ERROR);

        self::assertCount(128, $definitions);
        self::assertSame(
            '728f07a0d4013c3c025b' . 'b96f275ca069f32323c163b424ae1e5624dad6917c61',
            hash('sha256', $json),
        );
    }

    /**
     * Verify repeated enabledRules() calls with the same config reuse the memoised set without re-reading definitions.
     *
     * @return void
     */
    public function testEnabledRulesAreMemoisedPerConfigObject(): void
    {
        $definitionCalls = 0;
        $countingRule    = $this->definitionCountingRule(static function () use (&$definitionCalls): void {
            $definitionCalls++;
        });
        $registry    = new RuleRegistry([$countingRule]);
        $config      = AnalysisConfig::fromRegistry($registry);
        $callsBefore = $definitionCalls;

        $firstResolution  = $registry->enabledRules($config);
        $secondResolution = $registry->enabledRules($config);

        self::assertSame([$countingRule], $firstResolution);
        self::assertSame($firstResolution, $secondResolution);
        self::assertSame(
            $callsBefore,
            $definitionCalls,
            'enabledRules() must not re-invoke definition(); the registry snapshots definitions at construction',
        );
    }

    /**
     * Verify the memoisation is keyed by config identity, so distinct configs resolve distinct rule sets.
     *
     * @return void
     */
    public function testDifferentConfigObjectsResolveDifferentEnabledRuleSets(): void
    {
        $registry       = RuleRegistry::defaults();
        $fullConfig     = AnalysisConfig::fromRegistry($registry);
        $narrowedConfig = $fullConfig->withRuleSelection(new RuleSelection(excludeRules: [FileLengthRule::ID]));

        $fullRules     = $registry->enabledRules($fullConfig);
        $narrowedRules = $registry->enabledRules($narrowedConfig);
        $fullRuleIds   = array_map(static fn($rule): string => $rule->definition()->id, $fullRules);
        $narrowedIds   = array_map(static fn($rule): string => $rule->definition()->id, $narrowedRules);

        self::assertContains(FileLengthRule::ID, $fullRuleIds);
        self::assertNotContains(FileLengthRule::ID, $narrowedIds);
        self::assertSame($fullRules, $registry->enabledRules($fullConfig), 'the narrowed config must not poison the full config cache entry');
    }

    /**
     * Verify class-length counts substantive lines: docblock padding is free, code still fires.
     *
     * @return void
     */
    public function testClassLengthCountsSubstantiveLinesOnly(): void
    {
        $docHeavy = "<?php\nfinal class DocHeavy\n{\n";
        for ($index = 0; $index < 30; ++$index) {
            $docHeavy .= "    /** doc line {$index} */\n";
        }
        $docHeavy .= "    public int \$value = 1;\n}\n";

        $codeHeavy = "<?php\nfinal class CodeHeavy\n{\n";
        for ($index = 0; $index < 30; ++$index) {
            $codeHeavy .= "    public int \$value{$index} = {$index};\n";
        }
        $codeHeavy .= "}\n";

        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ClassLengthRule::ID,
            new RuleSettings(true, ['warning' => 20, 'error' => 999]),
        );
        $ruleContext = new RuleContext(__DIR__ . '/../..', $config);
        $rule        = new ClassLengthRule();

        // Thirty docblock lines are free, so the doc-heavy class stays far under the 20-line budget.
        $docFindings = $rule->analyse($this->parseInline($docHeavy), $ruleContext);
        self::assertSame([], $docFindings);

        // Thirty property lines are substantive, so the code-heavy class still reports.
        $codeFindings = $rule->analyse($this->parseInline($codeHeavy), $ruleContext);
        self::assertCount(1, $codeFindings);
        self::assertSame(33, $codeFindings[0]->metadata['lines']);
    }

    /**
     * Parses an inline source string through the real parser via a temporary file.
     *
     * @param string $source - PHP source text to parse.
     *
     * @return AnalysisUnit - Parsed unit for direct rule invocation.
     */
    private function parseInline(string $source): AnalysisUnit
    {
        // tempnam() never appends a suffix, so renaming keeps one file on disk instead of
        // stranding the extension-less original in the temp directory on every call.
        $reservedPath = tempnam(sys_get_temp_dir(), 'gruff-php-inline-');
        $path         = $reservedPath . '.php';
        rename($reservedPath, $path);
        file_put_contents($path, $source);

        try {
            return (new PhpFileParser())->parse(new SourceFile($path, 'inline.php'));
        } finally {
            unlink($path);
        }
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $displayPath - Fixture display path.
     *
     * @return AnalysisUnit - the parsed fixture ready for rule analysis
     */
    private function parseFixture(string $displayPath): AnalysisUnit
    {
        $absolutePath = __DIR__ . '/../..' . '/' . $displayPath;

        return (new PhpFileParser())->parse(new SourceFile($absolutePath, $displayPath));
    }

    /**
     * Build a fixture rule that reports every definition() invocation through the given callback.
     *
     * @param Closure $onDefinitionCall - Invoked once per definition() call, before the definition is built.
     *
     * @return RuleInterface - an anonymous findings-free rule whose definition() calls are observable
     */
    private function definitionCountingRule(Closure $onDefinitionCall): RuleInterface
    {
        return new readonly class ($onDefinitionCall) implements RuleInterface {
            /**
             * Build the counting fixture rule.
             *
             * @param Closure $onDefinitionCall - Invoked once per definition() call.
             */
            public function __construct(private Closure $onDefinitionCall)
            {
            }

            /**
             * Return metadata for the fixture rule, reporting the invocation first.
             *
             * @return RuleDefinition - the counting fixture's deterministic identity and reporting metadata
             */
            public function definition(): RuleDefinition
            {
                ($this->onDefinitionCall)();

                return new RuleDefinition(
                    id:              'test.definition-counting',
                    name:            'Definition counting fixture',
                    pillar:          Pillar::Maintainability,
                    tier:            RuleTier::V01,
                    defaultSeverity: Severity::Advisory,
                    confidence:      Confidence::Low,
                );
            }

            /**
             * Return findings produced by the fixture rule.
             *
             * @param AnalysisUnit $analysisUnit - Analysis unit.
             * @param RuleContext  $ruleContext  - Rule context for the fixture.
             *
             * @return list<\GruffPhp\Results\Finding\Finding> - always empty; this fixture only observes definition() calls
             */
            public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
            {
                return [];
            }
        };
    }

    /**
     * Build a fixture rule with the requested identifier.
     *
     * @param string $id - Rule identifier.
     *
     * @return RuleInterface - an anonymous rule emitting one finding under the given id
     */
    private function fakeRule(string $id): RuleInterface
    {
        return new readonly class ($id) implements RuleInterface {
            /**
             * Build the anonymous fixture rule.
             *
             * @param string $id - Rule identifier.
             */
            public function __construct(private string $id)
            {
            }

            /**
             * Return metadata for the fixture rule.
             *
             * @return RuleDefinition - the fixture's deterministic identity and reporting metadata
             */
            public function definition(): RuleDefinition
            {
                return new RuleDefinition(
                    id:              $this->id,
                    name:            'Fake rule',
                    pillar:          Pillar::Maintainability,
                    tier:            RuleTier::V01,
                    defaultSeverity: Severity::Advisory,
                    confidence:      Confidence::Low,
                );
            }

            /**
             * Return findings produced by the fixture rule.
             *
             * @param AnalysisUnit $analysisUnit - Analysis unit.
             * @param RuleContext  $ruleContext  - Rule context for the fixture.
             *
             * @return list<\GruffPhp\Results\Finding\Finding> - exactly one synthetic finding tagged with this rule's id, per unit
             */
            public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
            {
                return [
                    new Finding(
                        ruleId:     $this->id,
                        message:    'Fake finding.',
                        filePath:   $analysisUnit->file->displayPath,
                        line:       1,
                        severity:   Severity::Advisory,
                        pillar:     Pillar::Maintainability,
                        tier:       RuleTier::V01,
                        confidence: Confidence::Low,
                    ),
                ];
            }
        };
    }

    /**
     * Build a streaming project-rule fixture that records every accumulated unit path.
     *
     * @return ProjectRuleInterface - an anonymous accumulator emitting one finding that lists the unit paths it observed
     */
    private function accumulatingProjectRule(): ProjectRuleInterface
    {
        return new class () implements ProjectRuleInterface, ProjectRuleAccumulator {
            /**
             * Display paths of every unit fed to accumulate(), in arrival order.
             *
             * @var list<string>
             */
            private array $observedPaths = [];

            /**
             * Return metadata for the fixture rule.
             *
             * @return RuleDefinition - the accumulator fixture's deterministic identity and reporting metadata
             */
            public function definition(): RuleDefinition
            {
                return new RuleDefinition(
                    id:              'test.accumulating-project-rule',
                    name:            'Accumulating project rule fixture',
                    pillar:          Pillar::Maintainability,
                    tier:            RuleTier::V01,
                    defaultSeverity: Severity::Advisory,
                    confidence:      Confidence::Low,
                );
            }

            /**
             * Reset the accumulated unit paths for a fresh project pass.
             *
             * @param RuleContext $ruleContext - Rule context for the fixture.
             *
             * @return void
             */
            public function startProject(RuleContext $ruleContext): void
            {
                $this->observedPaths = [];
            }

            /**
             * Record one analysed unit's display path.
             *
             * @param AnalysisUnit $analysisUnit - Parsed unit to accumulate.
             * @param RuleContext  $ruleContext  - Rule context for the fixture.
             *
             * @return void
             */
            public function accumulate(AnalysisUnit $analysisUnit, RuleContext $ruleContext): void
            {
                $this->observedPaths[] = $analysisUnit->file->displayPath;
            }

            /**
             * Emit one finding listing every accumulated unit path.
             *
             * @param RuleContext $ruleContext - Rule context for the fixture.
             *
             * @return list<Finding> - a single finding naming the observed paths, proving full project context reached the seam
             */
            public function finishProject(RuleContext $ruleContext): array
            {
                return [
                    new Finding(
                        ruleId:     'test.accumulating-project-rule',
                        message:    sprintf('Accumulated: %s.', implode(', ', $this->observedPaths)),
                        filePath:   $this->observedPaths[0] ?? 'unknown',
                        line:       1,
                        severity:   Severity::Advisory,
                        pillar:     Pillar::Maintainability,
                        tier:       RuleTier::V01,
                        confidence: Confidence::Low,
                    ),
                ];
            }

            /**
             * Run the legacy whole-project pass for the fixture.
             *
             * @param list<AnalysisUnit> $units       - Parsed units available to the project-level rule.
             * @param RuleContext        $ruleContext - Rule context for the fixture.
             *
             * @return list<Finding> - always empty; the registry routes this accumulator through the streaming seam instead
             */
            public function analyseProject(array $units, RuleContext $ruleContext): array
            {
                return [];
            }
        };
    }

    /**
     * Build a project-level fixture rule with duplicate identity.
     *
     * @return RuleInterface - an anonymous rule reusing the project-level id to force a collision
     */
    private function duplicateProjectRule(): RuleInterface
    {
        return new readonly class () implements RuleInterface {
            /**
             * Return metadata for the fixture rule.
             *
             * @return RuleDefinition - the project-level fixture identity, deliberately sharing one id
             */
            public function definition(): RuleDefinition
            {
                return new RuleDefinition(
                    id:              'test.project-level',
                    name:            'Project-level fixture',
                    pillar:          Pillar::Documentation,
                    tier:            RuleTier::V01,
                    defaultSeverity: Severity::Warning,
                    confidence:      Confidence::High,
                );
            }

            /**
             * Return findings produced by the fixture rule.
             *
             * @param AnalysisUnit $analysisUnit - Analysis unit.
             * @param RuleContext  $ruleContext  - Rule context for the fixture.
             *
             * @return list<\GruffPhp\Results\Finding\Finding> - one README-scoped finding per unit, so dedup must collapse them to one
             */
            public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
            {
                return [
                    new Finding(
                        ruleId:     'test.project-level',
                        message:    'Project has no README.md.',
                        filePath:   'README.md',
                        line:       null,
                        severity:   Severity::Warning,
                        pillar:     Pillar::Documentation,
                        tier:       RuleTier::V01,
                        confidence: Confidence::High,
                    ),
                ];
            }
        };
    }
}
