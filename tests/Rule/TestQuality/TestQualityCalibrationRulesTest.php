<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\TestQuality\MysteryGuestRule;
use GruffPhp\Rule\TestQuality\TestLongerThanSutRule;
use GruffPhp\Rule\TestQuality\TestMethodTooLongRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers calibration-specific test-quality rule shapes.
 */
final class TestQualityCalibrationRulesTest extends TestCase
{
    /** Project root used by fixture tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify mystery guest ignores files created by the test or written by the SUT.
     *
     * @return void No return value.
     */
    public function testMysteryGuestIgnoresPreparedAndSutOwnedPaths(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/integration-harness-exemptions.php');

        self::assertRuleCount(MysteryGuestRule::ID, 1, $findings);
    }

    /**
     * Verify test-longer-than-SUT ignores command and process integration harness calls.
     *
     * @return void No return value.
     */
    public function testTestLongerThanSutIgnoresIntegrationHarnessCalls(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/integration-harness-exemptions.php');

        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
    }

    /**
     * Verify path overrides can raise the long-test threshold for integration-heavy folders.
     *
     * @return void No return value.
     */
    public function testTestMethodTooLongSupportsPathOverrides(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            TestMethodTooLongRule::ID,
            new RuleSettings(
                true,
                ['maxMeaningfulLines' => 25],
                ['pathOverrides' => ['tests/Fixtures/TestQuality/**' => 40]],
            ),
        );
        $findings = $this->analysePath('tests/Fixtures/TestQuality/test-method-too-long.php', $config);

        self::assertRuleCount(TestMethodTooLongRule::ID, 0, $findings);
    }

    /**
     * @param list<Finding> $findings
     * @return void No return value.
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
     * @return list<Finding>
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $unit     = $this->unitForPath($path);

        return $registry->analyse(
            [$unit],
            new RuleContext(self::PROJECT_ROOT, $config ?? AnalysisConfig::fromRegistry($registry)),
        );
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path Filesystem path.
     * @return AnalysisUnit Fixture value.
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        return (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $path, $path));
    }
}
