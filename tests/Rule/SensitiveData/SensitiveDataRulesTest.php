<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\SensitiveData;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rule\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rule\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rule\SensitiveData\HardcodedEnvValueRule;
use GruffPhp\Rule\SensitiveData\HighEntropyStringRule;
use GruffPhp\Rule\SensitiveData\JwtTokenRule;
use GruffPhp\Rule\SensitiveData\PhiPatternRule;
use GruffPhp\Rule\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rule\SensitiveData\PrivateKeyRule;
use GruffPhp\Source\SourceDiscovery;
use GruffPhp\Source\SourceFile;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers SensitiveDataRulesTest behavior.
 */
final class SensitiveDataRulesTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verify credential patterns are detected with redacted previews.
     *
     * @return void No return value.
     */
    public function testCredentialPatternsAreDetectedWithRedactedPreviews(): void
    {
        $findings = $this->analysePath('tests/Fixtures/SensitiveData/synthetic-secrets.php');

        self::assertRuleCount(AwsAccessKeyRule::ID, 1, $findings);
        self::assertRuleCount(ApiKeyPatternRule::ID, 5, $findings);
        self::assertRuleCount(JwtTokenRule::ID, 1, $findings);
        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 1, $findings);
        self::assertRuleCount(HardcodedEnvValueRule::ID, 1, $findings);
        self::assertRuleCount(HighEntropyStringRule::ID, 3, $findings);
        self::assertRuleCount(PrivateKeyRule::ID, 1, $findings);

        $messages = implode("\n", array_map(static fn (Finding $finding): string => $finding->message, $findings));
        $metadata = implode("\n", array_map(
            static fn (Finding $finding): string => json_encode($finding->metadata, JSON_THROW_ON_ERROR),
            $findings,
        ));
        $messageLeaks  = array_values(array_filter($this->secretValues(), static fn (string $secret): bool => str_contains($messages, $secret)));
        $metadataLeaks = array_values(array_filter($this->secretValues(), static fn (string $secret): bool => str_contains($metadata, $secret)));

        self::assertSame([], $messageLeaks, 'Finding messages should not leak secret values.');
        self::assertSame([], $metadataLeaks, 'Finding metadata should not leak secret values.');
    }

    /**
     * Verify config like files are discovered and scanned as text.
     *
     * @return void No return value.
     */
    public function testConfigLikeFilesAreDiscoveredAndScannedAsText(): void
    {
        $discovery = new SourceDiscovery(self::PROJECT_ROOT);
        $result    = $discovery->discover(['tests/Fixtures/SensitiveData/config-secrets.json']);

        self::assertCount(1, $result->files);
        self::assertSame(SourceFile::TYPE_TEXT, $result->files[0]->type);

        $unit = (new PhpFileParser())->parse($result->files[0]);

        self::assertFalse($unit->hasParseErrors());
        self::assertSame([], $unit->statements);

        $findings = $this->analyseUnits([$unit]);

        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 1, $findings);
        self::assertRuleCount(HardcodedEnvValueRule::ID, 1, $findings);
        self::assertRuleCount(HighEntropyStringRule::ID, 1, $findings);
    }

    /**
     * Verify PHI and PII profiles are detected in fixture data.
     *
     * @return void No return value.
     */
    public function testPhiAndPiiProfilesAreDetectedInFixtureData(): void
    {
        $findings = $this->analysePath('tests/Fixtures/SensitiveData/profile-data.json');

        self::assertRuleCount(PhiPatternRule::ID, 5, $findings);
        self::assertRuleCount(PiiTestFixtureRule::ID, 3, $findings);
    }

    /**
     * Verify allowed dummy values are not flagged.
     *
     * @return void No return value.
     */
    public function testAllowedDummyValuesAreNotFlagged(): void
    {
        $findings = array_values(array_filter(
            $this->analysePath('tests/Fixtures/SensitiveData/safe-dummy-values.php'),
            static fn (Finding $finding): bool => str_starts_with($finding->ruleId, 'sensitive-data.'),
        ));

        self::assertSame([], $findings);
    }

    /**
     * Verify hardcoded env value requires secret like value evidence.
     *
     * @return void No return value.
     */
    public function testHardcodedEnvValueRequiresSecretLikeValueEvidence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-safe-env-');
        self::assertIsString($path);
        $path .= '.php';
        $source = "<?php\n\n"
            . '$header = ' . var_export('AUTH_MODE_X_' . 'API_KEY=x-api-key', true) . ";\n"
            . '$prefix = ' . var_export('TOKEN_CACHE_' . 'KEY_PREFIX=voice.' . 'olb.oauth_token.pg_', true) . ";\n"
            . '$formId = ' . var_export('OLB_VOICE_CSRF_' . 'TOKEN_ID=olb_voice_agent', true) . ";\n"
            . '$secret = ' . var_export('API_TOKEN=' . 'qR8vT3mK6p' . 'L9xS2nD4eG', true) . ";\n";
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'tests/Fixtures/SensitiveData/inline-env-values.php'));
            $findings = array_values(array_filter(
                $this->analyseUnits([$unit]),
                static fn (Finding $finding): bool => $finding->ruleId === HardcodedEnvValueRule::ID,
            ));

            self::assertCount(1, $findings);
            self::assertStringContainsString('API_TOKEN', $findings[0]->message);
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Verify secret rules respect detector selection config.
     *
     * @return void No return value.
     */
    public function testSecretRulesRespectDetectorSelectionConfig(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/disable-high-entropy.yaml',
            $registry,
        );
        $findings = $this->analyseUnits(
            [$this->unitForPath('tests/Fixtures/SensitiveData/synthetic-secrets.php')],
            $config,
        );

        self::assertRuleCount(HighEntropyStringRule::ID, 0, $findings);
        self::assertRuleCount(AwsAccessKeyRule::ID, 1, $findings);
    }

    /**
     * Verify CLI text and JSON reports do not leak full secrets.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testCliTextAndJsonReportsDoNotLeakFullSecrets(): void
    {
        [$text, $json] = $this->secretLeakReports();

        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $reportLeaks = array_values(array_filter(
            $this->secretValues(),
            static fn (string $secret): bool => str_contains($text, $secret) || str_contains($json, $secret),
        ));

        self::assertSame([], $reportLeaks, 'Reports should not leak secret values.');

        self::assertStringContainsString('redacted', $text);
        self::assertStringContainsString('redacted', $json);
    }

    /**
     * @param list<Finding> $findings
     * @return void No return value.
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
    private function analysePath(string $path): array
    {
        return $this->analyseUnits([$this->unitForPath($path)]);
    }

    /**
     * @param list<AnalysisUnit> $units
     * @return list<Finding>
     */
    private function analyseUnits(array $units, ?AnalysisConfig $config = null): array
    {
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
     * @return AnalysisUnit Fixture value.
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $absolutePath = self::PROJECT_ROOT . '/' . $path;
        $type         = str_ends_with($path, '.php') ? SourceFile::TYPE_PHP : SourceFile::TYPE_TEXT;

        return (new PhpFileParser())->parse(new SourceFile($absolutePath, $path, $type));
    }

    /**
     * Run text and JSON reports over the secret fixtures.
     *
     * @return array{string, string} Text output followed by JSON output.
     */
    private function secretLeakReports(): array
    {
        $paths = [
            'tests/Fixtures/SensitiveData/synthetic-secrets.php',
            'tests/Fixtures/SensitiveData/config-secrets.json',
        ];

        return [
            $this->runGruff(['analyse', ...$paths, '--fail-on', 'none', '--no-config']),
            $this->runGruff(['analyse', ...$paths, '--format', 'json', '--fail-on', 'none', '--no-config']),
        ];
    }

    /**
     * @param list<string> $arguments
     * @return string CLI stdout.
     */
    private function runGruff(array $arguments): string
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff'], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private function secretValues(): array
    {
        return [
            'AKIA' . 'Z9Y8X7W6V5U4T3R2',
            'sk_live_' . '51N7uQbP0JZ6r' . 'T9vL3mK8sX2y',
            'ghp_' . 'aBcDeFgHiJkLmNoPqRs' . 'TuVwXyZ0123456789',
            'sk-proj-' . 'uQ7vR2mN5xP8zL1k' . 'C4bH9sT6wY3aD0fG',
            'sk-ant-api03-' . 'uQ7vR2mN5xP8zL1k' . 'C4bH9sT6wY3aD0fG',
            'xoxb-' . '123456789012-987654321098' . '-AbCdEfGhIjKlMnOpQrSt',
            'eyJhbGciOiJIUzI1NiJ9.' . 'eyJzdWIiOiIxMjM0NTY3ODkwIn0.' . 'sflKxwRJSMeKKF2Q' . 'T4fwpMeJf36POk6yJV_adQssw5c',
            'mysql://appuser:' . 'rN7pQ4sV9xY2zA5b' . '@db.internal/app',
            'postgres://reporter:' . 'qR8vT3mK6pL9xS2n' . '@db.internal/reporting',
            'API_TOKEN=' . 'rN7pQ4sV9xY2zA5bC8dG',
            'API_TOKEN=' . 'qR8vT3mK6pL9xS2nD4eG',
            'M7qP2vL9xZ4aB8nC3dF6' . 'gH1jK5mN0rS2tV9wY4zQ',
            '0123456789abcdef0123456789abcdef' . '0123456789abcdef0123456789abcdef',
            'AaBbCcDdEeFfGgHhIiJjKkLlMm' . 'NnOoPpQqRrSsTtUuVvWwXxYyZzQqRr',
            'N8pQ3rT6uW9xY2zA5bC8' . 'dF1gH4jK7mP0sV3wX6yZ',
        ];
    }
}
