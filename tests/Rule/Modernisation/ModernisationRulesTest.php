<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Modernisation;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Modernisation\PublicPropertyRule;
use GruffPhp\Rules\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\ErrorSuppressionRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers modernisation rule detection: PHP version gating, PHP 8.x candidates, exemption of legitimate mutable cases, and error-suppression flagged
 * as security-with-modernisation pillar.
 */
final class ModernisationRulesTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify PHP version gating suppresses PHP eight suggestions.
     *
     * @return void
     */
    public function testPhpVersionGatingSuppressesPhpEightSuggestions(): void
    {
        $findings = $this->analysePath(
            'tests/Fixtures/Modernisation/cumulative-modernisation.php',
            $this->config('tests/Fixtures/Config/php74.yaml'),
        );

        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 0, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
    }

    /**
     * Verify PHP eight two config enables modernisation candidates.
     *
     * @return void
     */
    public function testPhpEightTwoConfigEnablesModernisationCandidates(): void
    {
        $findings = $this->analysePath(
            'tests/Fixtures/Modernisation/cumulative-modernisation.php',
            $this->config('tests/Fixtures/Config/php82.yaml'),
        );

        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 2, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
    }

    /**
     * Verify PHP eight candidates are detected.
     *
     * @return void
     */
    public function testPhpEightCandidatesAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/Modernisation/php80-candidates.php');

        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
    }

    /**
     * Verify readonly owners stay quiet while public mutable promoted properties report once.
     *
     * @return void
     */
    public function testPublicPropertyRuleHandlesReadonlyOwnersAndPromotedProperties(): void
    {
        $unit = $this->inlineUnit(<<<'PHP'
<?php
readonly class ReadonlyOwner
{
    public string $declared;

    public function __construct(public string $promoted)
    {
        $this->declared = $promoted;
    }
}
final class MutableService
{
    public readonly string $readonlyDeclared;
    public static string $staticDeclared;
    public string $mutableDeclared;

    public function __construct(
        public string $publicPromoted,
        public readonly string $readonlyPromoted,
        protected string $protectedPromoted,
        private string $privatePromoted,
    ) {
        $this->readonlyDeclared = $readonlyPromoted;
    }
}
PHP);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = (new PublicPropertyRule())->analyse($unit, new RuleContext(self::PROJECT_ROOT, $config));

        self::assertSame([], $unit->diagnostics);
        self::assertSame(
            ['mutableDeclared', 'publicPromoted'],
            array_map(static fn(Finding $finding): mixed => $finding->metadata['property'] ?? null, $findings),
        );
        self::assertSame([15, 18], array_map(static fn(Finding $finding): ?int => $finding->line, $findings));
        self::assertCount(2, array_unique(array_map(
            static fn(Finding $finding): mixed => $finding->metadata['property'] ?? null,
            $findings,
        )));
    }

    /**
     * Verify PHP 8.4 restricted-set properties stay quiet while an open write side still reports.
     *
     * @return void
     */
    public function testPublicPropertyRuleSkipsAsymmetricVisibilityWriteRestrictions(): void
    {
        $unit = $this->inlineUnit(<<<'PHP'
<?php
final class AsymmetricService
{
    public private(set) string $privateSetDeclared = '';
    public protected(set) string $protectedSetDeclared = '';
    public string $openDeclared = '';

    public function __construct(
        public private(set) string $privateSetPromoted,
        public protected(set) string $protectedSetPromoted,
        public string $openPromoted,
    ) {
    }
}
PHP);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = (new PublicPropertyRule())->analyse($unit, new RuleContext(self::PROJECT_ROOT, $config));

        self::assertSame([], $unit->diagnostics);
        self::assertSame(
            ['openDeclared', 'openPromoted'],
            array_map(static fn(Finding $finding): mixed => $finding->metadata['property'] ?? null, $findings),
        );
    }

    /**
     * Verify an exact configured class exemption leaves other mutable classes reportable.
     *
     * @return void
     */
    public function testPublicPropertyRuleHonoursAllowedClasses(): void
    {
        $unit = $this->inlineUnit(<<<'PHP'
<?php
namespace App\Parser;

final class AllowedUnit
{
    public string $allowed;
}

final class ReportedUnit
{
    public string $reported;
}
PHP);
        $registry      = RuleRegistry::defaults();
        $defaultConfig = AnalysisConfig::fromRegistry($registry);
        $rule          = new PublicPropertyRule();
        $context       = new RuleContext(self::PROJECT_ROOT, $defaultConfig);

        self::assertSame(
            ['allowed', 'reported'],
            array_map(static fn(Finding $finding): mixed => $finding->metadata['property'] ?? null, $rule->analyse($unit, $context)),
        );

        $settings      = $defaultConfig->ruleSettings(PublicPropertyRule::ID);
        $allowedConfig = $defaultConfig->withRuleSettings(
            PublicPropertyRule::ID,
            new RuleSettings(
                $settings->enabled,
                $settings->thresholds,
                array_merge($settings->options, ['allowedClasses' => ['App\\Parser\\AllowedUnit']]),
                $settings->severityThreshold,
                $settings->excludeFromScore,
            ),
        );
        $findings = $rule->analyse($unit, new RuleContext(self::PROJECT_ROOT, $allowedConfig));

        self::assertSame(
            ['reported'],
            array_map(static fn(Finding $finding): mixed => $finding->metadata['property'] ?? null, $findings),
        );
    }

    /**
     * Verify PHP eight one candidates are detected.
     *
     * @return void
     */
    public function testPhpEightOneCandidatesAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/Modernisation/php81-candidates.php');

        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 2, $findings);
    }

    /**
     * Verify late mutation inheritance DTO and controller cases are not flagged.
     *
     * @return void
     */
    public function testLateMutationInheritanceDtoAndControllerCasesAreNotFlagged(): void
    {
        $findings = $this->analysePaths([
                                            'tests/Fixtures/Modernisation/non-candidates.php',
                                            'tests/Fixtures/Modernisation/Controller/RequestController.php',
                                        ]);

        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 0, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 0, $findings);
    }

    /**
     * Verify error suppression uses security finding with modernisation secondary pillar.
     *
     * @return void
     */
    public function testErrorSuppressionUsesSecurityFindingWithModernisationSecondaryPillar(): void
    {
        $findings            = $this->analysePath('tests/Fixtures/Modernisation/cumulative-modernisation.php');
        $suppressionFindings = array_values(array_filter(
                                                $findings,
                                                static fn(Finding $finding): bool => $finding->ruleId === ErrorSuppressionRule::ID,
                                            ));

        self::assertCount(1, $suppressionFindings);
        self::assertContains(Pillar::Modernisation, $suppressionFindings[0]->secondaryPillars);

        $duplicateModernisationFindings = array_values(array_filter(
                                                           $findings,
                                                           static fn(Finding $finding): bool => $finding->ruleId === 'modernisation.error-suppression',
                                                       ));
        self::assertSame([], $duplicateModernisationFindings);
    }

    /**
     * Assert the expected modernisation finding count for a rule.
     *
     * @param string        $ruleId - Rule id whose findings are counted.
     * @param int           $expectedCount - Number of findings the fixture should trip for that rule.
     * @param list<Finding> $findings - Full finding set to filter down to the rule.
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
     * Analyse modernisation fixtures and return findings for assertions.
     *
     * @param string              $path - Project-relative fixture path to scan.
     * @param AnalysisConfig|null $config - Override config; null falls back to the default registry config.
     *
     * @return list<Finding> - every finding the registry raised for the single fixture; empty when it is clean
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Analyse modernisation fixtures and return findings for assertions.
     *
     * @param list<string>        $paths - Project-relative fixture paths to scan together.
     * @param AnalysisConfig|null $config - Override config; null falls back to the default registry config.
     *
     * @return list<Finding> - findings aggregated across all supplied fixtures; empty when none trip
     */
    private function analysePaths(array $paths, ?AnalysisConfig $config = null): array
    {
        $units    = array_map(fn(string $path): AnalysisUnit => $this->unitForPath($path), $paths);
        $registry = RuleRegistry::defaults();

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
     * @return AnalysisUnit - the parsed fixture ready for rule analysis, carrying the project-relative display path
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }

    /**
     * Parse inline PHP source without adding a fixture-corpus unit.
     *
     * @param string $source - Complete PHP source for one focused rule assertion.
     *
     * @return AnalysisUnit - Parsed inline source with a stable display path.
     */
    private function inlineUnit(string $source): AnalysisUnit
    {
        return (new PhpFileParser())->parse(new SourceFile(
            'data://text/plain;base64,' . base64_encode($source),
            'tests/Fixtures/Modernisation/named-argument-inline.php',
        ));
    }

    /**
     * Build analysis configuration for modernisation assertions.
     *
     * @param string $path - Filesystem path.
     *
     * @return AnalysisConfig - config loaded from the fixture's own YAML, applying its rule overrides
     */
    private function config(string $path): AnalysisConfig
    {
        return (new ConfigLoader(self::PROJECT_ROOT))->load($path, RuleRegistry::defaults());
    }
}
