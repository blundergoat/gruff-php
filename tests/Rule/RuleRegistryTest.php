<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\Complexity\NestingDepthRule;
use GruffPhp\Rule\Complexity\NpathComplexityRule;
use GruffPhp\Rule\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rule\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rule\Modernisation\ConstructorPromotionCandidateRule;
use GruffPhp\Rule\Modernisation\EnumCandidateRule;
use GruffPhp\Rule\Modernisation\FirstClassCallableCandidateRule;
use GruffPhp\Rule\Modernisation\ForbiddenGlobalAccessRule;
use GruffPhp\Rule\Modernisation\MatchExpressionCandidateRule;
use GruffPhp\Rule\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rule\Modernisation\NamedArgumentOpportunityRule;
use GruffPhp\Rule\Modernisation\PublicPropertyRule;
use GruffPhp\Rule\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Security\DangerousFunctionCallRule;
use GruffPhp\Rule\Security\DisabledSslVerificationRule;
use GruffPhp\Rule\Security\ErrorSuppressionRule;
use GruffPhp\Rule\Security\ExtractCompactUserInputRule;
use GruffPhp\Rule\Security\HeaderInjectionRule;
use GruffPhp\Rule\Security\InsecureRandomRule;
use GruffPhp\Rule\Security\SilentCatchRule;
use GruffPhp\Rule\Security\SqlConcatenationRule;
use GruffPhp\Rule\Security\UnsafeUnserializeRule;
use GruffPhp\Rule\Security\VariableIncludeRule;
use GruffPhp\Rule\Security\WeakCryptoRule;
use GruffPhp\Rule\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rule\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rule\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rule\SensitiveData\HardcodedEnvValueRule;
use GruffPhp\Rule\SensitiveData\HighEntropyStringRule;
use GruffPhp\Rule\SensitiveData\JwtTokenRule;
use GruffPhp\Rule\SensitiveData\PhiPatternRule;
use GruffPhp\Rule\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rule\SensitiveData\PrivateKeyRule;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Rule\Size\ClassLengthRule;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Rule\Size\ParameterCountRule;
use GruffPhp\Rule\Size\PropertyCountRule;
use GruffPhp\Rule\Size\PublicMethodCountRule;
use GruffPhp\Rule\TestQuality\ConditionalTestLogicRule;
use GruffPhp\Rule\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rule\TestQuality\EagerTestRule;
use GruffPhp\Rule\TestQuality\ExcessiveMockingRule;
use GruffPhp\Rule\TestQuality\LoopInTestRule;
use GruffPhp\Rule\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rule\TestQuality\MockOnlyTestRule;
use GruffPhp\Rule\TestQuality\MysteryGuestRule;
use GruffPhp\Rule\TestQuality\NoAssertionsRule;
use GruffPhp\Rule\TestQuality\PrivateReflectionRule;
use GruffPhp\Rule\TestQuality\SetupBloatRule;
use GruffPhp\Rule\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rule\TestQuality\SleepInTestRule;
use GruffPhp\Rule\TestQuality\SutNotCalledRule;
use GruffPhp\Rule\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rule\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rule\TestQuality\TrivialAssertionRule;
use GruffPhp\Rule\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rule\Waste\CommentedOutCodeRule;
use GruffPhp\Rule\Waste\EmptyClassRule;
use GruffPhp\Rule\Waste\EmptyMethodRule;
use GruffPhp\Rule\Waste\OneLineMethodRule;
use GruffPhp\Rule\Waste\UnreachableCodeRule;
use GruffPhp\Rule\Waste\UnusedImportRule;
use GruffPhp\Rule\Waste\UnusedParameterRule;
use GruffPhp\Source\SourceFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers RuleRegistryTest behavior.
 */
