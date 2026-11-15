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
 * Covers test-quality calibration through the configured rule registry.
 * Parsed fixtures exercise the integration-harness count and a repeated-structure path exclusion.
 * Assertions filter findings by rule ID; the fixture methods are not executed.
 */
final class TestQualityCalibrationRulesTest extends TestCase
{
    /** Project root used by fixture tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify the integration-harness fixture produces one test-longer-than-SUT finding.
     *
     * @return void - Asserts the rule-specific finding count is one.
     */
    public function testTestLongerThanSutIgnoresIntegrationHarnessCalls(): void
    {
        $findings = $this->analysePath('tests/Fixtures/TestQuality/integration-harness-exemptions.php');

        self::assertRuleCount(TestLongerThanSutRule::ID, 1, $findings);
    }

    /**
     * Verify repeated-structure rule supports project-specific path exemptions.
     *
     * @return void - Asserts no repeated-structure findings under the configured path pattern.
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
     * @return void - Asserts the number of findings whose rule ID matches the requested rule.
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
     * @param ?AnalysisConfig $config - Configuration override; null loads the fixture configuration that enables default-off rules.
     *
     * @return list<Finding> - Unfiltered findings from rules enabled by the chosen configuration; empty when none are reported.
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
     * @param string $path - Project-relative fixture path, also used as the display path.
     *
     * @return AnalysisUnit - Fixture contents and any read or parse diagnostics, retaining the supplied display path.
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }
}
