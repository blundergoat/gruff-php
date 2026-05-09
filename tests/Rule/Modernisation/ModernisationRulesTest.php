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

final class ModernisationRulesTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    public function testPhpVersionGatingSuppressesPhpEightSuggestions(): void
    {
        $findings = $this->analysePath(
            'tests/Fixtures/M12/Modernisation/cumulative-modernisation.php',
            $this->config('tests/Fixtures/M12/Config/php74.json'),
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

    public function testPhpEightTwoConfigEnablesModernisationCandidates(): void
    {
        $findings = $this->analysePath(
            'tests/Fixtures/M12/Modernisation/cumulative-modernisation.php',
            $this->config('tests/Fixtures/M12/Config/php82.json'),
        );

        self::assertRuleCount(ConstructorPromotionCandidateRule::ID, 2, $findings);
        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 2, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
        self::assertRuleCount(EnumCandidateRule::ID, 1, $findings);
        self::assertRuleCount(MatchExpressionCandidateRule::ID, 1, $findings);
        self::assertRuleCount(FirstClassCallableCandidateRule::ID, 1, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 1, $findings);
        self::assertRuleCount(MixedTypeOveruseRule::ID, 1, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 1, $findings);
    }

    public function testPhpEightCandidatesAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M12/Modernisation/php80-candidates.php');

        self::assertRuleCount(ConstructorPromotionCandidateRule::ID, 2, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 1, $findings);
        self::assertRuleCount(MatchExpressionCandidateRule::ID, 1, $findings);
        self::assertRuleCount(MixedTypeOveruseRule::ID, 1, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 2, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 2, $findings);
    }

    public function testPhpEightOneCandidatesAreDetected(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M12/Modernisation/php81-candidates.php');

        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 2, $findings);
        self::assertRuleCount(EnumCandidateRule::ID, 1, $findings);
        self::assertRuleCount(FirstClassCallableCandidateRule::ID, 1, $findings);
    }

    public function testLateMutationInheritanceDtoAndControllerCasesAreNotFlagged(): void
    {
        $findings = $this->analysePaths([
            'tests/Fixtures/M12/Modernisation/non-candidates.php',
            'tests/Fixtures/M12/Modernisation/Controller/RequestController.php',
        ]);

        self::assertRuleCount(ReadonlyPropertyCandidateRule::ID, 0, $findings);
        self::assertRuleCount(ConstructorPromotionCandidateRule::ID, 0, $findings);
        self::assertRuleCount(PublicPropertyRule::ID, 0, $findings);
        self::assertRuleCount(ForbiddenGlobalAccessRule::ID, 0, $findings);
        self::assertRuleCount(NamedArgumentOpportunityRule::ID, 0, $findings);
    }

    public function testErrorSuppressionUsesSecurityFindingWithModernisationSecondaryPillar(): void
    {
        $findings = $this->analysePath('tests/Fixtures/M12/Modernisation/cumulative-modernisation.php');
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
        $units = array_map(fn (string $path): AnalysisUnit => $this->unitForPath($path), $paths);
        $registry = RuleRegistry::defaults();

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? AnalysisConfig::fromRegistry($registry)),
        );
    }

    private function unitForPath(string $path): AnalysisUnit
    {
        return (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $path, $path));
    }

    private function config(string $path): AnalysisConfig
    {
        return (new ConfigLoader(self::PROJECT_ROOT))->load($path, RuleRegistry::defaults());
    }
}
