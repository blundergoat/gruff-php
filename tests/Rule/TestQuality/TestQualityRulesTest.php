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
use GruffPhp\Rules\TestQuality\DataProviderAnnotationRule;
use GruffPhp\Rules\TestQuality\EmptyDataProviderRule;
use GruffPhp\Rules\TestQuality\ExceptionTypeOnlyRule;
use GruffPhp\Rules\TestQuality\GlobalStateMutationRule;
use GruffPhp\Rules\TestQuality\LoopAssertionWithoutMessageRule;
use GruffPhp\Rules\TestQuality\MockingDomainObjectRule;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use GruffPhp\Rules\TestQuality\PrivateReflectionRule;
use GruffPhp\Rules\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rules\TestQuality\SetupBloatRule;
use GruffPhp\Rules\TestQuality\StaticAnalysisRedundantTestRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use GruffPhp\Rules\TestQuality\TestdoxReadabilityRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rules\TestQuality\TrivialSnapshotRule;
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
     * Verify PHPUnit and pest test scopes support no assertion checks.
     *
     * @return void
     */
    public function testPhpUnitAndPestTestScopesSupportNoAssertionChecks(): void
    {
        $findings = $this->analysePaths([
                                            'tests/Fixtures/TestQuality/phpunit-core-smells.php',
                                            'tests/Fixtures/TestQuality/pest-smells.php',
                                        ]);

        self::assertRuleCount(NoAssertionsRule::ID, 4, $findings);
    }

    /**
     * Verify mechanics smells are detected.
     *
     * @return void
     */
    public function testMechanicsSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-mechanics-smells.php');

        // ReflectionMethod + ReflectionClass + Closure::bind - one finding per test scope
        self::assertRuleCount(PrivateReflectionRule::ID, 3, $findings);
        self::assertRuleCount(DataProviderAnnotationRule::ID, 1, $findings);
        self::assertRuleCount(TrivialSnapshotRule::ID, 1, $findings);
        self::assertRuleCount(SetupBloatRule::ID, 1, $findings);
    }

    /**
     * Verify advanced heuristic smells are detected.
     *
     * @return void
     */
    public function testAdvancedHeuristicSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-advanced-smells.php');

        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
        self::assertRuleCount(SutNotCalledRule::ID, 1, $findings);
        // 1 mixed-style class finding (MixedNamingQualityTest) + 2 poor-name method findings (PoorlyNamedTest::testProcessOrderWorks, ::testProcessOrder1)
        self::assertRuleCount(TestNamingConsistencyRule::ID, 3, $findings);
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
     * Verify non candidate cases are not flagged by selected rules.
     *
     * @return void
     */
    public function testNonCandidateCasesAreNotFlaggedBySelectedRules(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/non-candidates.php');

        self::assertRuleCount(DataProviderAnnotationRule::ID, 0, $findings);
        self::assertRuleCount(NoAssertionsRule::ID, 0, $findings);
        self::assertRuleCount(EmptyDataProviderRule::ID, 0, $findings);
        self::assertRuleCount(LoopAssertionWithoutMessageRule::ID, 0, $findings);
        self::assertRuleCount(ExceptionTypeOnlyRule::ID, 0, $findings);
        self::assertRuleCount(StaticAnalysisRedundantTestRule::ID, 0, $findings);
        self::assertRuleCount(GlobalStateMutationRule::ID, 0, $findings);
        self::assertRuleCount(RepeatedStructureMissingDataProviderRule::ID, 0, $findings);
        self::assertRuleCount(TestdoxReadabilityRule::ID, 0, $findings);
        self::assertRuleCount(MockingDomainObjectRule::ID, 0, $findings);
        // The phpunit-* rules are project-level (they fire on .phpunit.xml.dist at the project root),
        // not source-file rules, so they're intentionally excluded from this fixture-content assertion.
    }

    /**
     * Verify each single-rule fixture emits exactly the expected finding count.
     *
     * @param string $fixture       - Fixture path under tests/Fixtures/TestQuality to analyse.
     * @param string $ruleId        - Rule identifier whose findings are counted.
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
            'empty-data-provider flags two empty providers' => ['tests/Fixtures/TestQuality/empty-data-provider.php', EmptyDataProviderRule::ID, 2],
            'loop-assertion-without-message flags three messageless loop assertions' => ['tests/Fixtures/TestQuality/loop-assertion-without-message.php', LoopAssertionWithoutMessageRule::ID, 3],
            'exception-type-only flags one type-only expectation' => ['tests/Fixtures/TestQuality/exception-type-only.php', ExceptionTypeOnlyRule::ID, 1],
            'global-state-mutation flags three leaks in the leaky class' => ['tests/Fixtures/TestQuality/global-state-mutation.php', GlobalStateMutationRule::ID, 3],
        ];
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
        $this->assertSmellOwnedSolelyByNeighbour('tests/Fixtures/TestQuality/exception-type-only.php', ExceptionTypeOnlyRule::ID, 1);
        $this->assertSmellOwnedSolelyByNeighbour('tests/Fixtures/TestQuality/phpunit-mechanics-smells.php', PrivateReflectionRule::ID, 3);
    }

    /**
     * Verify repeated structure missing data provider detected and data provider users ignored.
     *
     * @return void
     */
    public function testRepeatedStructureMissingDataProviderDetectedAndDataProviderUsersIgnored(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry);
        $settings = $config->ruleSettings(RepeatedStructureMissingDataProviderRule::ID);
        $config = $config->withRuleSettings(RepeatedStructureMissingDataProviderRule::ID, new RuleSettings(
            enabled: $settings->enabled,
            thresholds: $settings->thresholds,
            options: array_replace($settings->options, ['groupFindings' => false]),
            severityThreshold: $settings->severityThreshold,
            excludeFromScore: $settings->excludeFromScore,
        ));
        $findings = array_values(array_filter(
                                     $this->analysePath('tests/Fixtures/TestQuality/repeated-structure-missing-data-provider.php', $config),
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
     * @param string        $ruleId        - Rule identifier whose findings are counted.
     * @param int           $expectedCount - Exact number of findings the rule must emit.
     * @param list<Finding> $findings      - Findings to filter down to the requested rule id.
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
     * @param string $fixture     - Fixture path whose neighbouring-rule ownership is verified.
     * @param string $ownerRuleId - Rule identifier expected to solely own the fixture's smell.
     * @param int    $ownerCount  - Exact number of findings the owning rule must emit.
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
     * @param string        $key      - Metadata key to read.
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
     * @param string              $path   - Single fixture path to analyse.
     * @param AnalysisConfig|null $config - Overriding config, or null to enable the default-off calibration rules.
     *
     * @return list<Finding> - every finding emitted across all rules for the single fixture; empty when nothing fired
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param list<string>        $paths  - Fixture paths to parse and analyse together.
     * @param AnalysisConfig|null $config - Overriding config, or null to enable the default-off calibration rules.
     *
     * @return list<Finding> - every finding the default registry emits across the combined fixtures; empty when nothing fired
     */
    private function analysePaths(array $paths, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $units    = array_map(fn(string $path): AnalysisUnit => $this->unitForPath($path), $paths);
        $config ??= (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry);

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config),
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
