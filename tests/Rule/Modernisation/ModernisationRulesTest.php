<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Modernisation;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Modernisation\ConstructorPromotionCandidateRule;
use GruffPhp\Rule\Modernisation\EnumCandidateRule;
use GruffPhp\Rule\Modernisation\FirstClassCallableCandidateRule;
use GruffPhp\Rule\Modernisation\ForbiddenGlobalAccessRule;
use GruffPhp\Rule\Modernisation\MatchExpressionCandidateRule;
use GruffPhp\Rule\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rule\Modernisation\NamedArgumentOpportunityRule;
use GruffPhp\Rule\Modernisation\PublicPropertyRule;
use GruffPhp\Rule\Modernisation\ReadonlyPropertyCandidateRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Security\ErrorSuppressionRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers modernisation rule detection: PHP version gating, PHP 8.x candidates, exemption of legitimate mutable cases, and error-suppression flagged as security-with-modernisation pillar.
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
        self::assertRuleCount(EnumCandidateRule::ID, 0, $findings);
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
        self::assertRuleCount(EnumCandidateRule::ID, 1, $findings);
        self::assertRuleCount(MatchExpressionCandidateRule::ID, 1, $findings);
        self::assertRuleCount(FirstClassCallableCandidateRule::ID, 1, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 0, $findings);
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
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 1, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 2, $findings);
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
        self::assertRuleCount(EnumCandidateRule::ID, 1, $findings);
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
     * Verify error suppression uses security finding with modernisation secondary pillar.
     *
     * @return void
     */
    public function testErrorSuppressionUsesSecurityFindingWithModernisationSecondaryPillar(): void
    {
        $findings            = $this->analysePath('tests/Fixtures/Modernisation/cumulative-modernisation.php');
        $suppressionFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === ErrorSuppressionRule::ID,
        ));

        self::assertCount(1, $suppressionFindings);
        self::assertContains(Pillar::Modernisation, $suppressionFindings[0]->secondaryPillars);

        $duplicateModernisationFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === 'modernisation.error-suppression',
        ));
        self::assertSame([], $duplicateModernisationFindings);
    }

    /**
     * Assert the expected modernisation finding count for a rule.
     *
     * @param list<Finding> $findings
     * @return void
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
     * Analyse modernisation fixtures and return findings for assertions.
     *
     * @return list<Finding>
     */
    private function analysePath(string $path, ?AnalysisConfig $config = null): array
    {
        return $this->analysePaths([$path], $config);
    }

    /**
     * Analyse modernisation fixtures and return findings for assertions.
     *
     * @param list<string> $paths
     * @return list<Finding>
     */
    private function analysePaths(array $paths, ?AnalysisConfig $config = null): array
    {
        $units    = array_map(fn (string $path): AnalysisUnit => $this->unitForPath($path), $paths);
        $registry = RuleRegistry::defaults();

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? AnalysisConfig::fromRegistry($registry)),
        );
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path Filesystem path.
     * @return AnalysisUnit
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }

    /**
     * Build analysis configuration for modernisation assertions.
     *
     * @param string $path Filesystem path.
     * @return AnalysisConfig
     */
    private function config(string $path): AnalysisConfig
    {
        return (new ConfigLoader(self::PROJECT_ROOT))->load($path, RuleRegistry::defaults());
    }
}
