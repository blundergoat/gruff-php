<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Security\DebugModeEnabledRule;
use GruffPhp\Rule\Security\PermissiveCorsRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers the syntax-only framework-misconfiguration security rules:
 * permissive CORS (wildcard origin + credentials) and forced error display.
 */
final class FrameworkSecurityRulesTest extends TestCase
{
    /** Project root used to resolve fixtures. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /** Fixture with unsafe CORS and debug-mode toggles. */
    private const MISCONFIG_FIXTURE = 'tests/Fixtures/Security/framework-misconfig.php';

    /** Fixture with only safe CORS postures. */
    private const CORS_SAFE_FIXTURE = 'tests/Fixtures/Security/framework-cors-safe.php';

    /**
     * Verify wildcard CORS plus credentials in one scope fires once.
     *
     * @return void
     */
    public function testWildcardCorsWithCredentialsDetected(): void
    {
        $findings = $this->findingsForRule(self::MISCONFIG_FIXTURE, PermissiveCorsRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(Pillar::Security, $findings[0]->pillar);
        self::assertSame(Confidence::Medium, $findings[0]->confidence);
    }

    /**
     * Verify a wildcard origin and credentials in different scopes do not fire.
     *
     * @return void
     */
    public function testScopedAndPublicCorsDoNotFire(): void
    {
        self::assertSame([], $this->findingsForRule(self::CORS_SAFE_FIXTURE, PermissiveCorsRule::ID));
    }

    /**
     * Verify only truthy display-error toggles fire.
     *
     * @return void
     */
    public function testForcedErrorDisplayDetected(): void
    {
        $findings = $this->findingsForRule(self::MISCONFIG_FIXTURE, DebugModeEnabledRule::ID);

        self::assertCount(2, $findings);

        $directives = array_map(static fn (Finding $finding): mixed => $finding->metadata['directive'] ?? null, $findings);
        self::assertContains('display_errors', $directives);
        self::assertContains('display_startup_errors', $directives);
    }

    /**
     * Analyse a fixture and return findings for one rule.
     *
     * @param string $displayPath Fixture display path.
     * @param string $ruleId      Rule identifier to filter for.
     * @return list<Finding>
     */
    private function findingsForRule(string $displayPath, string $ruleId): array
    {
        $unit     = (new PhpFileParser())->parse(new SourceFile(
            self::PROJECT_ROOT . '/' . $displayPath,
            $displayPath,
            SourceFile::TYPE_PHP,
        ));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        // The filtered list isolates the framework-misconfiguration rule under test.
        return array_values(array_filter($findings, static fn (Finding $finding): bool => $finding->ruleId === $ruleId));
    }
}
