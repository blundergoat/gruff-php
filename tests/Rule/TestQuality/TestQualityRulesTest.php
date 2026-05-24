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
use GruffPhp\Rule\TestQuality\MagicNumberAssertionRule;
use GruffPhp\Rule\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rule\TestQuality\MockOnlyTestRule;
use GruffPhp\Rule\TestQuality\MockWithoutExpectationRule;
use GruffPhp\Rule\TestQuality\MultipleAaaCyclesRule;
use GruffPhp\Rule\TestQuality\MysteryGuestRule;
use GruffPhp\Rule\TestQuality\NoAssertionsRule;
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

/**
 * Covers the test-quality rule pack across PHPUnit and Pest: assertion smells, eager tests, SUT-not-called, oversized methods, unused mocks, global-state leaks, repeated structures, and per-rule severity options.
 */
final class TestQualityRulesTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify PHPUnit and pest test scopes support no assertion and trivial assertion checks.
     *
     * @return void
     */
    public function testPhpUnitAndPestTestScopesSupportNoAssertionAndTrivialAssertionChecks(): void
    {
        $findings = $this->analysePaths([
            'tests/Fixtures/TestQuality/phpunit-core-smells.php',
            'tests/Fixtures/TestQuality/pest-smells.php',
        ]);

        self::assertRuleCount(NoAssertionsRule::ID, 4, $findings);
        self::assertRuleCount(TrivialAssertionRule::ID, 2, $findings);
    }

    /**
     * Verify core control flow and flakiness smells are detected.
     *
     * @return void
     */
    public function testCoreControlFlowAndFlakinessSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-core-smells.php');

        self::assertRuleCount(ConditionalTestLogicRule::ID, 1, $findings);
        // sleep + time + microtime + new DateTime('now') + new DateTimeImmutable() - frozen DateTime is not flagged
        self::assertRuleCount(SleepInTestRule::ID, 5, $findings);
    }

    /**
     * Verify mechanics smells are detected.
     *
     * @return void
     */
    public function testMechanicsSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-mechanics-smells.php');

        self::assertRuleCount(ExcessiveMockingRule::ID, 1, $findings);
        self::assertRuleCount(MockOnlyTestRule::ID, 1, $findings);
        self::assertRuleCount(MysteryGuestRule::ID, 1, $findings);
        self::assertRuleCount(MagicNumberAssertionRule::ID, 1, $findings);
        // ReflectionMethod + ReflectionClass + Closure::bind - one finding per test scope
        self::assertRuleCount(PrivateReflectionRule::ID, 3, $findings);
        self::assertRuleCount(DataProviderAnnotationRule::ID, 1, $findings);
        self::assertRuleCount(TrivialSnapshotRule::ID, 1, $findings);
        self::assertRuleCount(SetupBloatRule::ID, 1, $findings);
        self::assertRuleCount(SkippedWithoutReasonRule::ID, 1, $findings);
    }

    /**
     * Verify advanced heuristic smells are detected.
     *
     * @return void
     */
    public function testAdvancedHeuristicSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-advanced-smells.php');

        self::assertRuleCount(EagerTestRule::ID, 1, $findings);
        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
        self::assertRuleCount(SutNotCalledRule::ID, 1, $findings);
        // 1 mixed-style class finding (MixedNamingQualityTest) + 2 poor-name method findings (PoorlyNamedTest::testProcessOrderWorks, ::testProcessOrder1)
        self::assertRuleCount(TestNamingConsistencyRule::ID, 3, $findings);
    }

    /**
     * Verify eager test ignores result observation calls after a single act.
     *
     * @return void
     */
    public function testEagerTestIgnoresResultObservationCallsAfterSingleAct(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/eager-test-observation-cases.php'),
            static fn (Finding $finding): bool => $finding->ruleId === EagerTestRule::ID,
        ));

        self::assertCount(1, $findings);
        self::assertSame('MultipleBehaviorQualityTest::testProcessesOrderAndSendsReceipt()', $findings[0]->symbol);
    }

    /**
     * Verify eager-test reports real multi-act cases with stable metadata.
     *
     * @return void
     */
    public function testEagerTestReportsRealMultiActCasesWithStableMetadata(): void
    {
        $findings = $this->eagerMutationFindings();
        $symbols  = array_map(static fn (Finding $finding): ?string => $finding->symbol, $findings);

        self::assertSame([
            'EagerPositiveMutationCasesTest::testProcessesOrderAndSendsReceipt()',
            'EagerPositiveMutationCasesTest::testStaticServiceHandlesTwoBehaviors()',
            'EagerPositiveMutationCasesTest::testNewServiceHandlesTwoBehaviors()',
            'EagerPositiveMutationCasesTest::testPropertyServiceHandlesTwoBehaviors()',
            'EagerPositiveMutationCasesTest::testObservationNamedMethodsStillCountOnDomainReceiver()',
            'EagerPositiveMutationCasesTest::testSkipsNoiseBeforeRealSutCalls()',
            'EagerPositiveMutationCasesTest::testSkipsResultObservationBeforeRealSutCalls()',
            'EagerPositiveMutationCasesTest::testDomainStartWaitStopMethodsAreSutCalls()',
            'EagerPositiveMutationCasesTest::testReceiverCaseIsNormalisedForVariables()',
            'EagerPositiveMutationCasesTest::testReceiverCaseIsNormalisedForStaticCalls()',
            'EagerPositiveMutationCasesTest::testReceiverCaseIsNormalisedForNewExpressions()',
            'EagerPositiveMutationCasesTest::testReceiverCaseIsNormalisedForProperties()',
            'EagerPositiveMutationCasesTest::testKnownReceiverNonObservationMethodsStillCount()',
            'EagerPositiveMutationCasesTest::testLargestReceiverTieKeepsFirstReceiver()',
        ], $symbols);

        $expectedAssertionCount = 3;
        self::assertSame($expectedAssertionCount, $findings[0]->metadata['assertions'] ?? null);
        self::assertSame(['processorder', 'sendreceipt', 'audittrailwritten'], $findings[0]->metadata['sutCalls'] ?? null);
        self::assertSame(['processorder', 'sendreceipt'], $findings[1]->metadata['sutCalls'] ?? null);
        self::assertSame(['getstatus', 'hasreceipt'], $findings[4]->metadata['sutCalls'] ?? null);
        self::assertSame(['processorder', 'sendreceipt'], $findings[13]->metadata['sutCalls'] ?? null);
    }

    /**
     * Verify eager-test ignores assertion, harness, and observation noise.
     *
     * @return void
     */
    public function testEagerTestIgnoresAssertionHarnessAndObservationNoise(): void
    {
        $findings = $this->eagerMutationFindings();
        $symbols  = array_map(static fn (Finding $finding): ?string => $finding->symbol, $findings);

        self::assertNotContains('EagerNegativeMutationCasesTest::testTwoAssertionMultiActStaysBelowDefaultThreshold()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testAssertionHelperCallsDoNotBecomeSutCalls()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testDirectThisHelpersDoNotBecomeSutCalls()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testAssertionNamedCollaboratorCallsDoNotBecomeSutCalls()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testNestedSutCallsInsideAssertionsAreObservations()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testMockExpectationCallsDoNotBecomeSutCalls()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testGenericSetTimeoutIsSetupNotSutExercise()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testProcessHarnessCallsDoNotBecomeSutExercise()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testUppercaseProcessHarnessReceiverIsStillRecognised()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testResultVariableObservationsDoNotBecomeSutExercise()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testKnownObservationReceiversDoNotBecomeSutExercise()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testCountObservationDoesNotPairWithNonObservationOnKnownReceiver()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testDistinctStaticReceiversDoNotCollapseTogether()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testDistinctVariableReceiversDoNotCollapseTogether()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testDistinctNewReceiversDoNotCollapseTogether()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testDistinctPropertyNamesDoNotCollapseTogether()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testDistinctPropertyOwnersDoNotCollapseTogether()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testSecondResultVariableObservationsRemainSkipped()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testResultVariablesAfterNonVariableAssignmentRemainSkipped()', $symbols);
        self::assertNotContains('EagerNegativeMutationCasesTest::testChainedResultVariableObservationsRemainSkipped()', $symbols);
    }

    /**
     * Verify eager-test treats fractional assertion thresholds as integer minima.
     *
     * @return void
     */
    public function testEagerTestCastsFractionalAssertionThreshold(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            EagerTestRule::ID,
            new RuleSettings(true, ['minAssertions' => 2.5]),
        );
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/eager-test-mutation-cases.php', $config),
            static fn (Finding $finding): bool => $finding->ruleId === EagerTestRule::ID,
        ));
        $symbols = array_map(static fn (Finding $finding): ?string => $finding->symbol, $findings);

        self::assertContains('EagerNegativeMutationCasesTest::testTwoAssertionMultiActStaysBelowDefaultThreshold()', $symbols);
    }

    /**
     * Verify SUT-not-called only flags leading method-style test names.
     *
     * @return void
     */
    public function testSutNotCalledOnlyFlagsLeadingMethodStyleTestNames(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/sut-not-called-heuristic.php'),
            static fn (Finding $finding): bool => $finding->ruleId === SutNotCalledRule::ID,
        ));

        self::assertCount(1, $findings);
        self::assertSame('SutNotCalledHeuristicTest::testCalculateTotalReturnsExpectedValue()', $findings[0]->symbol);
    }

    /**
     * Verify magic number assertion ignores contextual numeric contracts.
     *
     * @return void
     */
    public function testMagicNumberAssertionIgnoresContextualNumericContracts(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/magic-number-assertion-heuristic.php'),
            static fn (Finding $finding): bool => $finding->ruleId === MagicNumberAssertionRule::ID,
        ));

        self::assertCount(1, $findings);
        self::assertSame('MagicNumberHeuristicTest::testOpaqueBusinessNumberIsFlagged()', $findings[0]->symbol);
    }

    /**
     * Verify non candidate cases are not flagged by selected rules.
     *
     * @return void
     */
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

    /**
     * Verify extends production class detected and allows test case descendants.
     *
     * @return void
     */
    public function testExtendsProductionClassDetectedAndAllowsTestCaseDescendants(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/extends-production.php');

        self::assertRuleCount(ExtendsProductionClassRule::ID, 1, $findings);
    }

    /**
     * Verify test method too long detected and ignores whitespace lines.
     *
     * @return void
     */
    public function testTestMethodTooLongDetectedAndIgnoresWhitespaceLines(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/test-method-too-long.php');

        self::assertRuleCount(TestMethodTooLongRule::ID, 1, $findings);
    }

    /**
     * Verify empty data provider detected and yielding provider is allowed.
     *
     * @return void
     */
    public function testEmptyDataProviderDetectedAndYieldingProviderIsAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/empty-data-provider.php');

        self::assertRuleCount(EmptyDataProviderRule::ID, 2, $findings);
    }

    /**
     * Verify loop assertion without message detected and assertion with message allowed.
     *
     * @return void
     */
    public function testLoopAssertionWithoutMessageDetectedAndAssertionWithMessageAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/loop-assertion-without-message.php');

        self::assertRuleCount(LoopAssertionWithoutMessageRule::ID, 3, $findings);
    }

    /**
     * Verify unused mock detected and used mocks allowed.
     *
     * @return void
     */
    public function testUnusedMockDetectedAndUsedMocksAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/unused-mock.php');

        self::assertRuleCount(UnusedMockRule::ID, 2, $findings);
    }

    /**
     * Verify exception type only detected and paired assertions allowed.
     *
     * @return void
     */
    public function testExceptionTypeOnlyDetectedAndPairedAssertionsAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/exception-type-only.php');

        self::assertRuleCount(ExceptionTypeOnlyRule::ID, 1, $findings);
    }

    /**
     * Verify tautological type assertion detected and cross class assertions allowed.
     *
     * @return void
     */
    public function testTautologicalTypeAssertionDetectedAndCrossClassAssertionsAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/tautological-type-assertion.php');

        self::assertRuleCount(TautologicalTypeAssertionRule::ID, 2, $findings);
    }

    /**
     * Verify global state mutation detected and cleaned up class allowed.
     *
     * @return void
     */
    public function testGlobalStateMutationDetectedAndCleanedUpClassAllowed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/global-state-mutation.php');

        // 3 mutations in the leaky class (superglobal write + putenv + ini_set), 0 in classes with local or inherited cleanup, 0 in the read-only class.
        self::assertRuleCount(GlobalStateMutationRule::ID, 3, $findings);
    }

    /**
     * Verify mock without expectation detected with variant severities.
     *
     * @return void
     */
    public function testMockWithoutExpectationDetectedWithVariantSeverities(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/mock-without-expectation.php');

        self::assertRuleCount(MockWithoutExpectationRule::ID, 2, $findings);

        $mockFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === MockWithoutExpectationRule::ID,
        ));
        $variants = array_values(array_filter(
            array_map(static fn (Finding $finding): mixed => $finding->metadata['variant'] ?? null, $mockFindings),
            'is_string',
        ));
        $variables = array_values(array_filter(
            array_map(static fn (Finding $finding): mixed => $finding->metadata['variable'] ?? null, $mockFindings),
            'is_string',
        ));

        sort($variants);
        self::assertSame(['dead-mock', 'stub-only'], $variants);
        self::assertNotContains('fake', $variables);
    }

    /**
     * Verify repeated structure missing data provider detected and data provider users ignored.
     *
     * @return void
     */
    public function testRepeatedStructureMissingDataProviderDetectedAndDataProviderUsersIgnored(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/repeated-structure-missing-data-provider.php'),
            static fn (Finding $finding): bool => $finding->ruleId === RepeatedStructureMissingDataProviderRule::ID,
        ));

        self::assertCount(2, $findings);
        self::assertSame('RepeatedShapesTest::testSumsAlpha()', $findings[0]->symbol);
        self::assertSame(['testSumsAlpha', 'testSumsBeta', 'testSumsGamma'], $findings[0]->metadata['methods'] ?? null);
        self::assertSame(3, $findings[0]->metadata['count'] ?? null);
        self::assertStringContainsString('RepeatedShapesTest has 3 structurally identical test methods', $findings[0]->message);
        self::assertSame('RepeatedControlFlowTest::testComplexAlpha()', $findings[1]->symbol);
        self::assertSame(['testComplexAlpha', 'testComplexBeta', 'testComplexGamma'], $findings[1]->metadata['methods'] ?? null);
        self::assertSame(3, $findings[1]->metadata['count'] ?? null);
    }

    /**
     * Verify multiple AAA cycles fires by default and with explicit config.
     *
     * @return void
     */
    public function testMultipleAaaCyclesFiresByDefaultAndWithExplicitConfig(): void
    {
        $defaultFindings = $this->analysePath('tests/Fixtures/TestQuality/multiple-aaa-cycles.php');
        self::assertRuleCount(MultipleAaaCyclesRule::ID, 1, $defaultFindings);

        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/enable-multiple-aaa-cycles.yaml',
            $registry,
        );
        $configuredFindings = $this->analysePaths(
            ['tests/Fixtures/TestQuality/multiple-aaa-cycles.php'],
            $config,
        );

        self::assertRuleCount(MultipleAaaCyclesRule::ID, 1, $configuredFindings);
    }

    /**
     * Verify multiple AAA cycles does not double count inline act assert after act statement.
     *
     * @return void
     */
    public function testMultipleAaaCyclesDoesNotDoubleCountInlineActAssertAfterActStatement(): void
    {
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(MultipleAaaCyclesRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
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

    /**
     * Verify multiple AAA cycles can exempt accepted broad contract-test files.
     *
     * @return void
     */
    public function testMultipleAaaCyclesHonoursIgnoredPathPatterns(): void
    {
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(MultipleAaaCyclesRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            MultipleAaaCyclesRule::ID,
            new RuleSettings(
                true,
                $settings->thresholds,
                array_merge($settings->options, ['ignoredPathPatterns' => ['tests/Fixtures/TestQuality/*']]),
            ),
        );

        $findings = $this->analysePath('tests/Fixtures/TestQuality/multiple-aaa-cycles.php', $config);

        self::assertRuleCount(MultipleAaaCyclesRule::ID, 0, $findings);
    }

    /**
     * Verify testdox readability fires by default and with explicit config.
     *
     * @return void
     */
    public function testTestdoxReadabilityFiresByDefaultAndWithExplicitConfig(): void
    {
        $defaultFindings = $this->analysePath('tests/Fixtures/TestQuality/testdox-readability.php');
        self::assertRuleCount(TestdoxReadabilityRule::ID, 1, $defaultFindings);

        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/enable-testdox-readability.yaml',
            $registry,
        );
        $configuredFindings = $this->analysePaths(
            ['tests/Fixtures/TestQuality/testdox-readability.php'],
            $config,
        );

        self::assertRuleCount(TestdoxReadabilityRule::ID, 1, $configuredFindings);
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
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @return list<Finding>
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Build eager mutation findings for the test-quality rule.
     *
     * @return list<Finding>
     */
    private function eagerMutationFindings(): array
    {
        return array_values(array_filter(
            $this->analysePath('tests/Fixtures/TestQuality/eager-test-mutation-cases.php'),
            static fn (Finding $finding): bool => $finding->ruleId === EagerTestRule::ID,
        ));
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
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }
}
