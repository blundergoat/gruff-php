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
use PHPUnit\Framework\TestCase;

final class SingleImplementorInterfaceRuleTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../../..';
    private const FIXTURE_DIR = 'tests/Fixtures/Design/single-implementor-interface';

    public function testInternalOneImplFlagsExactlyOneInterface(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);
        sort($symbols);

        self::assertContains(
            'Fixtures\\Design\\SingleImplementor\\InternalOneImpl\\BookingOtpGatewayInterface',
            $symbols,
        );
    }

    public function testMockOnlyInterfaceFlagsByDefault(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertContains(
            'Fixtures\\Design\\SingleImplementor\\MockOnly\\BookingEventSinkInterface',
            $symbols,
        );
    }

    public function testPsrInterfaceIsExempt(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains(
            'Fixtures\\Design\\SingleImplementor\\Psr\\AuditLoggerInterface',
            $symbols,
        );
    }

    public function testSymfonyTaggedInterfaceIsExempt(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains(
            'Fixtures\\Design\\SingleImplementor\\SymfonyTagged\\AuditListenerInterface',
            $symbols,
        );
    }

    public function testMultiImplInterfaceDoesNotFlag(): void
    {
        $findings = $this->analyseFixtures();

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $findings);

        self::assertNotContains(
            'Fixtures\\Design\\SingleImplementor\\MultiImpl\\RendererInterface',
            $symbols,
        );
    }

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
    }

    public function testFlaggedFindingsCarrySeverityPillarAndMetadata(): void
    {
        $findings = $this->analyseFixtures();

        self::assertNotEmpty($findings);
        foreach ($findings as $finding) {
            self::assertSame(Severity::Advisory, $finding->severity);
            self::assertSame(Pillar::Design, $finding->pillar);
            self::assertSame(1, $finding->metadata['implementorCount'] ?? null);
            self::assertSame(0, $finding->metadata['externalUsageCount'] ?? null);
            self::assertNotNull($finding->metadata['implementorFqn'] ?? null);
            self::assertNotNull($finding->metadata['decision'] ?? null);
        }
    }

    public function testOnlyTheExpectedTwoInterfacesAreFlagged(): void
    {
        $findings = $this->analyseFixtures();

        self::assertCount(2, $findings, 'Exactly two fixture shapes should flag (internal-one-impl, mock-only).');
    }

    /**
     * @return list<Finding>
     */
    private function analyseFixtures(?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();
        $config ??= AnalysisConfig::fromRegistry($registry);

        $units = $this->fixtureUnits();
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
        $parser = new PhpFileParser();
        $paths = [
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
            $units[] = $parser->parse(new SourceFile($absolute, self::FIXTURE_DIR . '/' . $relative));
        }

        return $units;
    }
}
