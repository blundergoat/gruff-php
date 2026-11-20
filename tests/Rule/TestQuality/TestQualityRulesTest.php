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
 * Covers test-quality rules using parsed PHPUnit and Pest fixtures.
 * Asserts finding counts, selected symbols, candidate metadata, and advisory severity.
 * Exercises the default registry, calibration configuration, and explicit rule options.
 */
final class TestQualityRulesTest extends TestCase
{
    /** Project root for fixture paths, configuration loading, and rule context. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Count NoAssertionsRule findings across the combined PHPUnit and Pest fixtures.
     *
     * @return void - Asserts four NoAssertionsRule findings across the two fixtures.
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
     * @return void - Asserts three reflection findings and one each for provider annotations, snapshots, and setup bloat.
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
     * @return void - Asserts one test-length finding, one missing-SUT finding, and three naming findings.
     */
    public function testAdvancedHeuristicSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/phpunit-advanced-smells.php');

        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
        self::assertRuleCount(SutNotCalledRule::ID, 1, $findings);
        // MixedNamingQualityTest mixes styles; PoorlyNamedTest contains testProcessOrderWorks and testProcessOrder1.
        self::assertRuleCount(TestNamingConsistencyRule::ID, 3, $findings);
    }

    /**
     * Checks the heuristic fixture's single SUT-not-called finding and its test-method symbol.
     *
     * @return void - Asserts one finding for SutNotCalledHeuristicTest::testCalculateTotalReturnsExpectedValue().
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
     * Checks that the ten selected content-based rules emit no findings for the non-candidate fixture.
     *
     * @return void - Asserts zero findings for the ten listed rule IDs.
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
        // The phpunit-* rules inspect project-root configuration, so these fixture-content checks exclude them.

    }

    /**
     * Checks each fixture's finding count for the selected rule.
     *
     * @param string $fixture       - Fixture path under tests/Fixtures/TestQuality to analyse.
     * @param string $ruleId        - Rule identifier whose findings are counted.
     * @param int    $expectedCount - Exact number of findings the rule must emit on the fixture.
     *
     * @return void - Asserts the selected rule's total, without checking individual finding identities.
     */
    #[DataProvider('singleRuleFixtureProvider')]
    public function testSingleRuleFixtureEmitsExpectedCount(string $fixture, string $ruleId, int $expectedCount): void
    {
        self::assertRuleCount($ruleId, $expectedCount, $this->analysePath($fixture));
    }

    /**
     * Supplies fixtures, selected rule IDs, and expected totals for aggregate finding-count assertions.
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
     * Checks candidate variants, evidence symbols, advisory severity, and confidence metadata.
     *
     * @return void - Asserts six candidates, their metadata lists, and each candidate's severity, confidence, and message marker.
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
     * Checks that neighbouring rules retain ownership without redundant-test findings.
     *
     * @return void - Asserts zero redundant-test findings alongside each expected neighbouring-rule count.
     */
    public function testStaticAnalysisRedundantCandidatesRespectNeighbouringRules(): void
    {
        $this->assertSmellOwnedSolelyByNeighbour('tests/Fixtures/TestQuality/exception-type-only.php', ExceptionTypeOnlyRule::ID, 1);
        $this->assertSmellOwnedSolelyByNeighbour('tests/Fixtures/TestQuality/phpunit-mechanics-smells.php', PrivateReflectionRule::ID, 3);
    }

    /**
     * Checks two repeated-structure groups with per-group symbols and method metadata.
     *
     * @return void - Asserts both groups' symbols, method lists, and sizes, plus the first group's message marker.
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
     * Checks one readability finding under both the default and explicit configurations.
     *
     * @return void - Asserts one TestdoxReadabilityRule finding under each configuration.
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
     * @return void - Asserts the filtered count without checking finding symbols or metadata.
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
     * Checks a neighbouring rule's count and the absence of redundant-test findings.
     *
     * @param string $fixture     - Fixture path analysed for the selected rule counts.
     * @param string $ownerRuleId - Neighbouring rule whose findings are counted.
     * @param int    $ownerCount  - Exact expected finding count for the neighbouring rule.
     *
     * @return void - Asserts those two counts without excluding findings from other rules.
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
     * @return list<string> - Strings sorted lexically, with duplicates retained; missing or non-string values are omitted.
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
     * @param string              $path   - Fixture path relative to the project root.
     * @param AnalysisConfig|null $config - Overriding config, or null to enable the default-off calibration rules.
     *
     * @return list<Finding> - Enabled-rule findings for the fixture and project context; empty when none are emitted.
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param list<string>        $paths  - Fixture paths relative to the project root, parsed and analysed together.
     * @param AnalysisConfig|null $config - Overriding config, or null to enable the default-off calibration rules.
     *
     * @return list<Finding> - Enabled-rule findings from the combined fixtures and project context; empty when none are emitted.
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
     * @param string $path - Fixture path relative to the project root.
     *
     * @return AnalysisUnit - Unit using $path as its display name; read or parse failures appear in its diagnostics.
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }
}