final class RuleRegistryTest extends TestCase
{
    /**
     * Verify default registry contains stable rule ids.
     *
     * @return void No return value.
     */
    public function testDefaultRegistryContainsStableRuleIds(): void
    {
        $registry = RuleRegistry::defaults();

        self::assertTrue($registry->has(CognitiveComplexityRule::ID));
        self::assertTrue($registry->has(CyclomaticComplexityRule::ID));
        self::assertTrue($registry->has(HalsteadVolumeRule::ID));
        self::assertTrue($registry->has(MaintainabilityIndexRule::ID));
        self::assertTrue($registry->has(NestingDepthRule::ID));
        self::assertTrue($registry->has(NpathComplexityRule::ID));
        self::assertTrue($registry->has(UnusedPrivateMethodRule::ID));
        self::assertTrue($registry->has(UnusedPrivatePropertyRule::ID));
        self::assertTrue($registry->has(CommentedOutCodeRule::ID));
        self::assertTrue($registry->has(EmptyClassRule::ID));
        self::assertTrue($registry->has(EmptyMethodRule::ID));
        self::assertTrue($registry->has(OneLineMethodRule::ID));
        self::assertTrue($registry->has(UnreachableCodeRule::ID));
        self::assertTrue($registry->has(UnusedImportRule::ID));
        self::assertTrue($registry->has(UnusedParameterRule::ID));
        self::assertTrue($registry->has(IdentifierQualityRule::ID));
        self::assertTrue($registry->has(ConstructorPromotionCandidateRule::ID));
        self::assertTrue($registry->has(EnumCandidateRule::ID));
        self::assertTrue($registry->has(FirstClassCallableCandidateRule::ID));
        self::assertTrue($registry->has(ForbiddenGlobalAccessRule::ID));
        self::assertTrue($registry->has(MatchExpressionCandidateRule::ID));
        self::assertTrue($registry->has(MixedTypeOveruseRule::ID));
        self::assertTrue($registry->has(NamedArgumentOpportunityRule::ID));
        self::assertTrue($registry->has(PublicPropertyRule::ID));
        self::assertTrue($registry->has(ReadonlyPropertyCandidateRule::ID));
        self::assertTrue($registry->has(ApiKeyPatternRule::ID));
        self::assertTrue($registry->has(AwsAccessKeyRule::ID));
        self::assertTrue($registry->has(DatabaseUrlPasswordRule::ID));
        self::assertTrue($registry->has(HardcodedEnvValueRule::ID));
        self::assertTrue($registry->has(HighEntropyStringRule::ID));
        self::assertTrue($registry->has(JwtTokenRule::ID));
        self::assertTrue($registry->has(PhiPatternRule::ID));
        self::assertTrue($registry->has(PiiTestFixtureRule::ID));
        self::assertTrue($registry->has(PrivateKeyRule::ID));
        self::assertTrue($registry->has(DangerousFunctionCallRule::ID));
        self::assertTrue($registry->has(DisabledSslVerificationRule::ID));
        self::assertTrue($registry->has(ErrorSuppressionRule::ID));
        self::assertTrue($registry->has(ExtractCompactUserInputRule::ID));
        self::assertTrue($registry->has(HeaderInjectionRule::ID));
        self::assertTrue($registry->has(InsecureRandomRule::ID));
        self::assertTrue($registry->has(SilentCatchRule::ID));
        self::assertTrue($registry->has(SqlConcatenationRule::ID));
        self::assertTrue($registry->has(UnsafeUnserializeRule::ID));
        self::assertTrue($registry->has(VariableIncludeRule::ID));
        self::assertTrue($registry->has(WeakCryptoRule::ID));
        self::assertTrue($registry->has(ConditionalTestLogicRule::ID));
        self::assertTrue($registry->has(DataProviderAnnotationRule::ID));
        self::assertTrue($registry->has(EagerTestRule::ID));
        self::assertTrue($registry->has(ExcessiveMockingRule::ID));
        self::assertTrue($registry->has(LoopInTestRule::ID));
        self::assertTrue($registry->has(MagicNumberAssertionRule::ID));
        self::assertTrue($registry->has(MockOnlyTestRule::ID));
        self::assertTrue($registry->has(MysteryGuestRule::ID));
        self::assertTrue($registry->has(NoAssertionsRule::ID));
        self::assertTrue($registry->has(PrivateReflectionRule::ID));
        self::assertTrue($registry->has(SetupBloatRule::ID));
        self::assertTrue($registry->has(SkippedWithoutReasonRule::ID));
        self::assertTrue($registry->has(SleepInTestRule::ID));
        self::assertTrue($registry->has(SutNotCalledRule::ID));
        self::assertTrue($registry->has(TestLongerThanSutRule::ID));
        self::assertTrue($registry->has(TestNamingConsistencyRule::ID));
        self::assertTrue($registry->has(TrivialAssertionRule::ID));
        self::assertTrue($registry->has(TrivialSnapshotRule::ID));
        self::assertTrue($registry->has(AverageMethodLengthRule::ID));
        self::assertTrue($registry->has(ClassLengthRule::ID));
        self::assertTrue($registry->has(FileLengthRule::ID));
        self::assertTrue($registry->has(MethodLengthRule::ID));
        self::assertTrue($registry->has(ParameterCountRule::ID));
        self::assertTrue($registry->has(PropertyCountRule::ID));
        self::assertTrue($registry->has(PublicMethodCountRule::ID));
    }

