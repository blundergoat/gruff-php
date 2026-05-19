<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Design;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Design\SingleImplementorInterfaceRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers SingleImplementorInterfaceRuleTest behavior.
 */
final class SingleImplementorInterfaceRuleTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';
    /** Fixture directory used by this test case. */
    private const FIXTURE_DIR = 'tests/Fixtures/Design/single-implementor-interface';

    /**
     * Verify internal one impl flags exactly one interface.
     *
     * @return void No return value.
     */
    public function testInternalOneImplFlagsExactlyOneInterface(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);
        sort($symbols);

        self::assertContains(
            'Fixtures\\Design\\SingleImplementor\\InternalOneImpl\\BookingOtpGatewayInterface',
            $symbols,
        );
        self::assertContains(
            'Fixtures\\Design\\SingleImplementor\\MutationCases\\PositiveAfterSkipsInterface',
            $symbols,
        );
    }

    /**
     * Verify mock only interface flags by default.
     *
     * @return void No return value.
     */
    public function testMockOnlyInterfaceFlagsByDefault(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertContains(
            'Fixtures\\Design\\SingleImplementor\\MockOnly\\BookingEventSinkInterface',
            $symbols,
        );
    }

    /**
     * Verify configured interface exemption cases are not flagged.
     *
     * @param string $symbol Interface symbol expected to be absent from findings.
     * @return void No return value.
     */
    #[DataProvider('configuredInterfaceExemptionProvider')]
    public function testConfiguredInterfaceExemptionsAreNotFlagged(string $symbol): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains($symbol, $symbols);
    }

    /**
     * Provide interface symbols that are exempt from single-implementor findings.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function configuredInterfaceExemptionProvider(): iterable
    {
        yield 'psr interface' => ['Fixtures\\Design\\SingleImplementor\\Psr\\AuditLoggerInterface'];
        yield 'symfony tagged interface' => ['Fixtures\\Design\\SingleImplementor\\SymfonyTagged\\AuditListenerInterface'];
        yield 'multi impl interface' => ['Fixtures\\Design\\SingleImplementor\\MultiImpl\\RendererInterface'];
    }

    /**
     * Verify interface hierarchy does not flag.
     *
     * @return void No return value.
     */
    public function testInterfaceHierarchyDoesNotFlag(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains(
            'Fixtures\\Design\\SingleImplementor\\InterfaceHierarchy\\CacheInterface',
            $symbols,
            'Parent interface extended by another interface must not flag.',
        );
        self::assertNotContains(
            'Fixtures\\Design\\SingleImplementor\\InterfaceHierarchy\\TaggedCacheInterface',
            $symbols,
            'Child interface used as a type-hint outside its implementor must not flag.',
        );
        self::assertNotContains(
            'Fixtures\\Design\\SingleImplementor\\MutationCases\\ParentContract',
            $symbols,
            'Parent interface extended by another mutation-case interface must not flag.',
        );
        self::assertNotContains(
            'Fixtures\\Design\\SingleImplementor\\MutationCases\\MultiParentExternalInterface',
            $symbols,
            'Interface with an external second parent must not flag.',
        );
    }

    /**
     * Verify external type-hint usage through returns properties and composite types does not flag.
     *
     * @return void No return value.
     */
    public function testExternalTypeHintUsageThroughReturnsPropertiesAndCompositeTypesDoesNotFlag(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\MutationCases\\ReturnUsageInterface', $symbols);
        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\MutationCases\\PropertyUsageInterface', $symbols);
        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\MutationCases\\NullableUsageInterface', $symbols);
        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\MutationCases\\UnionUsageInterface', $symbols);
        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\MutationCases\\IntersectionUsageInterface', $symbols);
    }

    /**
     * Verify framework attributes exempt interfaces when any configured prefix matches.
     *
     * @return void No return value.
     */
    public function testFrameworkAttributeExemptionsCheckAllAttributesAndContainsMatches(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\MutationCases\\MultipleAttributeInterface', $symbols);
        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\MutationCases\\ContainsAttributeInterface', $symbols);
    }

    /**
     * Verify flagged findings carry severity pillar and metadata.
     *
     * @return void No return value.
     */
    public function testFlaggedFindingsCarrySeverityPillarAndMetadata(): void
    {
        $findings = $this->analyseFixtures();

        self::assertNotEmpty($findings);
        $severityValues    = array_values(array_unique(array_map(static fn ($finding): string => $finding->severity->value, $findings)));
        $pillarValues      = array_values(array_unique(array_map(static fn ($finding): string => $finding->pillar->value, $findings)));
        $implementorCounts = array_values(array_unique(array_map(static function (Finding $finding): int {
            $implementorCount = $finding->metadata['implementorCount'] ?? null;

            self::assertIsInt($implementorCount);

            return $implementorCount;
        }, $findings)));
        $externalUsageCounts = array_values(array_unique(array_map(static function (Finding $finding): int {
            $externalUsageCount = $finding->metadata['externalUsageCount'] ?? null;

            self::assertIsInt($externalUsageCount);

            return $externalUsageCount;
        }, $findings)));
        $missingImplementorFqns = array_values(array_filter($findings, static fn ($finding): bool => ($finding->metadata['implementorFqn'] ?? null) === null));
        $missingDecisions       = array_values(array_filter($findings, static fn ($finding): bool => ($finding->metadata['decision'] ?? null) === null));
        $missingExclusionHint   = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => !str_contains($finding->remediation ?? '', 'additionalExcludedPaths'),
        ));

        self::assertSame([Severity::Advisory->value], $severityValues);
        self::assertSame([Pillar::Design->value], $pillarValues);
        self::assertSame([1], $implementorCounts);
        self::assertSame([0], $externalUsageCounts);
        self::assertSame([], $missingImplementorFqns);
        self::assertSame([], $missingDecisions);
        self::assertSame([], $missingExclusionHint);
        self::assertSame([], array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => ($finding->metadata['interfaceFqn'] ?? null) !== $finding->symbol,
        )));
    }

    /**
     * Verify only the expected two interfaces are flagged.
     *
     * @return void No return value.
     */
    public function testOnlyTheExpectedTwoInterfacesAreFlagged(): void
    {
        $findings = $this->analyseFixtures();

        self::assertCount(3, $findings, 'Exactly three fixture shapes should flag (internal-one-impl, mock-only, mutation positive).');
    }

    /**
     * Verify additional excluded paths remove eligible units before analysis.
     *
     * @return void No return value.
     */
    public function testAdditionalExcludedPathsRemoveEligibleUnitsBeforeAnalysis(): void
    {
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(SingleImplementorInterfaceRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            SingleImplementorInterfaceRule::ID,
            new \GruffPhp\Config\RuleSettings(
                enabled:    true,
                thresholds: $settings->thresholds,
                options:    array_merge($settings->options, [
                    'additionalExcludedPaths' => [self::FIXTURE_DIR . '/internal-one-impl'],
                ]),
            ),
        );
        $findings = $this->analyseFixtures($config);
        $symbols  = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains('Fixtures\\Design\\SingleImplementor\\InternalOneImpl\\BookingOtpGatewayInterface', $symbols);
    }

    /**
     * @return list<Finding>
     */
    private function analyseFixtures(?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $config ??= AnalysisConfig::fromRegistry($registry);

        $units       = $this->fixtureUnits();
        $allFindings = $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config),
        );

        return array_values(array_filter(
            $allFindings,
            static fn (Finding $finding): bool => $finding->ruleId === SingleImplementorInterfaceRule::ID,
        ));
    }

    /**
     * @return list<AnalysisUnit>
     */
    private function fixtureUnits(): array
    {
        $phpFileParser = new PhpFileParser();
        $paths         = [
            'mutation-cases/MutationCases.php',
            'psr/AuditPsrLogger.php',
            'symfony-tagged/SymfonyTaggedListener.php',
            'internal-one-impl/BookingOtpGateway.php',
            'mock-only/BookingEventSink.php',
            'multi-impl/Renderer.php',
            'interface-hierarchy/InterfaceHierarchy.php',
        ];

        $units = [];
        foreach ($paths as $relative) {
            $absolute = self::PROJECT_ROOT . '/' . self::FIXTURE_DIR . '/' . $relative;
            $units[]  = $phpFileParser->parse(new SourceFile($absolute, self::FIXTURE_DIR . '/' . $relative));
        }

        return $units;
    }
}
