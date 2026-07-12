<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Modernisation;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Modernisation\ConstructorPromotionCandidateRule;
use GruffPhp\Rules\Modernisation\FirstClassCallableCandidateRule;
use GruffPhp\Rules\Modernisation\ForbiddenGlobalAccessRule;
use GruffPhp\Rules\Modernisation\MatchExpressionCandidateRule;
use GruffPhp\Rules\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rules\Modernisation\NamedArgumentOpportunityRule;
use GruffPhp\Rules\Modernisation\PublicPropertyRule;
use GruffPhp\Rules\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\ErrorSuppressionRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertRuleCount(ConstructorPromotionCandidateRule::ID, 0, $findings);
        self::assertRuleCount(FirstClassCallableCandidateRule::ID, 0, $findings);
        self::assertRuleCount(MatchExpressionCandidateRule::ID, 0, $findings);
        self::assertRuleCount(MixedTypeOveruseRule::ID, 0, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 0, $findings);
        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 0, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 1, $findings);
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

        self::assertRuleCount(ConstructorPromotionCandidateRule::ID, 2, $findings);
        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 2, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
        self::assertRuleCount(MatchExpressionCandidateRule::ID, 1, $findings);
        self::assertRuleCount(FirstClassCallableCandidateRule::ID, 1, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 1, $findings);
        self::assertRuleCount(MixedTypeOveruseRule::ID, 1, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 1, $findings);
    }

    /**
     * Verify PHP eight candidates are detected.
     *
     * @return void
     */
    public function testPhpEightCandidatesAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/Modernisation/php80-candidates.php');

        self::assertRuleCount(ConstructorPromotionCandidateRule::ID, 2, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
        self::assertRuleCount(MatchExpressionCandidateRule::ID, 1, $findings);
        self::assertRuleCount(MixedTypeOveruseRule::ID, 1, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 4, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 2, $findings);

        $namedArgumentFindings = array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->ruleId === NamedArgumentOpportunityRule::ID,
        ));
        self::assertSame(
            [RemediationAction::Consider->value],
            array_values(array_unique(array_map(
                static fn(Finding $finding): string => is_string($finding->metadata['remediationAction'] ?? null)
                    ? $finding->metadata['remediationAction']
                    : '',
                $namedArgumentFindings,
            ))),
        );
        self::assertSame(
            [Severity::Advisory->value],
            array_values(array_unique(array_map(
                static fn(Finding $finding): string => $finding->severity->value,
                $namedArgumentFindings,
            ))),
        );
        self::assertSame(
            [Confidence::Low->value],
            array_values(array_unique(array_map(
                static fn(Finding $finding): string => $finding->confidence->value,
                $namedArgumentFindings,
            ))),
        );
        self::assertSame(
            ['Consider named arguments only for stable APIs where parameter names are part of the intended contract; gruff-php reports only.'],
            array_values(array_unique(array_map(
                static fn(Finding $finding): ?string => $finding->remediation,
                $namedArgumentFindings,
            ))),
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
        self::assertRuleCount(FirstClassCallableCandidateRule::ID, 1, $findings);
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
        self::assertRuleCount(ConstructorPromotionCandidateRule::ID, 0, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 0, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 0, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 0, $findings);
    }

    /**
     * Verify forbidden global access skips superglobal writes and unsets while reads keep flagging.
     *
     * @return void
     */
    public function testForbiddenGlobalAccessSkipsWritePositionsAndKeepsReads(): void
    {
        $findings = array_values(array_filter(
                                     $this->analysePath('tests/Fixtures/Modernisation/forbidden-global-write-positions.php'),
                                     static fn(Finding $finding): bool => $finding->ruleId === ForbiddenGlobalAccessRule::ID,
                                 ));

        // Plain read, compound assignment, and the read inside a write target's dimension expression.
        self::assertSame(
            ['_GET', '_GET', '_POST'],
            array_map(static fn(Finding $finding): mixed => $finding->metadata['global'] ?? null, $findings),
        );
    }

    /**
     * Verify modernisation rules keep their documented false-positive fixtures quiet.
     *
     * @param string $fixture - Fixture file under tests/Fixtures/Modernisation.
     * @param string $ruleId - Rule expected to report no findings for the fixture.
     *
     * @return void
     */
    #[DataProvider('modernisationFalsePositiveProvider')]
    public function testModernisationRulesIgnoreFalsePositiveFixtures(string $fixture, string $ruleId): void
    {
        $findings = $this->analysePath($fixture);

        self::assertRuleCount($ruleId, 0, $findings);
    }

    /**
     * Provide false-positive fixtures for modernisation rules.
     *
     * @return list<array{fixture: string, ruleId: string}> - fixture/rule pairs that should produce zero findings
     */
    public static function modernisationFalsePositiveProvider(): array
    {
        return [
            [
                'fixture' => 'tests/Fixtures/Modernisation/first-class-callable-non-class-const.php',
                'ruleId'  => FirstClassCallableCandidateRule::ID,
            ],
            [
                'fixture' => 'tests/Fixtures/Modernisation/first-class-callable-associative-array.php',
                'ruleId'  => FirstClassCallableCandidateRule::ID,
            ],
        ];
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