    /**
     * Verify runs enabled rules over parsed files.
     *
     * @return void No return value.
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

        $findings = array_values(array_filter($allFindings, static fn ($finding) => $finding->ruleId === FileLengthRule::ID));

        self::assertCount(1, $findings);
        self::assertSame(FileLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(Pillar::Size, $findings[0]->pillar);
        self::assertSame(['lines' => 27, 'threshold' => 3, 'thresholdType' => 'warning'], $findings[0]->metadata);
    }

    /**
     * Verify skips disabled rules.
     *
     * @return void No return value.
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

        $fileLengthFindings = array_filter($allFindings, static fn ($finding) => $finding->ruleId === FileLengthRule::ID);
        self::assertSame([], array_values($fileLengthFindings));
    }

    /**
     * Verify deduplicates project level findings across units.
     *
     * @return void No return value.
     */
    public function testDeduplicatesProjectLevelFindingsAcrossUnits(): void
    {
        $registry = new RuleRegistry([$this->duplicateProjectRule()]);
        $config   = AnalysisConfig::fromRegistry($registry);

        $findings = $registry->analyse(
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
     * Verify rejects duplicate rule ids.
     *
     * @return void No return value.
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
     * @return void No return value.
     */
    public function testDefaultRulesHaveListableDescriptions(): void
    {
        $missingDescriptionIds = array_values(array_map(
            static fn ($rule): string => $rule->definition()->id,
            array_filter(
                RuleRegistry::defaults()->all(),
                static fn ($rule): bool => trim($rule->definition()->description()) === '',
            ),
        ));

        self::assertSame([], $missingDescriptionIds);
    }

    /**
     * Verify default rule definitions keep stable reporting and config metadata.
     *
     * @return void No return value.
     */
    public function testDefaultRuleDefinitionsStayStable(): void
    {
        $definitions = array_map(static function ($rule): array {
            $definition = $rule->definition();

            return [
                'id' => $definition->id,
                'name' => $definition->name,
                'description' => $definition->description(),
                'pillar' => $definition->pillar->value,
                'secondaryPillars' => array_map(static fn (Pillar $pillar): string => $pillar->value, $definition->secondaryPillars),
                'tier' => $definition->tier->value,
                'defaultSeverity' => $definition->defaultSeverity->value,
                'confidence' => $definition->confidence->value,
                'defaultThresholds' => $definition->defaultThresholds,
                'defaultEnabled' => $definition->defaultEnabled,
                'defaultOptions' => $definition->defaultOptions,
            ];
        }, RuleRegistry::defaults()->all());

        usort($definitions, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $json = json_encode($definitions, JSON_THROW_ON_ERROR);

        self::assertCount(110, $definitions);
        self::assertSame('9a44423e7b63191d5f6112bf75a6d6c5551eba5029acf223d39cb937785ad9c0', hash('sha256', $json));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $displayPath Fixture display path.
     * @return AnalysisUnit Fixture value.
     */
    private function parseFixture(string $displayPath): AnalysisUnit
    {
        $absolutePath = __DIR__ . '/../..' . '/' . $displayPath;

        return (new PhpFileParser())->parse(new SourceFile($absolutePath, $displayPath));
    }

    /**
     * Build a fixture rule with the requested identifier.
     *
     * @param string $id Rule identifier.
     * @return RuleInterface Fixture value.
     */
    private function fakeRule(string $id): RuleInterface
    {
        return new readonly class ($id) implements RuleInterface {
            /**
             * Build the anonymous fixture rule.
             *
             * @param string $id Rule identifier.
             */
            public function __construct(private string $id)
            {
            }

            /**
             * Return metadata for the fixture rule.
             *
             * @return RuleDefinition Fixture value.
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
             * @param AnalysisUnit $unit Analysis unit.
             * @param RuleContext $context Rule context for the fixture.
             * @return list<\GruffPhp\Finding\Finding> Fixture findings.
             */
            public function analyse(AnalysisUnit $unit, RuleContext $context): array
            {
                return [
                    new Finding(
                        ruleId:     $this->id,
                        message:    'Fake finding.',
                        filePath:   $unit->file->displayPath,
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
     * Build a project-level fixture rule with duplicate identity.
     *
     * @return RuleInterface Fixture value.
     */
    private function duplicateProjectRule(): RuleInterface
    {
        return new readonly class () implements RuleInterface {
            /**
             * Return metadata for the fixture rule.
             *
             * @return RuleDefinition Fixture value.
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
             * @param AnalysisUnit $unit Analysis unit.
             * @param RuleContext $context Rule context for the fixture.
             * @return list<\GruffPhp\Finding\Finding> Fixture findings.
             */
            public function analyse(AnalysisUnit $unit, RuleContext $context): array
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
