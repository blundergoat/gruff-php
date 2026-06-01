<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\SensitiveData;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rule\SensitiveData\GcpServiceAccountKeyRule;
use GruffPhp\Rule\SensitiveData\PhiPatternRule;
use GruffPhp\Rule\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rule\SensitiveData\PrivateKeyRule;
use GruffPhp\Rule\SensitiveData\UrlEmbeddedCredentialsRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers the M12 sensitive-data expansion: GCP service-account keys and
 * HTTP(S) URL-embedded credentials, with overlap precedence and no-leak proof.
 */
final class SensitiveDataExpansionRulesTest extends TestCase
{
    /** Project root used to resolve fixtures and the CLI binary. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /** Realistic synthetic GCP service-account key. */
    private const GCP_FIXTURE = 'tests/Fixtures/SensitiveData/gcp-service-account-key.json';

    /** Placeholder GCP service-account key. */
    private const GCP_PLACEHOLDER = 'tests/Fixtures/SensitiveData/gcp-service-account-placeholder.json';

    /** URL-embedded-credentials fixture. */
    private const URL_FIXTURE = 'tests/Fixtures/SensitiveData/url-credentials.php';

    /** Schema-field-name and placeholder PHI/PII fixture. */
    private const PHI_GUARD_FIXTURE = 'tests/Fixtures/SensitiveData/phi-schema-placeholders.php';

    /**
     * Verify a real service-account key fires once with a redacted preview.
     *
     * @return void
     */
    public function testGcpServiceAccountKeyDetected(): void
    {
        $findings = $this->findingsForRule(self::GCP_FIXTURE, GcpServiceAccountKeyRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(Pillar::SensitiveData, $findings[0]->pillar);
        self::assertSame(Confidence::High, $findings[0]->confidence);
        self::assertSame('gcp-service-account-key', $findings[0]->metadata['detector'] ?? null);
        $preview = $findings[0]->metadata['preview'] ?? null;
        self::assertIsString($preview);
        self::assertStringContainsString('redacted', $preview);
    }

    /**
     * Verify a placeholder service-account key does not fire.
     *
     * @return void
     */
    public function testGcpPlaceholderKeyIgnored(): void
    {
        self::assertSame([], $this->findingsForRule(self::GCP_PLACEHOLDER, GcpServiceAccountKeyRule::ID));
    }

    /**
     * Verify the GCP and generic private-key rules are complementary, not duplicate.
     *
     * @return void
     */
    public function testGcpAndPrivateKeyFindingsAreDistinct(): void
    {
        $gcpFindings        = $this->findingsForRule(self::GCP_FIXTURE, GcpServiceAccountKeyRule::ID);
        $privateKeyFindings = $this->findingsForRule(self::GCP_FIXTURE, PrivateKeyRule::ID);

        self::assertCount(1, $gcpFindings);
        self::assertCount(1, $privateKeyFindings);
        // Anchored at distinct loci: GCP at the type marker, private-key at the PEM header.
        self::assertNotSame($gcpFindings[0]->line, $privateKeyFindings[0]->line);
    }

    /**
     * Verify http(s) URL credentials fire and the password is redacted.
     *
     * @return void
     */
    public function testUrlEmbeddedCredentialsDetected(): void
    {
        $findings = $this->findingsForRule(self::URL_FIXTURE, UrlEmbeddedCredentialsRule::ID);

        self::assertCount(2, $findings);
        foreach ($findings as $finding) {
            $preview = $finding->metadata['preview'] ?? null;
            self::assertIsString($preview, sprintf('Finding on line %s should expose a string preview.', (string)$finding->line));
            self::assertStringContainsString('redacted', $preview, sprintf('Finding on line %s should redact the password.', (string)$finding->line));
        }
    }

    /**
     * Verify the URL-credentials rule ignores DB schemes (handled elsewhere) and
     * safe URLs, with no overlap against the database-url rule.
     *
     * @return void
     */
    public function testUrlCredentialsScopeAndNoDatabaseOverlap(): void
    {
        $urlFindings = $this->findingsForRule(self::URL_FIXTURE, UrlEmbeddedCredentialsRule::ID);
        $dbFindings  = $this->findingsForRule(self::URL_FIXTURE, DatabaseUrlPasswordRule::ID);

        // url-credentials handles only http(s); the mysql:// URL is the database rule's job.
        self::assertCount(2, $urlFindings);
        self::assertCount(1, $dbFindings);
        self::assertNotSame($urlFindings[0]->line, $dbFindings[0]->line);
    }

    /**
     * Verify no renderer leaks the raw key body or URL password.
     *
     * @return void
     */
    public function testNewDetectorsDoNotLeakRawSecretsAcrossFormats(): void
    {
        $rawSecrets = ['MIIBVgIBADANBgkqhkiG', 's3cr3tValue', 'Tok3nXyZ9'];

        foreach (['text', 'json', 'sarif', 'github', 'markdown', 'html'] as $format) {
            $output = $this->runGruff(['analyse', self::GCP_FIXTURE, self::URL_FIXTURE, '--format', $format, '--fail-on', 'none', '--no-config']);

            foreach ($rawSecrets as $secret) {
                self::assertStringNotContainsString($secret, $output, sprintf('%s report leaked a raw secret.', $format));
            }

            self::assertStringContainsString('redacted', $output, sprintf('%s report should show a redacted preview.', $format));
        }
    }

    /**
     * Verify schema field names and placeholder identifiers do not fire PHI/PII rules.
     *
     * @return void
     */
    public function testSchemaAndPlaceholderValuesDoNotFirePhiOrPii(): void
    {
        self::assertSame([], $this->findingsForRule(self::PHI_GUARD_FIXTURE, PhiPatternRule::ID));
        self::assertSame([], $this->findingsForRule(self::PHI_GUARD_FIXTURE, PiiTestFixtureRule::ID));
    }

    /**
     * Analyse a fixture and return findings for one rule.
     *
     * @param string $displayPath Fixture display path.
     * @param string $ruleId      Rule identifier to filter for.
     *
     * @return list<Finding> - findings emitted by that one rule, in detection order; empty when the rule did not fire
     */
    private function findingsForRule(string $displayPath, string $ruleId): array
    {
        $type     = str_ends_with($displayPath, '.php') ? SourceFile::TYPE_PHP : SourceFile::TYPE_TEXT;
        $unit     = (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $displayPath, $displayPath, $type));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        // The filtered list is the public contract these rule tests assert.
        return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId));
    }

    /**
     * Run the gruff CLI and return its stdout.
     *
     * @param list<string> $arguments CLI arguments.
     *
     * @return string - the full rendered report captured from stdout; a non-zero exit aborts via assertion first,
     *   so the returned text is always the complete report to scan for leaked secrets
     */
    private function runGruff(array $arguments): string
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        // Renderers write report payloads to stdout.
        return $process->getOutput();
    }
}
