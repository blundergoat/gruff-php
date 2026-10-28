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
use GruffPhp\Rules\TestQuality\RepeatedStructureMissingDataProviderRule;
use GruffPhp\Rules\TestQuality\TestLongerThanSutRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers calibration-specific test-quality rule shapes.
 */
final class TestQualityCalibrationRulesTest extends TestCase
{
    /** Project root used by fixture tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify test-longer-than-SUT ignores command and process integration harness calls.
     *
     * @return void
     */
    public function testTestLongerThanSutIgnoresIntegrationHarnessCalls(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/integration-harness-exemptions.php');

        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
    }

    /**
     * Verify repeated-structure rubric supports project-specific path exemptions.
     *
     * @return void
     */
    public function testRepeatedStructureRuleSupportsIgnoredPathPatterns(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            RepeatedStructureMissingDataProviderRule::ID,
            new RuleSettings(true, [], ['ignoredPathPatterns' => ['tests/Fixtures/TestQuality/**']]),
        );

        $findings = $this->analysePath('tests/Fixtures/TestQuality/repeated-structure-missing-data-provider.php', $config);

        self::assertRuleCount(RepeatedStructureMissingDataProviderRule::ID, 0, $findings);
    }

    /**
     * Assert how many findings one rule emitted.
     *
     * @param string        $ruleId        - Rule whose findings are isolated before counting.
     * @param int           $expectedCount - Findings the rule must report for this fixture.
     * @param list<Finding> $findings      - Full analysis output to filter by rule id.
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
     * Analyse test-quality fixtures and return findings for assertions.
     *
     * @param string          $path   - Project-relative fixture path to parse and scan.
     * @param ?AnalysisConfig $config - Override config; null enables the default-off calibration rules.
     *
     * @return list<Finding> - every finding the full default rule set emitted for the fixture, unfiltered; empty when clean
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $unit     = $this->unitForPath($path);
        $config ??= (new ConfigLoader(self::PROJECT_ROOT))->load('tests/Fixtures/Config/enable-default-off-rules.yaml', $registry);

        return $registry->analyse(
            [$unit],
            new RuleContext(self::PROJECT_ROOT, $config),
        );
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path - Filesystem path.
     *
     * @return AnalysisUnit - the parsed fixture, repo-relative display path preserved, ready to feed the rule registry
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }
}
