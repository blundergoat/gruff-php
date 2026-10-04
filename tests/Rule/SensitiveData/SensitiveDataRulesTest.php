<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\SensitiveData;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rules\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rules\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rules\SensitiveData\JwtTokenRule;
use GruffPhp\Rules\SensitiveData\PhiPatternRule;
use GruffPhp\Rules\SensitiveData\PiiTestFixtureRule;
use GruffPhp\Rules\SensitiveData\PrivateKeyRule;
use GruffPhp\Rules\SensitiveData\SecretScannerHelper;
use GruffPhp\Engine\Source\SourceDiscovery;
use GruffPhp\Engine\Source\SourceFile;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers the safe sensitive-data findings users receive from source, config, fixtures, and CLI reports.
 *
 * Scenarios protect fixed markers, PHI/PII context, placeholders, comments, occurrence counts, and renderer containment.
 * Users exercise these paths when source analysis or a rendered report encounters credential-like content.
 */
final class SensitiveDataRulesTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Verifies every synthetic credential occurrence reaches the user with a fixed zero-payload marker.
     * Messages and metadata must omit the matched value, its edges, and its length.
     *
     * @return void
     */
    public function testCredentialPatternsAreDetectedWithFixedPreviews(): void
    {
        $findings = $this->analysePath('tests/Fixtures/SensitiveData/synthetic-secrets.php');

        self::assertRuleCount(AwsAccessKeyRule::ID, 1, $findings);
        self::assertRuleCount(ApiKeyPatternRule::ID, 14, $findings);
        self::assertRuleCount(JwtTokenRule::ID, 1, $findings);
        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 1, $findings);
        self::assertRuleCount(PrivateKeyRule::ID, 1, $findings);

        $messages      = implode("\n", array_map(static fn(Finding $finding): string => $finding->message, $findings));
        $metadata      = implode("\n", array_map(
            static fn(Finding $finding): string => json_encode($finding->metadata, JSON_THROW_ON_ERROR),
            $findings,
        ));
        $messageLeaks  = array_values(array_filter($this->secretValues(), static fn(string $secret): bool => str_contains($messages, $secret)));
        $metadataLeaks = array_values(array_filter($this->secretValues(), static fn(string $secret): bool => str_contains($metadata, $secret)));

        self::assertSame([], $messageLeaks, 'Finding messages should not leak secret values.');
        self::assertSame([], $metadataLeaks, 'Finding metadata should not leak secret values.');

        $unexpectedDisplayMarkers = array_values(array_filter($findings, self::isMarkerOutsideGrammar(...)));
        self::assertSame([], $unexpectedDisplayMarkers, 'Every sensitive marker must stay inside the ratified grammar, with no secret-derived edges or lengths.');
    }

    /**
     * Reports whether one finding's marker falls outside the closed grammar FAMILY-CONTRACT.md section 5 ratifies.
     *
     * The grammar admits the bare `[redacted]`, one of the seventeen ratified categories, and a connection marker
     * naming only its already-public scheme. Anything else means a detector put matched text into a marker.
     *
     * @param Finding $finding - Sensitive-data finding whose `metadata.preview` marker is judged.
     *
     * @return bool - true when the marker is outside the grammar; a finding with no string marker is never outside it
     */
    private static function isMarkerOutsideGrammar(Finding $finding): bool
    {
        $marker = $finding->metadata['preview'] ?? null;
        $grammar = '/^\[redacted(?::(?:private-key|jwt|aws-access-key|github-token|slack-token|stripe-live-key'
                   . '|google-api-key|anthropic-api-key|npm-token|gitlab-token|gcp-service-account|email|phone'
                   . '|payment-card|ssn|medicare|mrn|connection-string:[a-z][a-z0-9+.-]*))?\]$/';

        // A finding carrying no string marker has nothing to judge, so the grammar cannot reject it.
        return is_string($marker) && preg_match($grammar, $marker) !== 1;
    }

    /**
     * Verify config like files are discovered and scanned as text.
     *
     * @return void
     */
    public function testConfigLikeFilesAreDiscoveredAndScannedAsText(): void
    {
        $sourceDiscovery = new SourceDiscovery(self::PROJECT_ROOT);
        $result          = $sourceDiscovery->discover(['tests/Fixtures/SensitiveData/config-secrets.json']);

        self::assertCount(1, $result->files);
        self::assertSame(SourceFile::TYPE_TEXT, $result->files[0]->type);

        $unit = (new PhpFileParser())->parse($result->files[0]);

        self::assertFalse($unit->hasParseErrors());
        self::assertSame([], $unit->statements);

        $findings = $this->analyseUnits([$unit]);

        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 1, $findings);
    }

    /**
     * Verify PHI and PII profiles are detected in fixture data.
     *
     * @return void
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
     * @return void
     */
    public function testAllowedDummyValuesAreNotFlagged(): void
    {
        $findings = array_values(array_filter(
                                     $this->analysePath('tests/Fixtures/SensitiveData/safe-dummy-values.php'),
                                     static fn(Finding $finding): bool => str_starts_with($finding->ruleId, 'sensitive-data.'),
                                 ));
        $reported = array_map(static fn(Finding $finding): string => $finding->ruleId . ':' . $finding->line, $findings);

        // Line 11 is AWS's documented example key, a vendor-documented sample (FAMILY-CONTRACT.md section 5), so nothing reports.
        self::assertSame([], $reported);
    }

    /**
     * Models a config that shows where an AWS key goes with a run of X instead of the key.
     * FAMILY-CONTRACT.md section 5 reads a body that is entirely X as naming no credential, while a real key that
     * merely contains a run of X still reports, because hiding it would hide a live credential.
     *
     * @return void
     */
    public function testAwsKeyWhoseWholeBodyIsXIsReadAsMasked(): void
    {
        $masked   = str_repeat('X', 16);
        $source   = "aws_access_key_id = AKIA{$masked}\n"
            . "aws_session_key_id = ASIA{$masked}\n"
            . 'aws_partly_masked_id = AKIA' . 'IOSFODNN' . str_repeat('X', 8) . "\n";
        $unit     = new AnalysisUnit(new SourceFile(__FILE__, 'config.env', SourceFile::TYPE_TEXT), $source, [], [], []);
        $context  = new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
        $findings = (new AwsAccessKeyRule())->analyse($unit, $context);

        self::assertSame([3], array_map(static fn(Finding $finding): ?int => $finding->line, $findings));
    }

    /**
     * Verify matches inside PHP comments are skipped for opt-in pattern rules but private-key still fires.
     *
     * @return void
     */
    public function testInCommentMatchesAreSkippedExceptPrivateKey(): void
    {
        $findings = $this->analysePath('tests/Fixtures/SensitiveData/comments-skipped.php');

        self::assertRuleCount(ApiKeyPatternRule::ID, 0, $findings);
        self::assertRuleCount(AwsAccessKeyRule::ID, 0, $findings);
        self::assertRuleCount(JwtTokenRule::ID, 0, $findings);
        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 0, $findings);
        self::assertRuleCount(PhiPatternRule::ID, 0, $findings);
        self::assertRuleCount(PiiTestFixtureRule::ID, 0, $findings);
        self::assertRuleCount(PrivateKeyRule::ID, 1, $findings);
    }

    /**
     * Verify vendor-documented sample values are not reported while a live-shaped key still is.
     *
     * AWS's documented example key and the jwt.io sample token are samples (FAMILY-CONTRACT section 5); every value
     * is assembled from parts so this file stores none of them whole.
     *
     * @return void
     */
    public function testDocumentedSamplesAreNotReported(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-documented-samples-');
        self::assertIsString($path);
        $path   .= '.php';
        $example = 'AKIA' . 'IOSFODNN7' . 'EXAMPLE';
        $live    = 'AKIA' . 'Q7R2M8N4' . 'P6T9V1X3';
        $jwtSampleToken     = implode('.', [
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            'eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ',
            'SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c',
        ]);
        $source  = "<?php\n\n"
                   . '$example = ' . var_export($example, true) . ";\n"
                   . '$live = ' . var_export($live, true) . ";\n"
                   . '$token = ' . var_export($jwtSampleToken, true) . ";\n";
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'src/documented-samples.php'));
            $findings = array_values(array_filter(
                                         $this->analyseUnits([$unit]),
                                         static fn(Finding $finding): bool => in_array($finding->ruleId, [AwsAccessKeyRule::ID, JwtTokenRule::ID], true),
            ));

            self::assertSame([[AwsAccessKeyRule::ID, 4]], array_map(static fn(Finding $finding): array => [$finding->ruleId, $finding->line], $findings));
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Verify placeholder PHI examples are suppressed without muting real-looking values.
     *
     * @return void
     */
    public function testPhiPlaceholderExamplesAreNotFlagged(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-phi-placeholder-');
        self::assertIsString($path);
        $path                .= '.md';
        $placeholderMedicare = '2345 ' . '67890 ' . '1';
        $realMedicare        = '2123 ' . '45678 ' . '1';
        $source              = implode("\n", [
            sprintf('"medicare_number": { "value": "%s", "confidence": "high", "source_snippet": "Medicare: %s" },', $placeholderMedicare, $placeholderMedicare),
            '$VOUCHER->set(\'PatientFundMembershipNum\', \'123456789\');',
            sprintf('"patient_medicare": "%s"', $realMedicare),
            '',
        ]);
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'docs/examples/inline-phi-placeholder.md', SourceFile::TYPE_TEXT));
            $findings = array_values(array_filter(
                                         $this->analyseUnits([$unit]),
                                         static fn(Finding $finding): bool => $finding->ruleId === PhiPatternRule::ID,
                                     ));

            self::assertCount(1, $findings);
            self::assertSame(3, $findings[0]->line);
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Placeholder words must begin a token, never sit inside one, in both directions.
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}> - value, whether identifier words split tokens, expected
     */
    public static function placeholderValueCases(): array
    {
        return [
            // A word that merely contains a placeholder word is a real value and must stay reportable.
            'latest is not test'         => ['latestReleaseCredentialZq7Xw2Lp9', true, false],
            'contest is not test'        => ['contest', true, false],
            'attestation is not test'    => ['attestation', true, false],
            // Glued, camelCase, snake_case, and separated placeholders stay suppressed.
            'digit-suffixed changeme'    => ['changeme123', true, true],
            'camelCase fake'             => ['fakeToken', true, true],
            'PascalCase changeme'        => ['ChangeMe', true, true],
            'separated example'          => ['example-password', true, true],
            'snake_case test'            => ['my_test_secret', true, true],
            'low-cardinality filler'     => ['xxxxxxxx', true, true],
            'empty literal'              => ['', true, true],
            // A token that begins with a placeholder word is a glued dummy, as the operator decided on 2026-09-19.
            'unbroken testkey'           => ['TESTKEY', true, true],
            'glued testpass'             => ['testpass99', true, true],
            'glued example placeholder'  => ['sk_live_exampleplaceholder', true, true],
            // AWS's documented example key, assembled so this file never holds it, is a documented sample however it is split.
            'aws example key, words'     => ['AKIA' . 'IOSFODNN7' . 'EXAMPLE', true, true],
            'aws example key, whole run' => ['AKIA' . 'IOSFODNN7' . 'EXAMPLE', false, true],
            // A live-shaped key is one alphanumeric run with no placeholder word, so it still reports.
            'live-shaped key, whole run' => ['AKIA' . 'Q7R2M8N4' . 'P6T9V1X3', false, false],
        ];
    }

    /**
     * Verify the placeholder filter matches words that begin a token rather than any substring.
     *
     * @param string $candidateValue             - Candidate value handed to the filter.
     * @param bool   $shouldSplitIdentifierWords - Whether identifier word boundaries also split tokens.
     * @param bool   $isPlaceholder              - Whether the filter must suppress the value.
     *
     * @return void
     */
    #[DataProvider('placeholderValueCases')]
    public function testPlaceholderWordsMustBeginAToken(string $candidateValue, bool $shouldSplitIdentifierWords, bool $isPlaceholder): void
    {
        self::assertSame($isPlaceholder, SecretScannerHelper::isLikelyDummyValue($candidateValue, $shouldSplitIdentifierWords));
    }

    /**
     * Verify secret rules respect detector selection config.
     *
     * @return void
     */
    public function testSecretRulesRespectDetectorSelectionConfig(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(self::PROJECT_ROOT))->load(
            'tests/Fixtures/Config/disable-jwt-token.yaml',
            $registry,
        );
        $findings = $this->analyseUnits(
            [$this->unitForPath('tests/Fixtures/SensitiveData/synthetic-secrets.php')],
            $config,
        );

        self::assertRuleCount(JwtTokenRule::ID, 0, $findings);
        self::assertRuleCount(AwsAccessKeyRule::ID, 1, $findings);
    }

    /**
     * Verify CLI text and JSON reports do not leak full secrets.
     *
     * @return void
     * @throws JsonException
     */
    public function testCliTextAndJsonReportsDoNotLeakFullSecrets(): void
    {
        [$text, $json] = $this->secretLeakReports();

        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $reportLeaks = array_values(array_filter(
                                        $this->secretValues(),
                                        static fn(string $secret): bool => str_contains($text, $secret) || str_contains($json, $secret),
                                    ));

        self::assertSame([], $reportLeaks, 'Reports should not leak secret values.');

        self::assertStringContainsString('redacted', $text);
        self::assertStringContainsString('redacted', $json);
    }

    /**
     * Assert the expected sensitive-data finding count for a rule.
     *
     * @param string        $ruleId - Rule whose findings are isolated before counting.
     * @param int           $expectedCount - Findings the rule must report for this fixture.
     * @param list<Finding> $findings - Full analysis output to filter by rule id.
     *
     * @return void
     */
    private static function assertRuleCount(string $ruleId, int $expectedCount, array $findings): void
    {
        self::assertCount(
            $expectedCount,
            array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId)),
            sprintf('Expected %d findings for %s.', $expectedCount, $ruleId),
        );
    }

    /**
     * Analyse sensitive-data fixtures and return findings for assertions.
     *
     * @param string $path - Project-relative fixture path to parse and scan.
     *
     * @return list<Finding> - every rule finding for the fixture, in registry order; empty when nothing flagged
     */
    private function analysePath(string $path): array
    {
        return $this->analyseUnits([$this->unitForPath($path)]);
    }

    /**
     * Analyse sensitive-data fixtures and return findings for assertions.
     *
     * @param list<AnalysisUnit> $units - Pre-parsed units to run the default rule set over.
     * @param ?AnalysisConfig    $config - Override config; null applies the registry defaults with the database-URL rule switched on.
     *
     * @return list<Finding> - aggregated findings the default rule set produced across the units; empty when none fired
     */
    private function analyseUnits(array $units, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? self::configWithDatabaseUrlRule($registry)),
        );
    }

    /**
     * Builds the default config with the database-URL rule switched on, since it ships off by default.
     *
     * @param RuleRegistry $registry - Default registry the config is seeded from.
     *
     * @return AnalysisConfig - registry defaults with `sensitive-data.database-url-password` enabled
     */
    private static function configWithDatabaseUrlRule(RuleRegistry $registry): AnalysisConfig
    {
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings(DatabaseUrlPasswordRule::ID);

        // It ships off by default; enabling it here mirrors a project that sets `enabled: true` for it.
        return $config->withRuleSettings(DatabaseUrlPasswordRule::ID, new RuleSettings(
            true,
            $settings->thresholds,
            $settings->options,
            $settings->severityThreshold,
            $settings->excludeFromScore,
        ));
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path - Filesystem path.
     *
     * @return AnalysisUnit - the parsed unit, typed PHP for .php paths and plain text otherwise
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $absolutePath = self::PROJECT_ROOT . '/' . $path;
        $type         = str_ends_with($path, '.php') ? SourceFile::TYPE_PHP : SourceFile::TYPE_TEXT;

        // Non-PHP fixtures parse as plain text so secret scanners still see the bytes.
        return (new PhpFileParser())->parse(new SourceFile($absolutePath, $path, $type));
    }

    /**
     * Run text and JSON reports over the secret fixtures.
     *
     * @return array{string, string} - the same fixtures rendered twice: human-readable text first, machine JSON second, so the leak check can scan
     *                       both surfaces
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
     * Runs the CLI as a user would and returns the rendered report for leak-safety assertions.
     *
     * @param list<string> $arguments - CLI arguments appended after the gruff binary path.
     *
     * @return string - the gruff CLI's stdout; stderr is dropped and a non-zero exit already fails the test before returning
     */
    private function runGruff(array $arguments): string
    {
        $process = new Process(array_merge([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php'], $arguments), self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return $process->getOutput();
    }

    /**
     * Build synthetic secret-like values for sensitive-data tests.
     *
     * @return list<string> - the canonical plaintext secrets the leak checks search reports and findings for
     */
    private function secretValues(): array
    {
        // Canonical secrets the leak check hunts for; split by concatenation so this file isn't flagged.
        return [
            'AKIA' . 'Z9Y8X7W6V5U4T3R2',
            'sk_live_' . '51N7uQbP0JZ6r' . 'T9vL3mK8sX2y',
            'ghp_' . 'aBcDeFgHiJkLmNoPqRs' . 'TuVwXyZ0123456789',
            'github_pat_' . '11AA22BB33CC44DD55' . 'EE66FF77GG88HH99II00',
            'gho_' . 'aBcDeFgHiJkLmNoPqRs' . 'TuVwXyZ0123456789',
            'ghu_' . 'aBcDeFgHiJkLmNoPqRs' . 'TuVwXyZ0123456789',
            'ghs_' . 'aBcDeFgHiJkLmNoPqRs' . 'TuVwXyZ0123456789',
            'sk-proj-' . 'uQ7vR2mN5xP8zL1k' . 'C4bH9sT6wY3aD0fG',
            'sk-ant-api03-' . 'uQ7vR2mN5xP8zL1k' . 'C4bH9sT6wY3aD0fG',
            'xoxb-' . '123456789012-987654321098' . '-AbCdEfGhIjKlMnOpQrSt',
            'https://hooks.slack.com/services/' . 'T12345678/B12345678/' . 'AbCdEfGhIjKlMnOpQrStUvWxYz',
            'npm_' . 'aBcDeFgHiJkLmNoPqRs' . 'TuVwXyZ012345',
            'AIza' . 'SyA1b2C3d4E5f6G7' . 'h8I9j0K1l2M3n4O5p6Q',
            '?sv=2024-01-01&ss=b&srt=sco&sp=rl&se=2026-01-01T00:00:00Z'
            . '&st=2025-01-01T00:00:00Z&spr=https&sig='
            . 'rN7pQ4sV9xY2zA5bC8dF1gH4jK7mP0sV3wX6yZ%3D',
            'glpat-' . 'aBcDeFgHiJkLmNoPq' . 'RsTuVwXyZ',
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
