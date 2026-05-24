<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\TestQuality\ConditionalTestLogicRule;
use GruffPhp\Rule\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rule\TestQuality\EagerTestRule;
use GruffPhp\Rule\TestQuality\EmptyDataProviderRule;
use GruffPhp\Rule\TestQuality\ExceptionTypeOnlyRule;
use GruffPhp\Rule\TestQuality\ExcessiveMockingRule;
use GruffPhp\Rule\TestQuality\ExtendsProductionClassRule;
use GruffPhp\Rule\TestQuality\GlobalStateMutationRule;
use GruffPhp\Rule\TestQuality\LoopAssertionWithoutMessageRule;
use GruffPhp\Rule\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rule\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rule\TestQuality\MockOnlyTestRule;
use GruffPhp\Rule\TestQuality\MockWithoutExpectationRule;
use GruffPhp\Rule\TestQuality\MysteryGuestRule;
use GruffPhp\Rule\TestQuality\NoAssertionsRule;
use GruffPhp\Rule\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rule\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rule\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Rule\TestQuality\PrivateReflectionRule;
use GruffPhp\Rule\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rule\TestQuality\SetupBloatRule;
use GruffPhp\Rule\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rule\TestQuality\SleepInTestRule;
use GruffPhp\Rule\TestQuality\SutNotCalledRule;
use GruffPhp\Rule\TestQuality\TautologicalTypeAssertionRule;
use GruffPhp\Rule\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rule\TestQuality\TestMethodTooLongRule;
use GruffPhp\Rule\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rule\TestQuality\TrivialAssertionRule;
use GruffPhp\Rule\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rule\TestQuality\UnusedMockRule;
use GruffPhp\Source\SourceFile;
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

        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(self::PROJECT_ROOT))->load(
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
     * Verify non test class methods with test prefix are not analysed.
     *
     * @return void
     */
    public function testNonTestClassMethodsWithTestPrefixAreNotAnalysed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/non-test-class.php');

        $testQualityFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'test-quality.')
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
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'test-quality.'),
        ));

        $ruleIds        = array_map(static fn (Finding $finding): string => $finding->ruleId, $findings);
        $missingRuleIds = array_values(array_diff($this->expectedRuleIds(), $ruleIds));

        self::assertSame([], $missingRuleIds);

        $fingerprints = array_map(static fn (Finding $finding): string => $finding->fingerprint(), $findings);
        self::assertCount(count($fingerprints), array_unique($fingerprints));
    }

    /**
     * Build a dummy PHPUnit analysis unit.
     *
     * @return AnalysisUnit
     */
    private function phpUnitDummyUnit(): AnalysisUnit
    {
        return $this->unitForPath('tests/Fixtures/TestQuality/non-candidates.php');
    }

    /**
     * Build a rule context for PHPUnit helper tests.
     *
     * @param string $relativeRoot
     * @return RuleContext
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
     * @param list<Finding> $findings
     * @return void
     */
    private static function assertRuleCount(string $ruleId, int $expectedCount, array $findings): void
    {
        self::assertCount(
            $expectedCount,
            array_values(array_filter($findings, static fn (Finding $finding): bool => $finding->ruleId === $ruleId)),
            sprintf('Expected %d findings for %s.', $expectedCount, $ruleId),
        );
    }

    /**
     * List rule IDs expected in enabled test-quality scans.
     *
     * @return list<string>
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
     * @return list<Finding>
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param list<string> $paths
     * @return list<Finding>
     */
    private function analysePaths(array $paths, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $units    = array_map(fn (string $path): AnalysisUnit => $this->unitForPath($path), $paths);

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? AnalysisConfig::fromRegistry($registry)),
        );
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path Filesystem path.
     * @return AnalysisUnit
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        return (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $path, $path));
    }
}
