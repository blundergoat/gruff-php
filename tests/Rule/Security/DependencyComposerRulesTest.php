<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DependencyComposerPathRule;
use GruffPhp\Rules\Security\DependencyComposerScriptRule;
use GruffPhp\Rules\Security\DependencyComposerUnpinnedRule;
use GruffPhp\Rules\Security\DependencyComposerVcsRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Composer dependency-posture security rules: VCS/path repositories,
 * unpinned constraints, and install-time shell scripts scanned from a manifest.
 */
final class DependencyComposerRulesTest extends TestCase
{
    /** Project root used to resolve fixtures. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /** Display path of the risky manifest fixture (basename must be composer.json). */
    private const RISKY_FIXTURE = 'tests/Fixtures/Security/ComposerDependency/composer.json';

    /** Display path of the safe manifest fixture. */
    private const CLEAN_FIXTURE = 'tests/Fixtures/Security/ComposerDependencyClean/composer.json';

    /**
     * Verify a VCS repository entry is flagged once with its repository type.
     *
     * @return void
     */
    public function testVcsRepositoryDetected(): void
    {
        $findings = $this->findingsForRule(self::RISKY_FIXTURE, DependencyComposerVcsRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(Pillar::Security, $findings[0]->pillar);
        self::assertSame(Confidence::Medium, $findings[0]->confidence);
        self::assertSame('vcs', $findings[0]->metadata['repositoryType'] ?? null);
    }

    /**
     * Verify a path repository entry is flagged once.
     *
     * @return void
     */
    public function testPathRepositoryDetected(): void
    {
        $findings = $this->findingsForRule(self::RISKY_FIXTURE, DependencyComposerPathRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('path', $findings[0]->metadata['repositoryType'] ?? null);
    }

    /**
     * Verify only unbounded constraints fire and pinned/ranged ones do not.
     *
     * @return void
     */
    public function testUnpinnedConstraintsDetectedAndPinnedConstraintsIgnored(): void
    {
        $findings = $this->findingsForRule(self::RISKY_FIXTURE, DependencyComposerUnpinnedRule::ID);

        self::assertCount(5, $findings);

        $packages = array_map(static fn(Finding $finding): mixed => $finding->metadata['package'] ?? null, $findings);
        self::assertContains('acme/wildcard-lib', $packages);
        self::assertContains('acme/partial-wildcard-lib', $packages);
        self::assertContains('acme/branch-lib', $packages);
        self::assertContains('acme/unbounded-helper', $packages);
        self::assertContains('acme/mixed-range-lib', $packages);
        self::assertNotContains('acme/pinned-lib', $packages);
        self::assertNotContains('php', $packages);
    }

    /**
     * Verify a shell/remote install-time script is flagged and a safe one is not.
     *
     * @return void
     */
    public function testInstallTimeShellScriptDetected(): void
    {
        $findings = $this->findingsForRule(self::RISKY_FIXTURE, DependencyComposerScriptRule::ID);

        self::assertCount(1, $findings);

        $events = array_map(static fn(Finding $finding): mixed => $finding->metadata['event'] ?? null, $findings);
        self::assertContains('post-install-cmd', $events);
        self::assertNotContains('build', $events);
        self::assertNotContains('lint', $events);
    }

    /**
     * Verify a safe manifest produces no dependency-posture findings.
     *
     * @return void
     */
    public function testSafeManifestProducesNoFindings(): void
    {
        foreach ([
                     DependencyComposerVcsRule::ID,
                     DependencyComposerPathRule::ID,
                     DependencyComposerUnpinnedRule::ID,
                     DependencyComposerScriptRule::ID,
                 ] as $ruleId) {
            self::assertSame([], $this->findingsForRule(self::CLEAN_FIXTURE, $ruleId), $ruleId);
        }
    }

    /**
     * Verify the rules ignore files that are not a Composer manifest.
     *
     * @return void
     */
    public function testNonManifestPathIsIgnored(): void
    {
        $unit     = (new PhpFileParser())->parse(new SourceFile(
                                                     self::PROJECT_ROOT . '/' . self::RISKY_FIXTURE,
                                                     'tests/Fixtures/Security/ComposerDependency/not-a-manifest.json',
                                                     SourceFile::TYPE_TEXT,
                                                 ));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        $dependencyFindings = array_values(array_filter(
                                               $findings,
                                               static fn(Finding $finding): bool => str_starts_with($finding->ruleId, 'security.dependency-composer-'),
                                           ));

        self::assertSame([], $dependencyFindings);
    }

    /**
     * Analyse a manifest fixture and return findings for one rule.
     *
     * @param string $displayPath - Fixture display path (basename decides manifest detection).
     * @param string $ruleId - Rule identifier to filter for.
     *
     * @return list<Finding> - findings emitted only by the named rule, empty when that rule stayed silent
     */
    private function findingsForRule(string $displayPath, string $ruleId): array
    {
        $unit     = (new PhpFileParser())->parse(new SourceFile(
                                                     self::PROJECT_ROOT . '/' . $displayPath,
                                                     $displayPath,
                                                     SourceFile::TYPE_TEXT,
                                                 ));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId));
    }
}
