<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\TestQuality\ConditionalTestLogicRule;
use GruffPhp\Rules\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rules\TestQuality\EagerTestRule;
use GruffPhp\Rules\TestQuality\EmptyDataProviderRule;
use GruffPhp\Rules\TestQuality\ExceptionTypeOnlyRule;
use GruffPhp\Rules\TestQuality\ExcessiveMockingRule;
use GruffPhp\Rules\TestQuality\ExtendsProductionClassRule;
use GruffPhp\Rules\TestQuality\GlobalStateMutationRule;
use GruffPhp\Rules\TestQuality\LoopAssertionWithoutMessageRule;
use GruffPhp\Rules\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rules\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rules\TestQuality\MockOnlyTestRule;
use GruffPhp\Rules\TestQuality\MockWithoutExpectationRule;
use GruffPhp\Rules\TestQuality\MysteryGuestRule;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use GruffPhp\Rules\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rules\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rules\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Rules\TestQuality\PrivateReflectionRule;
use GruffPhp\Rules\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rules\TestQuality\SetupBloatRule;
use GruffPhp\Rules\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rules\TestQuality\SleepInTestRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use GruffPhp\Rules\TestQuality\TautologicalTypeAssertionRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\TestQuality\TestMethodTooLongRule;
use GruffPhp\Rules\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rules\TestQuality\TrivialAssertionRule;
use GruffPhp\Rules\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rules\TestQuality\UnusedMockRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers config-driven test-quality rules and cumulative fixture contracts.
 */
final class TestQualityConfigurationRulesTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify mocking domain object is enabled by default but requires patterns to fire.
     *
     * @return void
     */
    public function testMockingDomainObjectIsEnabledByDefaultAndRequiresPatternsToFire(): void
    {
        $defaultFindings = $this->analysePath('tests/Fixtures/TestQuality/mocking-domain-object.php');
        self::assertRuleCount(MockingDomainObjectRule::ID, 0, $defaultFindings);

        $registry        = RuleRegistry::defaults();
        $config          = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/enable-mocking-domain-object.yaml',
            $registry,
        );
        $optedInFindings = $this->analysePaths(
            ['tests/Fixtures/TestQuality/mocking-domain-object.php'],
            $config,
        );

        self::assertRuleCount(MockingDomainObjectRule::ID, 2, $optedInFindings);
    }

    /**
     * Verify PHPUnit strict flags missing fires on lax config and stays silent on strict.
     *
     * @return void
     */
    public function testPhpUnitStrictFlagsMissingFiresOnLaxConfigAndStaysSilentOnStrict(): void
    {
        self::assertCount(0, (new PhpUnitStrictFlagsMissingRule())->analyse(
            $this->phpUnitDummyUnit(),
            $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/strict'),
        ));

        $laxFindings = (new PhpUnitStrictFlagsMissingRule())->analyse(
            $this->phpUnitDummyUnit(),
            $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/lax'),
        );

        self::assertCount(1, $laxFindings);
        self::assertSame(['failOnRisky', 'failOnWarning', 'beStrictAboutTestsThatDoNotTestAnything', 'beStrictAboutOutputDuringTests', 'beStrictAboutChangesToGlobalState'], $laxFindings[0]->metadata['missing']);
    }

    /**
     * Verify PHPUnit deprecations not fatal fires on lax config and stays silent on strict.
     *
     * @return void
     */
    public function testPhpUnitDeprecationsNotFatalFiresOnLaxConfigAndStaysSilentOnStrict(): void
    {
        self::assertCount(0, (new PhpUnitDeprecationsNotFatalRule())->analyse(
            $this->phpUnitDummyUnit(),
            $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/strict'),
        ));

        self::assertCount(1, (new PhpUnitDeprecationsNotFatalRule())->analyse(
            $this->phpUnitDummyUnit(),
            $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/lax'),
        ));
    }

    /**
     * Verify PHPUnit coverage source missing fires on lax config and allows legacy whitelist.
     *
     * @return void
     */
    public function testPhpUnitCoverageSourceMissingFiresOnLaxConfigAndAllowsLegacyWhitelist(): void
    {
        self::assertCount(0, (new PhpUnitCoverageSourceMissingRule())->analyse(
            $this->phpUnitDummyUnit(),
            $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/strict'),
        ));

        self::assertCount(1, (new PhpUnitCoverageSourceMissingRule())->analyse(
            $this->phpUnitDummyUnit(),
            $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/lax'),
        ));

        self::assertCount(0, (new PhpUnitCoverageSourceMissingRule())->analyse(
            $this->phpUnitDummyUnit(),
            $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/legacy-whitelist'),
        ));
    }

    /**
     * Verify PHPUnit rules stay silent when no config file is present.
     *
     * @return void
     */
    public function testPhpUnitRulesStaySilentWhenNoConfigFileIsPresent(): void
    {
        $context = $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/no-config');

        self::assertCount(0, (new PhpUnitStrictFlagsMissingRule())->analyse($this->phpUnitDummyUnit(), $context));
        self::assertCount(0, (new PhpUnitDeprecationsNotFatalRule())->analyse($this->phpUnitDummyUnit(), $context));
        self::assertCount(0, (new PhpUnitCoverageSourceMissingRule())->analyse($this->phpUnitDummyUnit(), $context));
    }

    /**
     * Verify test quality rules respect config disables.
     *
     * @return void
     */
    public function testTestQualityRulesRespectConfigDisables(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/disable-no-assertions.yaml',
            $registry,
        );

        $findings = $this->analysePaths(
            ['tests/Fixtures/TestQuality/phpunit-core-smells.php'],
            $config,
        );

        self::assertRuleCount(NoAssertionsRule::ID, 0, $findings);
        self::assertRuleCount(TrivialAssertionRule::ID, 1, $findings);
    }

    /**
     * Verify additionalTestBaseClasses accepts an exact base name that matches neither *TestCase shape.
     *
     * @return void
     */
    public function testExtendsProductionClassHonoursAdditionalTestBaseClasses(): void
    {
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(ExtendsProductionClassRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ExtendsProductionClassRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['additionalTestBaseClasses' => ['IntegrationTestBase']])),
        );

        $findings = array_values(array_filter(
                                     $this->analysePaths(['tests/Fixtures/TestQuality/extends-production.php'], $config),
                                     static fn(Finding $finding): bool => $finding->ruleId === ExtendsProductionClassRule::ID,
                                 ));

        self::assertCount(1, $findings);
        self::assertSame('OrderServiceTest', $findings[0]->symbol);
    }

    /**
     * Verify non test class methods with test prefix are not analysed.
     *
     * @return void
     */
    public function testNonTestClassMethodsWithTestPrefixAreNotAnalysed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/non-test-class.php');

        $testQualityFindings = array_values(array_filter(
                                                $findings,
                                                static fn(Finding $finding): bool => str_starts_with($finding->ruleId, 'test-quality.')
                                                                                     && str_contains($finding->filePath, 'non-test-class.php'),
                                            ));

        self::assertSame([], $testQualityFindings, 'Library code with test* method names must not trigger test-quality rules.');
    }

    /**
     * Verify cumulative fixture represents every static test quality rule.
     *
     * @return void
     */
    public function testCumulativeFixtureRepresentsEveryStaticTestQualityRule(): void
    {
        $findings = array_values(array_filter(
                                     $this->analysePath('tests/Fixtures/TestQuality/cumulative-test-quality.php'),
                                     static fn(Finding $finding): bool => str_starts_with($finding->ruleId, 'test-quality.'),
                                 ));

        $ruleIds        = array_map(static fn(Finding $finding): string => $finding->ruleId, $findings);
        $missingRuleIds = array_values(array_diff($this->expectedRuleIds(), $ruleIds));

        self::assertSame([], $missingRuleIds);

        $fingerprints = array_map(static fn(Finding $finding): string => $finding->fingerprint(), $findings);
        self::assertCount(count($fingerprints), array_unique($fingerprints));
    }

    /**
     * Build a dummy PHPUnit analysis unit.
     *
     * @return AnalysisUnit - a parsed unit with no test-quality candidates, used as a placeholder subject for config-only rule checks
     */
    private function phpUnitDummyUnit(): AnalysisUnit
    {
        return $this->unitForPath('tests/Fixtures/TestQuality/non-candidates.php');
    }

    /**
     * Build a rule context for PHPUnit helper tests.
     *
     * @param string $relativeRoot - Project-root-relative directory used as the context root.
     *
     * @return RuleContext - context anchored at the project root joined with the relative root, carrying default-registry config
     */
    private function phpUnitContext(string $relativeRoot): RuleContext
    {
        $registry = RuleRegistry::defaults();

        return new RuleContext(
            self::PROJECT_ROOT . '/' . $relativeRoot,
            AnalysisConfig::fromRegistry($registry),
        );
    }

    /**
     * Assert the expected test-quality finding count for a rule.
     *
     * @param string        $ruleId - Rule identifier whose findings are counted.
     * @param int           $expectedCount - Exact number of findings the rule must emit.
     * @param list<Finding> $findings - Findings to filter down to the requested rule id.
     *
     * @return void
     */
    private static function assertRuleCount(string $ruleId, int $expectedCount, array $findings): void
    {
        self::assertCount(
            $expectedCount,
            array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId)),
            sprintf('Expected %d findings for %s.', $expectedCount, $ruleId),
        );
    }

    /**
     * List rule IDs expected in enabled test-quality scans.
     *
     * @return list<string> - every test-quality rule id an enabled scan must surface; a missing entry means a rule was dropped
     */
    private function expectedRuleIds(): array
    {
        return [
            NoAssertionsRule::ID,
            TrivialAssertionRule::ID,
            ConditionalTestLogicRule::ID,
            TestLongerThanSutRule::ID,
            EagerTestRule::ID,
            MysteryGuestRule::ID,
            ExcessiveMockingRule::ID,
            MockOnlyTestRule::ID,
            SleepInTestRule::ID,
            TestNamingConsistencyRule::ID,
            MagicNumberAssertionRule::ID,
            PrivateReflectionRule::ID,
            DataProviderAnnotationRule::ID,
            TrivialSnapshotRule::ID,
            SutNotCalledRule::ID,
            SetupBloatRule::ID,
            SkippedWithoutReasonRule::ID,
            ExtendsProductionClassRule::ID,
            TestMethodTooLongRule::ID,
            EmptyDataProviderRule::ID,
            LoopAssertionWithoutMessageRule::ID,
            UnusedMockRule::ID,
            ExceptionTypeOnlyRule::ID,
            TautologicalTypeAssertionRule::ID,
            GlobalStateMutationRule::ID,
            MockWithoutExpectationRule::ID,
            RepeatedStructureMissingDataProviderRule::ID,
        ];
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param string              $path - Single fixture path to analyse.
     * @param AnalysisConfig|null $config - Overriding config, or null to use the registry defaults.
     *
     * @return list<Finding> - all findings the default registry emits for the single fixture; empty when the fixture is clean
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param list<string>        $paths - Fixture paths to parse and analyse together.
     * @param AnalysisConfig|null $config - Overriding config, or null to use the registry defaults.
     *
     * @return list<Finding> - findings the default registry emits across all parsed fixtures combined; empty when none fire
     */
    private function analysePaths(array $paths, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $units    = array_map(fn(string $path): AnalysisUnit => $this->unitForPath($path), $paths);

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? AnalysisConfig::fromRegistry($registry)),
        );
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path - Filesystem path.
     *
     * @return AnalysisUnit - the fixture parsed from the project-root-relative path, retaining that path as its display name
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        return (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $path, $path));
    }
}
