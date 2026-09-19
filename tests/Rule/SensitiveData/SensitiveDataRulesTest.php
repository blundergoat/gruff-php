<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\SensitiveData;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\SensitiveData\ApiKeyPatternRule;
use GruffPhp\Rules\SensitiveData\AwsAccessKeyRule;
use GruffPhp\Rules\SensitiveData\DatabaseUrlPasswordRule;
use GruffPhp\Rules\SensitiveData\HardcodedEnvValueRule;
use GruffPhp\Rules\SensitiveData\HighEntropyStringRule;
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
 * Scenarios protect fixed markers, PHI/PII context, placeholders, comments, entropy exclusions, occurrence counts, and renderer containment.
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
        self::assertRuleCount(HardcodedEnvValueRule::ID, 1, $findings);
        // Two, not three: the fixture's pure-hex digest is a checksum, skipped at any entropy bar since 2026-09-19.
        self::assertRuleCount(HighEntropyStringRule::ID, 2, $findings);
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
        self::assertRuleCount(HardcodedEnvValueRule::ID, 1, $findings);
        self::assertRuleCount(HighEntropyStringRule::ID, 1, $findings);
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

        // Line 11 is AWS's documented example key. An AWS key is one alphanumeric run, so since 2026-09-19 no
        // placeholder word can hide it and it reports, as it does in gruff-go. Every other placeholder stays quiet.
        self::assertSame(['sensitive-data.aws-access-key:11'], $reported);
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
        self::assertRuleCount(HardcodedEnvValueRule::ID, 0, $findings);
        self::assertRuleCount(HighEntropyStringRule::ID, 0, $findings);
        self::assertRuleCount(PhiPatternRule::ID, 0, $findings);
        self::assertRuleCount(PiiTestFixtureRule::ID, 0, $findings);
        self::assertRuleCount(PrivateKeyRule::ID, 1, $findings);
    }

    /**
     * Verify hardcoded env value requires secret like value evidence.
     *
     * @return void
     */
    public function testHardcodedEnvValueRequiresSecretLikeValueEvidence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-safe-env-');
        self::assertIsString($path);
        $path   .= '.php';
        $source = "<?php\n\n"
                  . 'const QBO_ACCESS_TOKEN_EXPIRES_AT = ' . var_export('accessTokenExpiresAt', true) . ";\n"
                  . 'const QBO_REFRESH_TOKEN_VALID_PERIOD = ' . var_export('refreshTokenValidationPeriod', true) . ";\n"
                  . 'const ACCESS_TOKEN_PAYMENTS_KEY = ' . var_export('AirwallexApiRequester.payments', true) . ";\n"
                  . '$header = ' . var_export('AUTH_MODE_X_' . 'API_KEY=x-api-key', true) . ";\n"
                  . '$prefix = ' . var_export('TOKEN_CACHE_' . 'KEY_PREFIX=voice.' . 'olb.oauth_token.pg_', true) . ";\n"
                  . '$formId = ' . var_export('OLB_VOICE_CSRF_' . 'TOKEN_ID=olb_voice_agent', true) . ";\n"
                  . '$secret = ' . var_export('API_TOKEN=' . 'qR8vT3mK6p' . 'L9xS2nD4eG', true) . ";\n";
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'tests/Fixtures/SensitiveData/inline-env-values.php'));
            $findings = array_values(array_filter(
                                         $this->analyseUnits([$unit]),
                                         static fn(Finding $finding): bool => $finding->ruleId === HardcodedEnvValueRule::ID,
            ));

            self::assertCount(1, $findings);
            self::assertSame('[redacted]', $findings[0]->metadata['preview'] ?? null);
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Verify route and URL path literals are not treated as high-entropy secrets.
     *
     * @return void
     */
    public function testHighEntropyRoutePathsAreNotFlagged(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-route-entropy-');
        self::assertIsString($path);
        $path   .= '.php';
        $secret = 'M7qP2vL9' . 'xZ4aB8nC' . '3dF6gH1j' . 'K5mN0rS2' . 'tV9wY4zQ';
        $source = "<?php\n\n"
                  . '$help = ' . var_export('/hc/en-au/sections/360005188513-Appointments', true) . ";\n"
                  . '$report = ' . var_export('/hc/en-au/sections/360005149694-Communication-Report', true) . ";\n"
                  . '$secret = ' . var_export($secret, true) . ";\n";
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'tests/Fixtures/SensitiveData/inline-route-entropy.php'));
            $findings = array_values(array_filter(
                                         $this->analyseUnits([$unit]),
                                         static fn(Finding $finding): bool => $finding->ruleId === HighEntropyStringRule::ID,
            ));

            self::assertCount(1, $findings);
            self::assertSame('[redacted]', $findings[0]->metadata['preview'] ?? null);
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Verify opaque dotted tokens stay entropy-eligible while JWTs remain the JWT rule's alone.
     *
     * @return void
     */
    public function testDottedOpaqueTokensAreEntropyEligibleWhileJwtsStayDelegated(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'gruff-dotted-token-entropy-');
        self::assertIsString($tempPath);
        $path = $tempPath . '.php';
        self::assertTrue(rename($tempPath, $path));
        // Both tokens are concatenated from short chunks so this test file's own source never
        // matches gruff's secret scanners; only the generated fixture carries the full literals.
        $opaqueToken = 'vTr4K2mQ.9fXZ81beLKw' . '72mYh37Rp.hV5c2LqN8d' . 'WjS6xTAGy';
        $sampleJwt   = 'eyJhbGciOiJIUzI1NiIs' . 'InR5cCI6IkpXVCJ9.eyJ' . 'zdWIiOiIxMjM0NTY3ODkw' . 'In0.dozjgNryP4J3jVmN' . 'Hl0w5N65nCX63nCz';
        $source      = "<?php\n\n"
                  . '$sessionToken = ' . var_export($opaqueToken, true) . ";\n"
                  . '$sampleJwt = ' . var_export($sampleJwt, true) . ";\n"
                  . '$routeName = ' . var_export('authentication.permissions.middleware-groups', true) . ";\n"
                  . '$versionLabel = ' . var_export('3.11.4-security-hardening-release-notes', true) . ";\n"
                  . '$metricsDomain = ' . var_export('telemetry.blundergoat-analytics.example', true) . ";\n"
                  . '$archivePath = ' . var_export('storage/app.private/uploads.tmp/archive-name.tar.gz', true) . ";\n";
        try {
            self::assertNotFalse(file_put_contents($path, $source));

            $unit        = (new PhpFileParser())->parse(new SourceFile($path, 'tests/Fixtures/SensitiveData/inline-dotted-token-entropy.php'));
            $findings    = $this->analyseUnits([$unit]);
            $highEntropy = array_values(array_filter(
                                            $findings,
                                            static fn(Finding $finding): bool => $finding->ruleId === HighEntropyStringRule::ID,
                                        ));
            $jwtFindings = array_values(array_filter(
                                            $findings,
                                            static fn(Finding $finding): bool => $finding->ruleId === JwtTokenRule::ID,
                                        ));

            // The opaque dotted token reports as high entropy only; the JWT reports under the JWT rule only
            // (no double report); the dotted route, version, domain, and path literals all stay silent.
            self::assertCount(1, $highEntropy);
            self::assertSame(3, $highEntropy[0]->line);
            self::assertSame('[redacted]', $highEntropy[0]->metadata['preview'] ?? null);
            self::assertCount(1, $jwtFindings);
            self::assertSame(4, $jwtFindings[0]->line);
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Verify gruff configuration path literals are not treated as high-entropy secrets.
     *
     * @return void
     */
    public function testHighEntropyGruffConfigPathsAreNotFlagged(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-config-path-entropy-');
        self::assertIsString($path);
        $path   .= '.php';
        $secret = 'M7qP2vL9' . 'xZ4aB8nC' . '3dF6gH1j' . 'K5mN0rS2' . 'tV9wY4zQ';
        $source = "<?php\n\n"
                  . '$configPath = ' . var_export('rules.naming.identifier-quality.excludeFromScore', true) . ";\n"
                  . '$secret = ' . var_export($secret, true) . ";\n";
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'tests/Fixtures/SensitiveData/inline-config-path-entropy.php'));
            $findings = array_values(array_filter(
                                         $this->analyseUnits([$unit]),
                                         static fn(Finding $finding): bool => $finding->ruleId === HighEntropyStringRule::ID,
            ));

            self::assertCount(1, $findings);
            self::assertSame('[redacted]', $findings[0]->metadata['preview'] ?? null);
        } finally {
            self::assertTrue(unlink($path));
        }
    }

    /**
     * Verify medical terminology metadata is not treated as embedded secret material.
     *
     * @return void
     */
    public function testHighEntropyMedicalStandardMetadataIsNotFlagged(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-medical-entropy-');
        self::assertIsString($path);
        $path   .= '.php';
        $secret = 'M7qP2vL9' . 'xZ4aB8nC' . '3dF6gH1j' . 'K5mN0rS2' . 'tV9wY4zQ';
        $source = "<?php\n\n"
                  . '$metadata = ' . var_export('{"ConceptCode":"A","CodeSystemOID":"2.16.840.1.113883.5.83","CodeSystemCode":"PH_ObservationInterpretation_HL7_V3","ValueSetCode":"PHVS_ObservationInterpretation_HL7_V3"}', true) . ";\n"
                  . '$secret = ' . var_export($secret, true) . ";\n";
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'tests/Fixtures/SensitiveData/inline-medical-entropy.php'));
            $findings = array_values(array_filter(
                                         $this->analyseUnits([$unit]),
                                         static fn(Finding $finding): bool => $finding->ruleId === HighEntropyStringRule::ID,
            ));

            self::assertCount(1, $findings);
            self::assertSame('[redacted]', $findings[0]->metadata['preview'] ?? null);
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
     * Threshold configurations and the entropy-rule lines each must report.
     *
     * @return array<string, array{0: string|null, 1: list<int>}> - config fixture path, or null for defaults, and the exact lines
     */
    public static function highEntropyThresholdCases(): array
    {
        return [
            // Defaults: 32 characters and 4.2 bits. Line 3 carries 4.0 bits, line 5 is 24 characters, line 6 is hex,
            // and line 7 concatenates a 34-character literal onto a short one.
            'defaults report only the long, high-entropy literals' => [null, [4, 7]],
            // A lowered bar admits line 3's 4.0 bits, and a pure-hex digest still stays silent.
            'entropy 3.5 admits the 4.0-bit literal, never the digest' => ['tests/Fixtures/Config/high-entropy-entropy-3-5.yaml', [3, 4, 7]],
            'minLength 48 excludes the 34-character literals' => ['tests/Fixtures/Config/high-entropy-min-length-48.yaml', []],
            // Below 32 the candidate pattern itself must widen, or a lowered minLength would do nothing.
            'minLength 20 admits the 24-character literal' => ['tests/Fixtures/Config/high-entropy-min-length-20.yaml', [4, 5, 7]],
            // The widened pattern must not pair the `'.'` between line 7's literals and swallow the secret's quote.
            'minLength 1 keeps each literal whole' => ['tests/Fixtures/Config/high-entropy-min-length-1.yaml', [4, 5, 7]],
            // Past PCRE's repeat limit the pattern must still compile, or the rule would warn and report nothing.
            'minLength 70000 compiles and reports nothing' => ['tests/Fixtures/Config/high-entropy-min-length-70000.yaml', []],
        ];
    }

    /**
     * Verify both configured thresholds are load-bearing in both directions, read through project configuration the
     * way a user's `.gruff-php.yaml` reaches the rule, and that the rule keeps the decided family contract.
     *
     * @param string|null $configPath    - Project-relative config fixture, or null for registry defaults.
     * @param list<int>   $expectedLines - Exact lines of `entropy-thresholds.php` the rule must report.
     *
     * @return void
     */
    #[DataProvider('highEntropyThresholdCases')]
    public function testHighEntropyThresholdsAreLoadBearing(?string $configPath, array $expectedLines): void
    {
        $registry = RuleRegistry::defaults();
        $config   = $configPath === null ? null : (new ConfigLoader(self::PROJECT_ROOT))->load($configPath, $registry);
        $findings = $this->analyseUnits([$this->unitForPath('tests/Fixtures/SensitiveData/entropy-thresholds.php')], $config);
        $lines    = array_values(array_map(
            static fn(Finding $finding): ?int => $finding->line,
            array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === HighEntropyStringRule::ID),
        ));

        self::assertSame($expectedLines, $lines);

        // The family contract decided on 2026-09-02: warning, medium confidence, on by default, 32 and 4.2.
        $definition = (new HighEntropyStringRule())->definition();
        self::assertSame(Severity::Warning, $definition->defaultSeverity);
        self::assertSame(Confidence::Medium, $definition->confidence);
        self::assertTrue($definition->isEnabledByDefault);
        self::assertEquals(['minLength' => 32, 'entropy' => 4.2], $definition->defaultThresholds);
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
            // AWS's documented example key, assembled so this file never holds it: identifier words split it, whole
            // alphanumeric runs do not.
            'aws example key, words'     => ['AKIA' . 'IOSFODNN7' . 'EXAMPLE', true, true],
            'aws example key, whole run' => ['AKIA' . 'IOSFODNN7' . 'EXAMPLE', false, false],
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
     * @param ?AnalysisConfig    $config - Override config; null applies the registry defaults.
     *
     * @return list<Finding> - aggregated findings the default rule set produced across the units; empty when none fired
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
