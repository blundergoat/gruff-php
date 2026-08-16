<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
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
use GruffPhp\Rules\TestQuality\MultipleAaaCyclesRule;
use GruffPhp\Rules\TestQuality\MysteryGuestRule;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use GruffPhp\Rules\TestQuality\PrivateReflectionRule;
use GruffPhp\Rules\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rules\TestQuality\SetupBloatRule;
use GruffPhp\Rules\TestQuality\SkippedWithoutReasonRule;
use GruffPhp\Rules\TestQuality\SleepInTestRule;
use GruffPhp\Rules\TestQuality\StaticAnalysisRedundantTestRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use GruffPhp\Rules\TestQuality\TautologicalTypeAssertionRule;
use GruffPhp\Rules\TestQuality\TestdoxReadabilityRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\TestQuality\TestMethodTooLongRule;
use GruffPhp\Rules\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rules\TestQuality\TrivialAssertionRule;
use GruffPhp\Rules\TestQuality\TrivialSnapshotRule;
use GruffPhp\Rules\TestQuality\UnusedMockRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the test-quality rule pack across PHPUnit and Pest: assertion smells, eager tests, SUT-not-called, oversized methods, unused mocks,
 * global-state leaks, repeated structures, and per-rule severity options.
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
                                     static fn(Finding $finding): bool => $finding->ruleId === EagerTestRule::ID,
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
        $symbols  = array_map(static fn(Finding $finding): ?string => $finding->symbol, $findings);

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
        $symbols  = array_map(static fn(Finding $finding): ?string => $finding->symbol, $findings);

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
                                     static fn(Finding $finding): bool => $finding->ruleId === EagerTestRule::ID,
                                 ));
        $symbols  = array_map(static fn(Finding $finding): ?string => $finding->symbol, $findings);

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
                                     static fn(Finding $finding): bool => $finding->ruleId === SutNotCalledRule::ID,
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
                                     static fn(Finding $finding): bool => $finding->ruleId === MagicNumberAssertionRule::ID,
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
        self::assertRuleCount(StaticAnalysisRedundantTestRule::ID, 0, $findings);
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
     * Verify each single-rule fixture emits exactly the expected finding count.
     *
     * @param string $fixture - Fixture path under tests/Fixtures/TestQuality to analyse.
     * @param string $ruleId - Rule identifier whose findings are counted.
     * @param int    $expectedCount - Exact number of findings the rule must emit on the fixture.
     *
     * @return void
     */
    #[DataProvider('singleRuleFixtureProvider')]
    public function testSingleRuleFixtureEmitsExpectedCount(string $fixture, string $ruleId, int $expectedCount): void
    {
        self::assertRuleCount($ruleId, $expectedCount, $this->analysePath($fixture));
    }

    /**
     * Provide single-rule fixtures paired with the exact finding count each rule must emit. Each exact count also
     * pins the negative half of the fixture (the allowed shapes that must stay unflagged).
     *
     * @return array<string, array{0: string, 1: string, 2: int}> - Rows of fixture path, rule id, and expected finding count.
     */
    public static function singleRuleFixtureProvider(): array
    {
        return [
            'extends-production flags production-class and unconfigured base parents' => ['tests/Fixtures/TestQuality/extends-production.php', ExtendsProductionClassRule::ID, 2],
            'test-method-too-long flags one oversized method' => ['tests/Fixtures/TestQuality/test-method-too-long.php', TestMethodTooLongRule::ID, 1],
            'empty-data-provider flags two empty providers' => ['tests/Fixtures/TestQuality/empty-data-provider.php', EmptyDataProviderRule::ID, 2],
            'loop-assertion-without-message flags three messageless loop assertions' => ['tests/Fixtures/TestQuality/loop-assertion-without-message.php', LoopAssertionWithoutMessageRule::ID, 3],
            'unused-mock flags two unused mocks' => ['tests/Fixtures/TestQuality/unused-mock.php', UnusedMockRule::ID, 2],
            'exception-type-only flags one type-only expectation' => ['tests/Fixtures/TestQuality/exception-type-only.php', ExceptionTypeOnlyRule::ID, 1],
            'global-state-mutation flags three leaks in the leaky class' => ['tests/Fixtures/TestQuality/global-state-mutation.php', GlobalStateMutationRule::ID, 3],
        ];
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
     * Verify static-analysis-redundant candidates carry evidence and leave behavior clean.
     *
     * @return void
     */
    public function testStaticAnalysisRedundantCandidatesDetectedWithEvidence(): void
    {
        $findings = array_values(array_filter(
                                     $this->analysePath('tests/Fixtures/TestQuality/static-analysis-redundant-test.php'),
                                     static fn(Finding $finding): bool => $finding->ruleId === StaticAnalysisRedundantTestRule::ID,
                                 ));

        self::assertCount(6, $findings);
        self::assertSame(
            [
                'class_exists',
                'enum_exists',
                'interface_exists',
                'method_exists',
                'property_exists',
                'trait_exists',
            ],
            $this->stringMetadataValues($findings, 'variant'),
        );
        self::assertSame(
            [
                'Fixtures\TestQuality\StaticAnalysisRedundantTest\ShapeContract',
                'Fixtures\TestQuality\StaticAnalysisRedundantTest\ShapeService',
                'Fixtures\TestQuality\StaticAnalysisRedundantTest\ShapeService::$label',
                'Fixtures\TestQuality\StaticAnalysisRedundantTest\ShapeService::label()',
                'Fixtures\TestQuality\StaticAnalysisRedundantTest\ShapeStatus',
                'Fixtures\TestQuality\StaticAnalysisRedundantTest\ShapeTrait',
            ],
            $this->stringMetadataValues($findings, 'evidenceSymbol'),
        );

        foreach ($findings as $index => $finding) {
            self::assertSame(Severity::Advisory, $finding->severity, "finding {$index}");
            self::assertSame(Confidence::High, $finding->confidence, "finding {$index}");
            self::assertStringContainsString('static-analysis-redundant candidate', $finding->message, "finding {$index}");
            self::assertSame('high', $finding->metadata['candidateConfidence'] ?? null, "finding {$index}");
        }
    }

    /**
     * Verify static-analysis-redundant candidates do not duplicate neighbouring rule ownership.
     *
     * @return void
     */
    public function testStaticAnalysisRedundantCandidatesRespectNeighbouringRules(): void
    {
        $this->assertSmellOwnedSolelyByNeighbour('tests/Fixtures/TestQuality/tautological-type-assertion.php', TautologicalTypeAssertionRule::ID, 2);
        $this->assertSmellOwnedSolelyByNeighbour('tests/Fixtures/TestQuality/exception-type-only.php', ExceptionTypeOnlyRule::ID, 1);
        $this->assertSmellOwnedSolelyByNeighbour('tests/Fixtures/TestQuality/phpunit-mechanics-smells.php', PrivateReflectionRule::ID, 3);
    }

    /**
     * Verify mock without expectation detected with variant severities.
     *
     * @return void
     */
    public function testMockWithoutExpectationDetectedWithVariantSeverities(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/mock-without-expectation.php');

        self::assertRuleCount(MockWithoutExpectationRule::ID, 3, $findings);

        $mockFindings = array_values(array_filter(
                                         $findings,
                                         static fn(Finding $finding): bool => $finding->ruleId === MockWithoutExpectationRule::ID,
                                     ));
        $variants     = array_values(array_filter(
                                         array_map(static fn(Finding $finding): mixed => $finding->metadata['variant'] ?? null, $mockFindings),
                                         'is_string',
                                     ));
        $variables    = array_values(array_filter(
                                         array_map(static fn(Finding $finding): mixed => $finding->metadata['variable'] ?? null, $mockFindings),
                                         'is_string',
                                     ));

        sort($variants);
        self::assertSame(['dead-mock', 'dead-mock', 'stub-only'], $variants);
        self::assertContains('bareProphecy', $variables);
        self::assertNotContains('assertedProphecy', $variables);
        self::assertNotContains('returningProphecy', $variables);
        self::assertNotContains('expectedProphecy', $variables);
        self::assertNotContains('singleCallProphecy', $variables);
        self::assertNotContains('countedProphecy', $variables);
        self::assertNotContains('observedProphecy', $variables);
        self::assertNotContains('forbiddenProphecy', $variables);
        self::assertNotContains('throwingProphecy', $variables);
        self::assertNotContains('callbackProphecy', $variables);
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
                                     static fn(Finding $finding): bool => $finding->ruleId === RepeatedStructureMissingDataProviderRule::ID,
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

        $registry           = RuleRegistry::defaults();
        $config             = (new ConfigLoader(self::PROJECT_ROOT))->load(
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
                                     static fn(Finding $finding): bool => $finding->ruleId === MultipleAaaCyclesRule::ID
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

        $registry           = RuleRegistry::defaults();
        $config             = (new ConfigLoader(self::PROJECT_ROOT))->load(
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
     * Assert the static-analysis-redundant rule stays silent while a neighbouring rule solely owns the fixture's smell.
     *
     * @param string $fixture - Fixture path whose neighbouring-rule ownership is verified.
     * @param string $ownerRuleId - Rule identifier expected to solely own the fixture's smell.
     * @param int    $ownerCount - Exact number of findings the owning rule must emit.
     *
     * @return void
     */
    private function assertSmellOwnedSolelyByNeighbour(string $fixture, string $ownerRuleId, int $ownerCount): void
    {
        $findings = $this->analysePath($fixture);

        self::assertRuleCount(StaticAnalysisRedundantTestRule::ID, 0, $findings);
        self::assertRuleCount($ownerRuleId, $ownerCount, $findings);
    }

    /**
     * Return sorted string metadata values for stable assertions.
     *
     * @param list<Finding> $findings - Findings whose metadata should be inspected.
     * @param string        $key - Metadata key to read.
     *
     * @return list<string> - String values sorted ascending.
     */
    private function stringMetadataValues(array $findings, string $key): array
    {
        $values = array_values(array_filter(
                                   array_map(static fn(Finding $finding): mixed => $finding->metadata[$key] ?? null, $findings),
                                   'is_string',
                               ));

        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param string              $path - Single fixture path to analyse.
     * @param AnalysisConfig|null $config - Overriding config, or null to use the registry defaults.
     *
     * @return list<Finding> - every finding emitted across all rules for the single fixture; empty when nothing fired
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Build eager mutation findings for the test-quality rule.
     *
     * @return list<Finding> - only EagerTestRule findings from the mutation fixture, in source order; empty when none fire
     */
    private function eagerMutationFindings(): array
    {
        return array_values(array_filter(
                                $this->analysePath('tests/Fixtures/TestQuality/eager-test-mutation-cases.php'),
                                static fn(Finding $finding): bool => $finding->ruleId === EagerTestRule::ID,
                            ));
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param list<string>        $paths - Fixture paths to parse and analyse together.
     * @param AnalysisConfig|null $config - Overriding config, or null to use the registry defaults.
     *
     * @return list<Finding> - every finding the default registry emits across the combined fixtures; empty when nothing fired
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
     * @return AnalysisUnit - the parsed fixture, carrying the project-root-relative path as its display name
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }
}
