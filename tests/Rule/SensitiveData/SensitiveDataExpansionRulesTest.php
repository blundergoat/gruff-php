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
use GruffPhp\Rules\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rules\SensitiveData\PrivateKeyRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers GCP service-account fixture findings and rendered previews.
 * The key fixture produces one GCP finding; the placeholder stays quiet and the private-key finding uses a different line.
 * The six exercised report formats include a redaction marker and omit the checked key-body prefix.
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
     * Verify one GCP finding has the expected metadata and a redaction marker in its preview.
     *
     * @return void - Asserts the finding count, pillar, confidence, detector and preview marker.
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
     * @return void - Asserts no GCP findings are returned for the placeholder fixture.
     */
    public function testGcpPlaceholderKeyIgnored(): void
    {
        self::assertSame([], $this->findingsForRule(self::GCP_PLACEHOLDER, GcpServiceAccountKeyRule::ID));
    }

    /**
     * Verify the key fixture yields one finding per rule on different lines.
     *
     * @return void - Asserts one GCP finding, one private-key finding and distinct line numbers.
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
     * Verify six report formats omit the checked key-body prefix and contain a redaction marker.
     *
     * @return void - Asserts prefix absence and marker presence in each captured report.
     */
    public function testNewDetectorsDoNotLeakRawSecretsAcrossFormats(): void
    {
        $rawSecrets = ['MIIBVgIBADANBgkqhkiG'];

        foreach (['text', 'json', 'sarif', 'github', 'markdown', 'html'] as $format) {
            $output = $this->runGruff(['analyse', self::GCP_FIXTURE, '--format', $format, '--fail-on', 'none', '--config', 'tests/Fixtures/Config/enable-default-off-rules.yaml']);

            foreach ($rawSecrets as $secret) {
                self::assertStringNotContainsString($secret, $output, sprintf('%s report leaked a raw secret.', $format));
            }

            self::assertStringContainsString('redacted', $output, sprintf('%s report should show a redacted preview.', $format));
        }
    }

    /**
     * Verify schema field names and placeholder identifiers produce no findings from the PII fixture rule.
     *
     * @return void - Asserts the selected rule returns no findings for the fixture.
     */
    public function testSchemaAndPlaceholderValuesDoNotFirePhiOrPii(): void
    {
        self::assertSame([], $this->findingsForRule(self::PHI_GUARD_FIXTURE, PiiTestFixtureRule::ID));
    }

    /**
     * Analyse a project-relative fixture with registry defaults and return one rule's findings.
     *
     * @param string $displayPath - Project-relative fixture path resolved under PROJECT_ROOT.
     * @param string $ruleId - Rule identifier whose findings are retained.
     *
     * @return list<Finding> - Matching findings in report order; empty when the rule is disabled or has no findings.
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
     * @param list<string> $arguments - Arguments appended after the PHP binary and CLI script.
     *
     * @return string - Captured stdout after the zero-exit assertion succeeds.
     */
    private function runGruff(array $arguments): string
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return $process->getOutput();
    }
}
