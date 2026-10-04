<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\SensitiveData;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\SensitiveData\GcpServiceAccountKeyRule;
use GruffPhp\Rules\SensitiveData\PhiPatternRule;
use GruffPhp\Rules\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rules\SensitiveData\PrivateKeyRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers the GCP service-account key rule as a user meets it when a key file is committed.
 *
 * - A real key reports once with a redacted marker, a placeholder key stays quiet, and the private-key rule reports its own line.
 * - No report format (text, JSON, SARIF, GitHub, Markdown or HTML) prints the raw key body.
 * - Schema field names and placeholder identifiers do not fire the PHI or PII rules.
 */
final class SensitiveDataExpansionRulesTest extends TestCase
{
    /** Project root used to resolve fixtures and the CLI binary. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /** Realistic synthetic GCP service-account key. */
    private const GCP_FIXTURE = 'tests/Fixtures/SensitiveData/gcp-service-account-key.json';

    /** Placeholder GCP service-account key. */
    private const GCP_PLACEHOLDER = 'tests/Fixtures/SensitiveData/gcp-service-account-placeholder.json';

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
     * Verify no renderer leaks the raw key body.
     *
     * @return void
     */
    public function testNewDetectorsDoNotLeakRawSecretsAcrossFormats(): void
    {
        $rawSecrets = ['MIIBVgIBADANBgkqhkiG'];

        foreach (['text', 'json', 'sarif', 'github', 'markdown', 'html'] as $format) {
            $output = $this->runGruff(['analyse', self::GCP_FIXTURE, '--format', $format, '--fail-on', 'none', '--no-config']);

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
     * @param string $displayPath - Fixture display path.
     * @param string $ruleId - Rule identifier to filter for.
     *
     * @return list<Finding> - findings emitted by that one rule, in detection order; empty when the rule did not fire
     */
    private function findingsForRule(string $displayPath, string $ruleId): array
    {
        $type     = str_ends_with($displayPath, '.php') ? SourceFile::TYPE_PHP : SourceFile::TYPE_TEXT;
        $unit     = (new PhpFileParser())->parse(new SourceFile(self::PROJECT_ROOT . '/' . $displayPath, $displayPath, $type));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId));
    }

    /**
     * Run the gruff CLI and return its stdout.
     *
     * @param list<string> $arguments - CLI arguments.
     *
     * @return string - the full rendered report captured from stdout; a non-zero exit aborts via assertion first,
     *   so the returned text is always the complete report to scan for leaked secrets
     */
    private function runGruff(array $arguments): string
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return $process->getOutput();
    }
}
