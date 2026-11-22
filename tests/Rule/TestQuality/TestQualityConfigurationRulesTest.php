<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Results\Finding\Finding;
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
use GruffPhp\Rules\TestQuality\PhpUnitCoverageSourceMissingRule;
use GruffPhp\Rules\TestQuality\PhpUnitDeprecationsNotFatalRule;
use GruffPhp\Rules\TestQuality\PhpUnitStrictFlagsMissingRule;
use GruffPhp\Rules\TestQuality\PrivateReflectionRule;
use GruffPhp\Rules\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rules\TestQuality\TestNamingConsistencyRule;
use GruffPhp\Rules\TestQuality\TrivialSnapshotRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers test-quality rule configuration and PHPUnit configuration diagnostics.
 * Uses fixture projects to check strict, lax, legacy, and missing PHPUnit configuration.
 * Checks selected rule coverage and fingerprint uniqueness in the cumulative fixture.
 */
final class TestQualityConfigurationRulesTest extends TestCase
{
    /** Project root used to resolve fixtures and load analysis configuration. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify the fixture produces zero mocking-domain-object findings without patterns and two with a domain pattern.
     *
     * @return void - Asserts the configured mocking-domain-object finding counts.
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
     * @return void - Asserts one lax-config finding with the expected missing flags and none for strict config.
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
     * @return void - Asserts one finding for lax config and none for strict config.
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
     * @return void - Asserts one lax-config finding and none for strict or legacy-whitelist config.
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
     * @return void - Asserts no findings from the strictness, deprecation, and coverage rules.
     */
    public function testPhpUnitRulesStaySilentWhenNoConfigFileIsPresent(): void
    {
        $context = $this->phpUnitContext('tests/Fixtures/PhpUnitConfig/no-config');

        self::assertCount(0, (new PhpUnitStrictFlagsMissingRule())->analyse($this->phpUnitDummyUnit(), $context));
        self::assertCount(0, (new PhpUnitDeprecationsNotFatalRule())->analyse($this->phpUnitDummyUnit(), $context));
        self::assertCount(0, (new PhpUnitCoverageSourceMissingRule())->analyse($this->phpUnitDummyUnit(), $context));
    }

    /**
     * Verify the no-assertions rule produces no findings with its disabling configuration.
     *
     * @return void - Asserts the no-assertions finding count is zero.
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
    }

    /**
     * Verify test-prefixed methods in the non-test-class fixture produce no test-quality findings.
     *
     * @return void - Asserts no test-quality finding is attributed to the non-test-class fixture.
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
     * Verify the cumulative fixture emits all explicitly expected rule IDs with distinct fingerprints.
     *
     * @return void - Asserts no expected rule ID is missing and no finding fingerprint is duplicated.
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
     * @return AnalysisUnit - Parsed test fixture used as the subject for PHPUnit configuration checks.
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
     * @param string        $ruleId        - Rule identifier whose findings are counted.
     * @param int           $expectedCount - Exact number of findings the rule must emit.
     * @param list<Finding> $findings      - Findings to filter down to the requested rule id.
     *
     * @return void - Asserts that filtering by rule ID yields exactly the expected number of findings.
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
     * List the rule IDs required by the cumulative fixture assertion.
     *
     * @return list<string> - Rule IDs that the cumulative fixture's findings must include.
     */
    private function expectedRuleIds(): array
    {
        return [
            NoAssertionsRule::ID,
            TestLongerThanSutRule::ID,
            TestNamingConsistencyRule::ID,
            PrivateReflectionRule::ID,
            DataProviderAnnotationRule::ID,
            TrivialSnapshotRule::ID,
            SutNotCalledRule::ID,
            EmptyDataProviderRule::ID,
            LoopAssertionWithoutMessageRule::ID,
            ExceptionTypeOnlyRule::ID,
            GlobalStateMutationRule::ID,
            RepeatedStructureMissingDataProviderRule::ID,
        ];
    }

    /**
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param string              $path   - Single fixture path to analyse.
     * @param AnalysisConfig|null $config - Overriding config, or null to enable the default-off calibration rules.
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
     * @param list<string>        $paths  - Fixture paths to parse and analyse together.
     * @param AnalysisConfig|null $config - Overriding config, or null to enable the default-off calibration rules.
     *
     * @return list<Finding> - findings the default registry emits across all parsed fixtures combined; empty when none fire
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
     * @param string $path - Project-root-relative fixture path to parse.
     *
     * @return AnalysisUnit - the fixture parsed from the project-root-relative path, retaining that path as its display name
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        return (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $path, $path));
    }
}
