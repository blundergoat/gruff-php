<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Config\RuleSettings;
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
use GruffPhp\Rule\TestQuality\LoopInTestRule;
use GruffPhp\Rule\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rule\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rule\TestQuality\MockOnlyTestRule;
use GruffPhp\Rule\TestQuality\MockWithoutExpectationRule;
use GruffPhp\Rule\TestQuality\MultipleAaaCyclesRule;
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
use GruffPhp\Rule\TestQuality\TestdoxReadabilityRule;
use GruffPhp\Rule\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rule\TestQuality\TestMethodTooLongRule;
use GruffPhp\Rule\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rule\TestQuality\TrivialAssertionRule;
use GruffPhp\Rule\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rule\TestQuality\UnusedMockRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class TestQualityRulesTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    public function testPhpUnitAndPestTestScopesSupportNoAssertionAndTrivialAssertionChecks(): void
    {
        $findings = $this->analysePaths([
            'tests/Fixtures/TestQuality/phpunit-core-smells.php',
            'tests/Fixtures/TestQuality/pest-smells.php',
        ]);

        self::assertRuleCount(NoAssertionsRule::ID, 4, $findings);
        self::assertRuleCount(TrivialAssertionRule::ID, 2, $findings);
    }

    public function testCoreControlFlowAndFlakinessSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-core-smells.php');

        self::assertRuleCount(ConditionalTestLogicRule::ID, 1, $findings);
        self::assertRuleCount(LoopInTestRule::ID, 1, $findings);
        // sleep + time + microtime + new DateTime('now') + new DateTimeImmutable() — frozen DateTime is not flagged
        self::assertRuleCount(SleepInTestRule::ID, 5, $findings);
    }

    public function testMechanicsSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-mechanics-smells.php');

        self::assertRuleCount(ExcessiveMockingRule::ID, 1, $findings);
        self::assertRuleCount(MockOnlyTestRule::ID, 1, $findings);
        self::assertRuleCount(MysteryGuestRule::ID, 1, $findings);
        self::assertRuleCount(MagicNumberAssertionRule::ID, 1, $findings);
        // ReflectionMethod + ReflectionClass + Closure::bind — one finding per test scope
        self::assertRuleCount(PrivateReflectionRule::ID, 3, $findings);
        self::assertRuleCount(DataProviderAnnotationRule::ID, 1, $findings);
        self::assertRuleCount(TrivialSnapshotRule::ID, 1, $findings);
        self::assertRuleCount(SetupBloatRule::ID, 1, $findings);
        self::assertRuleCount(SkippedWithoutReasonRule::ID, 1, $findings);
    }

    public function testAdvancedHeuristicSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-advanced-smells.php');

        self::assertRuleCount(EagerTestRule::ID, 1, $findings);
        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
        self::assertRuleCount(SutNotCalledRule::ID, 1, $findings);
        // 1 mixed-style class finding (MixedNamingQualityTest) + 2 poor-name method findings (PoorlyNamedTest::testProcessOrderWorks, ::testProcessOrder1)
        self::assertRuleCount(TestNamingConsistencyRule::ID, 3, $findings);
    }

    public function testNonCandidateCasesAreNotFlaggedBySelectedRules(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/non-candidates.php');

        self::assertRuleCount(DataProviderAnnotationRule::ID, 0, $findings);
        self::assertRuleCount(SkippedWithoutReasonRule::ID, 0, $findings);
        self::assertRuleCount(ConditionalTestLogicRule::ID, 0, $findings);
        self::assertRuleCount(SleepInTestRule::ID, 0, $findings);
        self::assertRuleCount(NoAssertionsRule::ID, 0, $findings);
        self::assertRuleCount(ExtendsProductionClassRule::ID, 0, $findings);
        self::assertRuleCount(EmptyDataProviderRule::ID, 0, $findings);
        self::assertRuleCount(TestMethodTooLongRule::ID, 0, $findings);
        self::assertRuleCount(LoopAssertionWithoutMessageRule::ID, 0, $findings);
        self::assertRuleCount(UnusedMockRule::ID, 0, $findings);
        self::assertRuleCount(ExceptionTypeOnlyRule::ID, 0, $findings);
        self::assertRuleCount(TautologicalTypeAssertionRule::ID, 0, $findings);
        self::assertRuleCount(GlobalStateMutationRule::ID, 0, $findings);
        self::assertRuleCount(MockWithoutExpectationRule::ID, 0, $findings);
        self::assertRuleCount(RepeatedStructureMissingDataProviderRule::ID, 0, $findings);
        self::assertRuleCount(MultipleAaaCyclesRule::ID, 0, $findings);
        self::assertRuleCount(TestdoxReadabilityRule::ID, 0, $findings);
        self::assertRuleCount(MockingDomainObjectRule::ID, 0, $findings);
        // The phpunit-* rules are project-level (they fire on .phpunit.xml.dist at the project root),
        // not source-file rules, so they're intentionally excluded from this fixture-content assertion.
    }

    public function testExtendsProductionClassDetectedAndAllowsTestCaseDescendants(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/extends-production.php');

        self::assertRuleCount(ExtendsProductionClassRule::ID, 1, $findings);
    }

    public function testTestMethodTooLongDetectedAndIgnoresWhitespaceLines(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/test-method-too-long.php');

        self::assertRuleCount(TestMethodTooLongRule::ID, 1, $findings);
    }

    public function testEmptyDataProviderDetectedAndYieldingProviderIsAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/empty-data-provider.php');

        self::assertRuleCount(EmptyDataProviderRule::ID, 2, $findings);
    }

    public function testLoopAssertionWithoutMessageDetectedAndAssertionWithMessageAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/loop-assertion-without-message.php');

        self::assertRuleCount(LoopAssertionWithoutMessageRule::ID, 2, $findings);
    }

    public function testUnusedMockDetectedAndUsedMocksAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/unused-mock.php');

        self::assertRuleCount(UnusedMockRule::ID, 2, $findings);
    }

    public function testExceptionTypeOnlyDetectedAndPairedAssertionsAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/exception-type-only.php');

        self::assertRuleCount(ExceptionTypeOnlyRule::ID, 1, $findings);
    }

    public function testTautologicalTypeAssertionDetectedAndCrossClassAssertionsAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/tautological-type-assertion.php');

        self::assertRuleCount(TautologicalTypeAssertionRule::ID, 2, $findings);
    }

    public function testGlobalStateMutationDetectedAndCleanedUpClassAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/global-state-mutation.php');

        // 3 mutations in the leaky class (superglobal write + putenv + ini_set), 0 in classes with local or inherited cleanup, 0 in the read-only class.
        self::assertRuleCount(GlobalStateMutationRule::ID, 3, $findings);
    }

    public function testMockWithoutExpectationDetectedWithVariantSeverities(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/mock-without-expectation.php');

        self::assertRuleCount(MockWithoutExpectationRule::ID, 2, $findings);

        $variants = [];
        $variables = [];
        foreach ($findings as $finding) {
            if ($finding->ruleId !== MockWithoutExpectationRule::ID) {
                continue;
            }

            $variant = $finding->metadata['variant'] ?? null;
            self::assertIsString($variant);
            $variants[] = $variant;

            $variable = $finding->metadata['variable'] ?? null;
            self::assertIsString($variable);
            $variables[] = $variable;
        }
        sort($variants);
        self::assertSame(['dead-mock', 'stub-only'], $variants);
        self::assertNotContains('fake', $variables);
    }

    public function testRepeatedStructureMissingDataProviderDetectedAndDataProviderUsersIgnored(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/repeated-structure-missing-data-provider.php');

        self::assertRuleCount(RepeatedStructureMissingDataProviderRule::ID, 1, $findings);
    }

    public function testMultipleAaaCyclesIsDisabledByDefaultButFiresWhenOptedIn(): void
    {
        $defaultFindings = $this->analysePath('tests/Fixtures/TestQuality/multiple-aaa-cycles.php');
        self::assertRuleCount(MultipleAaaCyclesRule::ID, 0, $defaultFindings);

        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/enable-multiple-aaa-cycles.yaml',
            $registry,
        );
        $optedInFindings = $this->analysePaths(
            ['tests/Fixtures/TestQuality/multiple-aaa-cycles.php'],
            $config,
        );

        self::assertRuleCount(MultipleAaaCyclesRule::ID, 1, $optedInFindings);
    }

    public function testMultipleAaaCyclesDoesNotDoubleCountInlineActAssertAfterActStatement(): void
    {
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(MultipleAaaCyclesRule::ID);
        $config = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            MultipleAaaCyclesRule::ID,
            new RuleSettings(true, ['minCycles' => 1], $settings->options),
        );
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/multiple-aaa-cycles.php', $config),
            static fn (Finding $finding): bool => $finding->ruleId === MultipleAaaCyclesRule::ID
                && $finding->symbol === 'MultipleAaaCyclesTest::testActThenInlineActAssertCountsAsOneCycle()',
        ));

        self::assertCount(1, $findings);
        self::assertSame(1, $findings[0]->metadata['cycles']);
    }

    public function testTestdoxReadabilityIsDisabledByDefaultButFiresWhenOptedIn(): void
    {
        $defaultFindings = $this->analysePath('tests/Fixtures/TestQuality/testdox-readability.php');
        self::assertRuleCount(TestdoxReadabilityRule::ID, 0, $defaultFindings);

        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/enable-testdox-readability.yaml',
            $registry,
        );
        $optedInFindings = $this->analysePaths(
            ['tests/Fixtures/TestQuality/testdox-readability.php'],
            $config,
        );

        self::assertRuleCount(TestdoxReadabilityRule::ID, 2, $optedInFindings);
    }

    public function testMockingDomainObjectIsDisabledByDefaultAndRequiresPatternsToFire(): void
    {
        $defaultFindings = $this->analysePath('tests/Fixtures/TestQuality/mocking-domain-object.php');
        self::assertRuleCount(MockingDomainObjectRule::ID, 0, $defaultFindings);

        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/enable-mocking-domain-object.yaml',
            $registry,
        );
        $optedInFindings = $this->analysePaths(
            ['tests/Fixtures/TestQuality/mocking-domain-object.php'],
            $config,
        );

        self::assertRuleCount(MockingDomainObjectRule::ID, 2, $optedInFindings);
    }

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

    public function testPhpUnitRulesStaySilentWhenNoConfigFileIsPresent(): void
    {
        $context = $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/no-config');

        self::assertCount(0, (new PhpUnitStrictFlagsMissingRule())->analyse($this->phpUnitDummyUnit(), $context));
        self::assertCount(0, (new PhpUnitDeprecationsNotFatalRule())->analyse($this->phpUnitDummyUnit(), $context));
        self::assertCount(0, (new PhpUnitCoverageSourceMissingRule())->analyse($this->phpUnitDummyUnit(), $context));
    }

    private function phpUnitDummyUnit(): AnalysisUnit
    {
        return $this->unitForPath('tests/Fixtures/TestQuality/non-candidates.php');
    }

    private function phpUnitContext(string $relativeRoot): RuleContext
    {
        $registry = RuleRegistry::defaults();

        return new RuleContext(
            self::PROJECT_ROOT . '/' . $relativeRoot,
            AnalysisConfig::fromRegistry($registry),
        );
    }

    public function testTestQualityRulesRespectConfigDisables(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(self::PROJECT_ROOT))->load(
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

    public function testCumulativeFixtureRepresentsEveryStaticTestQualityRule(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/cumulative-test-quality.php'),
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'test-quality.'),
        ));

        $ruleIds = array_map(static fn (Finding $finding): string => $finding->ruleId, $findings);
        foreach ($this->expectedRuleIds() as $ruleId) {
            self::assertContains($ruleId, $ruleIds);
        }

        $fingerprints = array_map(static fn (Finding $finding): string => $finding->fingerprint(), $findings);
        self::assertCount(count($fingerprints), array_unique($fingerprints));
    }

    /**
     * @param list<Finding> $findings
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
     * @return list<string>
     */
    private function expectedRuleIds(): array
    {
        return [
            NoAssertionsRule::ID,
            TrivialAssertionRule::ID,
            ConditionalTestLogicRule::ID,
            LoopInTestRule::ID,
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
     * @return list<Finding>
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * @param list<string> $paths
     * @return list<Finding>
     */
    private function analysePaths(array $paths, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $units = array_map(fn (string $path): AnalysisUnit => $this->unitForPath($path), $paths);

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? AnalysisConfig::fromRegistry($registry)),
        );
    }

    private function unitForPath(string $path): AnalysisUnit
    {
        return (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $path, $path));
    }
}
