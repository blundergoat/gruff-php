<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\SensitiveData;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rules\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rules\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rules\SensitiveData\JwtTokenRule;
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
 * Scenarios protect fixed markers, PII context, placeholders, comments, occurrence counts, and renderer containment.
 * Users exercise these paths when source analysis or a rendered report encounters credential-like content.
 */
final class SensitiveDataRulesTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Checks detector counts, literal secretValues() matches in messages and JSON-encoded metadata, and preview syntax.
     *
     * @return void - Asserts expected counts, no literal matches, a marker on every sensitive-data finding, and no marker rejected by the regex.
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
     * Checks a string preview against redaction-marker syntax derived from FAMILY-CONTRACT.md section 5.
     * The pattern admits the bare marker, the listed categories, and a connection marker with a scheme-shaped suffix.
     * Rejection indicates invalid marker syntax; it does not establish that the marker contains matched secret text.
     *
     * @param Finding $finding - Finding from the default rule set; only a sensitive-data finding must carry a marker.
     *
     * @return bool - true when the marker is outside the grammar, or when a sensitive-data finding carries no string marker
     */
    private static function isMarkerOutsideGrammar(Finding $finding): bool
    {
        $marker = $finding->metadata['preview'] ?? null;
        $grammar = '/^\[redacted(?::(?:private-key|jwt|aws-access-key|github-token|slack-token|stripe-live-key'
                   . '|google-api-key|anthropic-api-key|npm-token|gitlab-token|gcp-service-account|email|phone'
                   . '|payment-card|ssn|medicare|mrn|connection-string:[a-z][a-z0-9+.-]*))?\]$/';

        // Markers are unconditional on sensitive-data findings; the default rule set also returns findings that carry none.
        if (!is_string($marker)) {
            return $finding->pillar === Pillar::SensitiveData;
        }

        return preg_match($grammar, $marker) !== 1;
    }

    /**
     * Verify config like files are discovered and scanned as text.
     *
     * @return void - Asserts text discovery, an empty AST without parse errors, and one database-URL finding.
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
     * Checks the profile-data fixture with PII detection enabled through the registry helper.
     *
     * @return void - Asserts exactly three PII-rule findings without checking their individual classifications.
     */
    public function testPhiAndPiiProfilesAreDetectedInFixtureData(): void
    {
        $findings = $this->analysePath('tests/Fixtures/SensitiveData/profile-data.json');

        self::assertRuleCount(PiiTestFixtureRule::ID, 3, $findings);
    }

    /**
     * Verifies the safe-dummy fixture has no sensitive-data findings.
     *
     * @return void - Asserts the filtered sensitive-data finding list is empty.
     */
    public function testAllowedDummyValuesAreNotFlagged(): void
    {
        $findings = array_values(array_filter(
                                     $this->analysePath('tests/Fixtures/SensitiveData/safe-dummy-values.php'),
                                     static fn(Finding $finding): bool => str_starts_with($finding->ruleId, 'sensitive-data.'),
                                 ));
        $reported = array_map(static fn(Finding $finding): string => $finding->ruleId . ':' . $finding->line, $findings);

        // AWS's documented example key is exempt as a vendor sample under FAMILY-CONTRACT.md section 5.
        self::assertSame([], $reported);
    }

    /**
     * Checks stock development pairs on loopback and single-label hosts against other credential cases.
     * The fixture expects findings for a stock pair on a dotted host and a non-stock password on localhost.
     *
     * @return void - Asserts only the dotted-host and non-stock-password cases produce findings.
     */
    public function testStockDevelopmentCredentialsOnLocalHostsAreNotReported(): void
    {
        // Assembled here, so this file holds no whole credential-shaped URL; the stock words themselves are not secrets.
        $realPassword = 'Vq7' . 'mZ2' . 'kP9';
        $source       = implode("\n", [
            '<?php',
            "\$a = '" . 'postgresql://' . 'postgres:postgres' . "@localhost:5432/app';",
            "\$b = '" . 'mysql://' . 'root:root' . "@127.0.0.1/app';",
            "\$c = '" . 'postgres://' . 'user:pass' . "@[::1]:5432/app';",
            "\$d = '" . 'redis://' . 'Guest:Secret' . "@db:6379';",
            "\$e = '" . 'postgres://' . 'postgres:postgres' . "@db.example.com/app';",
            "\$f = '" . 'postgres://' . 'postgres:' . $realPassword . "@localhost/app';",
            '',
        ]);
        $unit     = new AnalysisUnit(new SourceFile(__FILE__, 'config.php', SourceFile::TYPE_TEXT), $source, [], [], []);
        $context  = new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
        $findings = (new DatabaseUrlPasswordRule())->analyse($unit, $context);

        self::assertSame([6, 7], array_map(static fn(Finding $finding): ?int => $finding->line, $findings));
    }

    /**
     * Checks fully X-masked AWS key bodies alongside a synthetic key whose body only ends in X.
     * The fully masked cases are suppressed, while the partly X-filled case remains reportable.
     *
     * @return void - Asserts only the partly X-filled fixture line is reported.
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
     * Verify two credentials of the same rule on one source line both survive registry deduplication with distinct columns.
     *
     * @return void - Asserts two AWS access-key findings on line 2 carrying distinct 1-based column offsets.
     */
    public function testTwoSecretsOnSameLineAreBothReportedWithDistinctColumns(): void
    {
        $firstKey  = 'AKIA' . 'Q7R2M8N4' . 'P6T9V1X3';
        $secondKey = 'AKIA' . 'W3E5R7T9' . 'Y2U4I6O8';
        $source    = "<?php\n\$keys = ['{$firstKey}', '{$secondKey}'];\n";
        $unit      = new AnalysisUnit(new SourceFile(__FILE__, 'src/keys.php', SourceFile::TYPE_TEXT), $source, [], [], []);
        $findings  = array_values(array_filter(
            $this->analyseUnits([$unit]),
            static fn (Finding $finding): bool => $finding->ruleId === AwsAccessKeyRule::ID,
        ));

        self::assertCount(2, $findings);
        self::assertSame([2, 2], array_map(static fn (Finding $finding): ?int => $finding->line, $findings));
        $columns = array_map(static fn (Finding $finding): ?int => $finding->column, $findings);
        self::assertNotNull($columns[0]);
        self::assertNotNull($columns[1]);
        self::assertGreaterThan($columns[0], $columns[1]);
    }

    /**
     * Verifies the PHP-comment fixture is silent for API keys, AWS keys, JWTs, database URLs, and PII.
     * A private-key finding remains expected in that fixture.
     *
     * @return void - Asserts zero findings for those five rules and one for the private-key rule.
     */
    public function testInCommentMatchesAreSkippedExceptPrivateKey(): void
    {
        $findings = $this->analysePath('tests/Fixtures/SensitiveData/comments-skipped.php');

        self::assertRuleCount(ApiKeyPatternRule::ID, 0, $findings);
        self::assertRuleCount(AwsAccessKeyRule::ID, 0, $findings);
        self::assertRuleCount(JwtTokenRule::ID, 0, $findings);
        self::assertRuleCount(DatabaseUrlPasswordRule::ID, 0, $findings);
        self::assertRuleCount(PiiTestFixtureRule::ID, 0, $findings);
        self::assertRuleCount(PrivateKeyRule::ID, 1, $findings);
    }

    /**
     * Verify the AWS and JWT rules ignore their documented samples while the AWS rule reports the live-shaped key.
     * The FAMILY-CONTRACT section 5 samples and live-shaped key are assembled so this file stores no complete value.
     *
     * @return void - Asserts the filtered AWS and JWT findings contain only the live-shaped AWS key.
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
     * Provides token-boundary, dummy-value, and documented-sample cases for the placeholder filter.
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}> - Cases: candidate, identifier-splitting flag, expected dummy classification.
     */
    public static function placeholderValueCases(): array
    {
        return [
            // An internal placeholder substring must not by itself classify a value as a dummy.
            'latest is not test'         => ['latestReleaseCredentialZq7Xw2Lp9', true, false],
            'contest is not test'        => ['contest', true, false],
            'attestation is not test'    => ['attestation', true, false],
            // Glued, camelCase, snake_case, and separated placeholders remain classified as dummy values.
            'digit-suffixed changeme'    => ['changeme123', true, true],
            'camelCase fake'             => ['fakeToken', true, true],
            'PascalCase changeme'        => ['ChangeMe', true, true],
            'separated example'          => ['example-password', true, true],
            'snake_case test'            => ['my_test_secret', true, true],
            'low-cardinality filler'     => ['xxxxxxxx', true, true],
            'empty literal'              => ['', true, true],
            // A placeholder prefix marks a glued dummy value even without a separator.
            'unbroken testkey'           => ['TESTKEY', true, true],
            'glued testpass'             => ['testpass99', true, true],
            'glued example placeholder'  => ['sk_live_exampleplaceholder', true, true],
            // AWS's documented example key, assembled so this file never holds it, is a documented sample however it is split.
            'aws example key, words'     => ['AKIA' . 'IOSFODNN7' . 'EXAMPLE', true, true],
            'aws example key, whole run' => ['AKIA' . 'IOSFODNN7' . 'EXAMPLE', false, true],
            // A live-shaped key without a placeholder prefix must remain a non-dummy candidate.
            'live-shaped key, whole run' => ['AKIA' . 'Q7R2M8N4' . 'P6T9V1X3', false, false],
        ];
    }

    /**
     * Verify the placeholder filter matches words that begin a token rather than any substring.
     *
     * @param string $candidateValue             - Candidate value handed to the filter.
     * @param bool   $shouldSplitIdentifierWords - Whether identifier word boundaries also split tokens.
     * @param bool   $isPlaceholder              - Expected dummy classification from the helper.
     *
     * @return void - Asserts the helper's dummy classification matches each supplied expectation.
     */
    #[DataProvider('placeholderValueCases')]
    public function testPlaceholderWordsMustBeginAToken(string $candidateValue, bool $shouldSplitIdentifierWords, bool $isPlaceholder): void
    {
        self::assertSame($isPlaceholder, SecretScannerHelper::isLikelyDummyValue($candidateValue, $shouldSplitIdentifierWords));
    }

    /**
     * Verify secret rules respect detector selection config.
     *
     * @return void - Asserts JWT findings are disabled while one AWS finding remains.
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
     * Checks CLI text and JSON for literal occurrences of the synthetic values listed by secretValues().
     *
     * @return void - Requires decodable JSON, no listed literal matches in either report, and the substring 'redacted' in each.
     * @throws JsonException - If the CLI's JSON report cannot be decoded.
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
     * @return void - Asserts the filtered finding count equals the expected count.
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
     * @return list<Finding> - Finalized findings ordered by file, line, rule ID, and message; empty when none remain.
     */
    private function analysePath(string $path): array
    {
        return $this->analyseUnits([$this->unitForPath($path)]);
    }

    /**
     * Analyse sensitive-data fixtures and return findings for assertions.
     *
     * @param list<AnalysisUnit> $units - Pre-parsed units to run the default rule set over.
     * @param ?AnalysisConfig $config - Explicit settings, or null to enable database-URL and PII rules on registry defaults.
     *
     * @return list<Finding> - Finalized findings from the configured registry; empty when none remain.
     */
    private function analyseUnits(array $units, ?AnalysisConfig $config = null): array
    {
        $registry = RuleRegistry::defaults();

        return $registry->analyse(
            $units,
            new RuleContext(self::PROJECT_ROOT, $config ?? self::configWithOptInSensitiveRules($registry)),
        );
    }

    /**
     * Builds the default config with the opt-in sensitive-data rules switched on, as a project that enables them would.
     *
     * @param RuleRegistry $registry - Default registry the config is seeded from.
     *
     * @return AnalysisConfig - Registry defaults with database-URL-password and PII-fixture detection enabled.
     */
    private static function configWithOptInSensitiveRules(RuleRegistry $registry): AnalysisConfig
    {
        $config = AnalysisConfig::fromRegistry($registry);

        // Both ship off by default; enabling them here mirrors a project that sets enabled: true for each.
        foreach ([DatabaseUrlPasswordRule::ID, PiiTestFixtureRule::ID] as $ruleId) {
            $settings = $config->ruleSettings($ruleId);
            $config   = $config->withRuleSettings($ruleId, new RuleSettings(
                true,
                $settings->thresholds,
                $settings->options,
                $settings->severityThreshold,
                $settings->excludeFromScore,
            ));
        }

        return $config;
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path - Project-relative fixture path, prefixed with PROJECT_ROOT before reading.
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
     * @return array{string, string} - Reports for the same fixtures, with CLI text first and JSON second.
     */
    private function secretLeakReports(): array
    {
        // The realistic-PII fixture supplies findings whose matches must render redacted; the URL credentials in the other two are userinfo, not PII.
        $paths = [
            'tests/Fixtures/SensitiveData/synthetic-secrets.php',
            'tests/Fixtures/SensitiveData/config-secrets.json',
            'tests/Fixtures/SensitiveData/pii-realistic.php',
        ];

        return [
            $this->runGruff(['analyse', ...$paths, '--fail-on', 'none', '--config', 'tests/Fixtures/Config/enable-default-off-rules.yaml']),
            $this->runGruff(['analyse', ...$paths, '--format', 'json', '--fail-on', 'none', '--config', 'tests/Fixtures/Config/enable-default-off-rules.yaml']),
        ];
    }

    /**
     * Runs the CLI as a user would and returns the rendered report for leak-safety assertions.
     *
     * @param list<string> $arguments - CLI arguments appended after the gruff binary path.
     *
     * @return string - CLI stdout after a zero-exit assertion; failure diagnostics include both stderr and stdout.
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
        // Keep each complete credential pattern out of this source while returning the canonical values used by leak checks.
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
