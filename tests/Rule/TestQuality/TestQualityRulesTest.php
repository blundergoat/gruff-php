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
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class TestQualityRulesTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    public function testPhpUnitAndPestTestScopesSupportNoAssertionAndTrivialAssertionChecks(): void
    {
        $findings = $this->analysePaths([
            'tests/Fixtures/M13/TestQuality/phpunit-core-smells.php',
            'tests/Fixtures/M13/TestQuality/pest-smells.php',
        ]);

        self::assertRuleCount(NoAssertionsRule::ID, 4, $findings);
        self::assertRuleCount(TrivialAssertionRule::ID, 2, $findings);
    }

    public function testCoreControlFlowAndFlakinessSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M13/TestQuality/phpunit-core-smells.php');

        self::assertRuleCount(ConditionalTestLogicRule::ID, 1, $findings);
        self::assertRuleCount(LoopInTestRule::ID, 1, $findings);
        // sleep + time + microtime + new DateTime('now') + new DateTimeImmutable() — frozen DateTime is not flagged
        self::assertRuleCount(SleepInTestRule::ID, 5, $findings);
    }

    public function testMechanicsSmellsAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M13/TestQuality/phpunit-mechanics-smells.php');

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
        $findings = $this->analysePath('tests/Fixtures/M13/TestQuality/phpunit-advanced-smells.php');

        self::assertRuleCount(EagerTestRule::ID, 1, $findings);
        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
        self::assertRuleCount(SutNotCalledRule::ID, 1, $findings);
        // 1 mixed-style class finding (MixedNamingQualityTest) + 2 poor-name method findings (PoorlyNamedTest::testProcessOrderWorks, ::testProcessOrder1)
        self::assertRuleCount(TestNamingConsistencyRule::ID, 3, $findings);
    }

    public function testNonCandidateCasesAreNotFlaggedBySelectedRules(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M13/TestQuality/non-candidates.php');

        self::assertRuleCount(DataProviderAnnotationRule::ID, 0, $findings);
        self::assertRuleCount(SkippedWithoutReasonRule::ID, 0, $findings);
        self::assertRuleCount(ConditionalTestLogicRule::ID, 0, $findings);
        self::assertRuleCount(SleepInTestRule::ID, 0, $findings);
        self::assertRuleCount(NoAssertionsRule::ID, 0, $findings);
    }

    public function testTestQualityRulesRespectConfigDisables(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/M13/Config/disable-no-assertions.json',
            $registry,
        );

        $findings = $this->analysePaths(
            ['tests/Fixtures/M13/TestQuality/phpunit-core-smells.php'],
            $config,
        );

        self::assertRuleCount(NoAssertionsRule::ID, 0, $findings);
        self::assertRuleCount(TrivialAssertionRule::ID, 1, $findings);
    }

    public function testNonTestClassMethodsWithTestPrefixAreNotAnalysed(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M19/TestQuality/non-test-class.php');

        $testQualityFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'test-quality.'),
        ));

        self::assertSame([], $testQualityFindings, 'Library code with test* method names must not trigger test-quality rules.');
    }

    public function testCumulativeFixtureRepresentsEveryStaticTestQualityRule(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/M13/TestQuality/cumulative-test-quality.php'),
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
