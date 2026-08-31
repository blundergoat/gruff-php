<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Cli\Application;
use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers the `analyse` experience from CLI arguments through the report and exit status users receive.
 *
 * Scenarios include paths, syntax errors, thresholds, config, scoring, editor links, and every human- or machine-readable renderer.
 * Users rely on these paths whenever they invoke the primary analysis command locally, in an editor, or in CI.
 */
final class AnalyseCliTest extends CliTestCase
{
    /**
     * Verify analyse command runs as no op.
     *
     * @return void
     */
    public function testAnalyseCommandRunsAsNoOp(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/mixed',
                                   '--no-config',
                                   '--fail-on',
                                   'error',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff-php ' . Application::VERSION, $process->getOutput());
        self::assertStringContainsString('Discovered: 7', $process->getOutput());
        self::assertStringContainsString('Ignored: 0', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/mixed/vendor/ignored.php', $process->getOutput());
    }

    /**
     * Verify zero-file scans are explicit and unscored in both human and machine-readable reports.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandReportsEmptyAnalysisAsUnscored(): void
    {
        $baseArguments = [
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/composer.lock',
            '--no-config',
            '--no-cache',
        ];
        $textProcess = new Process([...$baseArguments, '--format', 'text'], self::PROJECT_ROOT);
        $textProcess->run();

        self::assertSame(0, $textProcess->getExitCode(), $textProcess->getErrorOutput());
        self::assertStringContainsString('Discovered: 0', $textProcess->getOutput());
        self::assertStringContainsString('[EMPTY-ANALYSIS] No scannable PHP files were discovered', $textProcess->getOutput());
        self::assertStringNotContainsString('Composite: A', $textProcess->getOutput());
        self::assertStringNotContainsString(PHP_EOL . 'Score' . PHP_EOL, $textProcess->getOutput());

        $jsonProcess = new Process([...$baseArguments, '--format', 'json'], self::PROJECT_ROOT);
        $jsonProcess->run();

        self::assertSame(0, $jsonProcess->getExitCode(), $jsonProcess->getErrorOutput());

        $report      = $this->decodeJsonOutput($jsonProcess);
        $summary     = $report['summary'] ?? null;
        $diagnostics = $report['diagnostics'] ?? null;

        self::assertIsArray($summary);
        self::assertSame(0, $summary['discoveredFiles'] ?? null);
        self::assertSame(0, $summary['exitCode'] ?? null);
        $score = $report['score'] ?? null;
        self::assertIsArray($score);
        self::assertSame(['grade' => 'N/A', 'score' => 0], $score['composite'] ?? null);
        self::assertIsArray($diagnostics);
        $emptyAnalysisDiagnostic = $diagnostics[0] ?? null;
        self::assertIsArray($emptyAnalysisDiagnostic);
        self::assertSame('empty-analysis', $emptyAnalysisDiagnostic['type'] ?? null);
        self::assertSame(false, $emptyAnalysisDiagnostic['invalidatesRun'] ?? null);
    }

    /**
     * Verify analyse command supports an explicit single-file option.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandSupportsSingleFileOption(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   '--file',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                                   '--no-config',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report      = $this->decodeJsonOutput($process);
        $runMetadata = $report['run'] ?? null;
        $summary     = $report['summary'] ?? null;

        self::assertIsArray($runMetadata);
        self::assertIsArray($summary);
        self::assertSame(['tests/Fixtures/Source/mixed/alpha.php'], $runMetadata['inputs'] ?? null);
        self::assertSame(1, $summary['discoveredFiles'] ?? null);
        self::assertSame(1, $summary['parsedFiles'] ?? null);
        self::assertSame(0, $summary['exitCode'] ?? null);
    }

    /**
     * Verify analyse command reports syntax errors without aborting.
     *
     * @return void
     */
    public function testAnalyseCommandReportsSyntaxErrorsWithoutAborting(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   self::PROJECT_ROOT . '/bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                                   'tests/Fixtures/Source/syntax-error/broken.php',
                                   '--no-config',
                               ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[PARSE-ERROR] tests/Fixtures/Source/syntax-error/broken.php:4', $process->getOutput());
        self::assertStringContainsString('Parse errors: 1', $process->getOutput());
        self::assertStringContainsString('Exit code: 2', $process->getOutput());
    }

    /**
     * Verify analyse command reports warning findings without failing by default.
     *
     * @return void
     */
    public function testAnalyseCommandReportsWarningFindingsWithoutFailingByDefault(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                                   '--config',
                                   'tests/Fixtures/Config/file-length-warning.yaml',
                                   '--fail-on',
                                   'error',
                                   '--no-baseline',
                                   '--no-cache',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $expected = $this->goldenOutput('text-warning.txt');

        self::assertSame($expected, $process->getOutput());
    }

    /**
     * Verify analyse command fails when finding meets default error threshold.
     *
     * @return void
     */
    public function testAnalyseCommandFailsWhenFindingMeetsDefaultErrorThreshold(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                                   '--config',
                                   'tests/Fixtures/Config/file-length-error.yaml',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('[error] size.file-length', $process->getOutput());
        self::assertStringContainsString('Exit code: 1', $process->getOutput());
    }

    /**
     * Verify analyse command can fail on warning threshold.
     *
     * @return void
     */
    public function testAnalyseCommandCanFailOnWarningThreshold(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                                   '--config',
                                   'tests/Fixtures/Config/file-length-warning.yaml',
                                   '--fail-on',
                                   'warning',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Fail threshold: warning', $process->getOutput());
        self::assertStringContainsString('Exit code: 1', $process->getOutput());
    }

    /**
     * Verify analyse command outputs JSON report.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandOutputsJsonReport(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/mixed/alpha.php',
                                   '--config',
                                   'tests/Fixtures/Config/file-length-warning.yaml',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'error',
                                   '--no-baseline',
                                   '--no-cache',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $expected = $this->goldenOutput('json-warning.json');

        self::assertSame($expected, $process->getOutput());

        $report   = $this->decodeJsonOutput($process);
        $summary  = $report['summary'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertSame('gruff.analysis.v3', $report['schemaVersion'] ?? null);
        self::assertIsArray($summary);
        self::assertSame(1, $summary['discoveredFiles'] ?? null);
        self::assertSame(0, $summary['exitCode'] ?? null);
        self::assertIsArray($findings);
        self::assertCount(4, $findings);

        $sizeFinding = null;
        foreach ($findings as $finding) {
            if (is_array($finding) && ($finding['ruleId'] ?? null) === 'size.file-length') {
                $sizeFinding = $finding;
                break;
            }
        }
        self::assertIsArray($sizeFinding);
        self::assertSame('warning', $sizeFinding['severity'] ?? null);
    }

    /** Verify bounded PHP remains analysed and retains sensitive-data scanning. */
    public function testBoundedDeepScanKeepsTextLevelSecurityFindings(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            '--no-config',
            '--profile',
            'security',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--no-cache',
            '--deep-scan-budget',
            '1:1',
            'tests/Fixtures/SensitiveData/synthetic-secrets.php',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report      = $this->decodeJsonOutput($process);
        $summary     = $report['summary'] ?? null;
        $diagnostics = $report['diagnostics'] ?? null;
        $findings    = $report['findings'] ?? null;

        self::assertIsArray($summary);
        self::assertSame(1, $summary['discoveredFiles'] ?? null);
        self::assertSame(1, $summary['parsedFiles'] ?? null);
        self::assertSame(0, $summary['parseErrors'] ?? null);
        self::assertIsArray($diagnostics);
        self::assertCount(1, $diagnostics);
        $diagnostic = $diagnostics[0];
        self::assertIsArray($diagnostic);
        self::assertSame('bounded-deep-scan', $diagnostic['type'] ?? null);
        self::assertSame(false, $diagnostic['invalidatesRun'] ?? null);
        $message = $diagnostic['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('maxLines=1; maxBytes=1; override=cli', $message);
        self::assertIsArray($findings);
        self::assertNotSame([], $findings);
        $firstFinding = $findings[0];
        self::assertIsArray($firstFinding);
        self::assertSame('sensitive-data.aws-access-key', $firstFinding['ruleId'] ?? null);
    }

    /** Verify malformed CLI budgets fail before analysis. */
    public function testDeepScanBudgetRejectsPartialCliOverride(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            '--no-config',
            '--format',
            'json',
            '--deep-scan-budget',
            '100',
            'tests/Fixtures/Source/mixed/alpha.php',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        $report      = $this->decodeJsonOutput($process);
        $diagnostics = $report['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);
        $diagnostic = $diagnostics[0] ?? null;
        self::assertIsArray($diagnostic);
        $message = $diagnostic['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString(
            '--deep-scan-budget must be <positive-lines>:<positive-bytes> or off.',
            $message,
        );
    }

    /** Verify an atomic CLI budget wins over the config block. */
    public function testDeepScanBudgetCliOverrideWinsOverConfig(): void
    {
        $configPath = tempnam(self::PROJECT_ROOT . '/tests', 'gruff-budget-');
        self::assertIsString($configPath);
        $yamlPath = $configPath . '.yaml';
        self::assertTrue(rename($configPath, $yamlPath));
        self::assertNotFalse(file_put_contents(
            $yamlPath,
            "schemaVersion: gruff-php.config.v0.1\ndeepScanBudget:\n    maxLines: 1\n    maxBytes: 1\n",
        ));

        try {
            $configBound = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '--config',
                $yamlPath,
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-cache',
                'tests/Fixtures/Source/mixed/alpha.php',
            ], self::PROJECT_ROOT);
            $configBound->run();
            $configReport      = $this->decodeJsonOutput($configBound);
            $configDiagnostics = $configReport['diagnostics'] ?? null;
            self::assertIsArray($configDiagnostics);
            $configDiagnostic = $configDiagnostics[0] ?? null;
            self::assertIsArray($configDiagnostic);
            self::assertSame('bounded-deep-scan', $configDiagnostic['type'] ?? null);
            $configMessage = $configDiagnostic['message'] ?? null;
            self::assertIsString($configMessage);
            self::assertStringContainsString('override=config', $configMessage);

            $cliOverride = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '--config',
                $yamlPath,
                '--deep-scan-budget',
                '1000:100000',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-cache',
                'tests/Fixtures/Source/mixed/alpha.php',
            ], self::PROJECT_ROOT);
            $cliOverride->run();
            $cliReport = $this->decodeJsonOutput($cliOverride);

            self::assertSame(0, $cliOverride->getExitCode(), $cliOverride->getErrorOutput());
            self::assertSame([], $cliReport['diagnostics'] ?? null);
        } finally {
            self::assertTrue(unlink($yamlPath));
        }
    }

    /** Verify every analyse renderer exposes the bounded-scan note. */
    public function testBoundedDeepScanDiagnosticIsVisibleInEveryFormat(): void
    {
        foreach (['text', 'json', 'html', 'markdown', 'github', 'hotspot', 'sarif'] as $format) {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'analyse',
                '--no-config',
                '--format',
                $format,
                '--fail-on',
                'none',
                '--no-cache',
                '--deep-scan-budget',
                '1:1',
                'tests/Fixtures/Source/mixed/alpha.php',
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(0, $process->getExitCode(), sprintf('%s: %s', $format, $process->getErrorOutput()));
            self::assertStringContainsString('bounded-deep-scan', strtolower($process->getOutput()), $format);
            self::assertStringContainsString('maxLines=1; maxBytes=1; override=cli', $process->getOutput(), $format);
        }
    }

    /**
     * Verify analyse command outputs JSON parse errors.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandOutputsJsonParseErrors(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/syntax-error',
                                   '--format',
                                   'json',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());

        $report      = $this->decodeJsonOutput($process);
        $summary     = $report['summary'] ?? null;
        $diagnostics = $report['diagnostics'] ?? null;

        self::assertIsArray($summary);
        self::assertSame(1, $summary['parseErrors'] ?? null);
        self::assertSame(2, $summary['exitCode'] ?? null);
        self::assertIsArray($diagnostics);
        $firstDiagnostic = $diagnostics[0] ?? null;

        self::assertIsArray($firstDiagnostic);
        self::assertSame('parse-error', $firstDiagnostic['type'] ?? null);
    }

    /**
     * Verify --exclude-rule is execution-level: the excluded rule's findings neither display nor trip the fail gate.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandExcludeRuleSkipsExecutionAndExitCode(): void
    {
        $baseArguments = [
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--no-config',
            '--no-baseline',
            '--format',
            'json',
            '--fail-on',
            'advisory',
        ];

        $controlProcess = new Process($baseArguments, __DIR__ . '/../..');
        $controlProcess->run();
        self::assertSame(1, $controlProcess->getExitCode(), 'the fixture must trip the advisory gate for the exclusion proof to mean anything');

        $excludedProcess = new Process([...$baseArguments, '--exclude-rule', 'docs.missing-public-phpdoc'], __DIR__ . '/../..');
        $excludedProcess->run();
        self::assertSame(0, $excludedProcess->getExitCode(), $excludedProcess->getErrorOutput());

        $report  = $this->decodeJsonOutput($excludedProcess);
        $summary = $report['summary'] ?? null;
        self::assertIsArray($summary);
        $findingCounts = $summary['findings'] ?? null;
        self::assertIsArray($findingCounts);
        self::assertSame(0, $findingCounts['total'] ?? null);
    }

    /**
     * Verify analyse command applies configured rule selection.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandAppliesConfiguredRuleSelection(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--config',
                                   'tests/Fixtures/Config/only-size-rules.yaml',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report  = $this->decodeJsonOutput($process);
        $summary = $report['summary'] ?? null;
        self::assertIsArray($summary);
        $findingCounts = $summary['findings'] ?? null;
        self::assertIsArray($findingCounts);
        self::assertSame(0, $findingCounts['total'] ?? null);
    }

    /**
     * Verify security profile limits rule execution to security and sensitive-data rules.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandSecurityProfileRunsSecurityRulesOnly(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Security/cumulative-security.php',
                                   '--no-config',
                                   '--profile',
                                   'security',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        $score    = $report['score'] ?? null;
        self::assertIsArray($findings);
        self::assertIsArray($score);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertNotCount(0, $findings);
        self::assertSame('F', $composite['grade'] ?? null);

        foreach ($findings as $index => $finding) {
            self::assertIsArray($finding, sprintf('Finding %d should be an array.', $index));
            $ruleId = $finding['ruleId'] ?? null;
            self::assertIsString($ruleId, sprintf('Finding %d should include a string ruleId.', $index));
            self::assertTrue(
                str_starts_with($ruleId, 'security.') || str_starts_with($ruleId, 'sensitive-data.'),
                'Unexpected rule from security profile: ' . $ruleId,
            );
        }
    }

    /**
     * Verify security profile replaces configured rule selection.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandSecurityProfileOverridesConfiguredSelection(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Security/cumulative-security.php',
                                   '--config',
                                   'tests/Fixtures/Config/only-size-rules.yaml',
                                   '--profile',
                                   'security',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        $ruleIds = array_map(
            static fn(mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertContains('security.dangerous-function-call', $ruleIds);
        self::assertNotContains('docs.missing-file-phpdoc', $ruleIds);
    }

    /**
     * Verify analyse command rejects unknown execution profiles.
     *
     * @return void
     */
    public function testAnalyseCommandReportsInvalidProfile(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   '--profile',
                                   'security-plus',
                                   'tests/Fixtures/Source/Code',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[USAGE-ERROR] Unsupported profile "security-plus". Use default or security.', $process->getOutput());
    }

    /**
     * Verify analyse command applies configured path ignores.
     *
     * @return void
     */
    public function testAnalyseCommandAppliesConfiguredPathIgnores(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/mixed',
                                   '--config',
                                   'tests/Fixtures/Config/ignore-alpha.yaml',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Discovered: 6', $process->getOutput());
        self::assertStringContainsString('Ignored: 1', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/mixed/alpha.php', $process->getOutput());
    }

    /**
     * Verify analyse command reports invalid selection config.
     *
     * @return void
     */
    public function testAnalyseCommandReportsInvalidSelectionConfig(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   '--config',
                                   'tests/Fixtures/Config/invalid-selection-rule.yaml',
                                   'tests/Fixtures/Source/Code',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[CONFIG-ERROR] Unknown rule id "size.nope" in "selection.rules".', $process->getOutput());
    }

    /**
     * Models a user running `analyse` with an old non-empty secret-preview list.
     * The command must exit 2 with one safe correction and never echo the configured value.
     *
     * @return void
     * @throws JsonException When the command unexpectedly returns malformed JSON instead of the config-error report.
     */
    public function testAnalyseCommandRejectsConfiguredLegacySecretPreview(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/SensitiveData/synthetic-secrets.php',
                                   '--config',
                                   'tests/Fixtures/Config/allow-aws-preview.yaml',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        $report = $this->decodeJsonOutput($process);
        // Missing diagnostics would mean the rejected configuration never reached the report users and integrations inspect.
        $diagnostics = $report['diagnostics'] ?? null;
        self::assertIsArray($diagnostics);
        // An empty diagnostics list would leave the user with exit 2 but no actionable correction.
        $diagnostic = $diagnostics[0] ?? null;
        self::assertIsArray($diagnostic);
        self::assertSame(
            'Config key "allowlists.secretPreviews" only accepts an empty list; remove all configured entries because secret previews no longer suppress findings.',
            $diagnostic['message'] ?? null,
        );
        self::assertStringNotContainsString('T3R2', $process->getOutput());
    }

    /**
     * Verify analyse command reports missing infection executable in run mode.
     *
     * @return void
     */
    public function testAnalyseCommandReportsMissingInfectionExecutableInRunMode(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--infection-run',
                                   '--infection-bin',
                                   'tests/Fixtures/Mutation/missing-infection',
                                   '--infection-report',
                                   'tests/Fixtures/Mutation/Infection/infection-clean.json',
                                   '--fail-on',
                                   'none',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[MUTATION-TOOL-ERROR]', $process->getOutput());
        self::assertStringContainsString('Infection executable not found', $process->getOutput());
    }

    /**
     * Verify analyse command outputs scoring data in JSON report.
     *
     * @return void
     * @throws JsonException
     */
    public function testAnalyseCommandOutputsScoringDataInJsonReport(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--format',
                                   'json',
                                   '--fail-on',
                                   'none',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        $score  = $report['score'] ?? null;
        $diff   = $report['diff'] ?? null;

        self::assertIsArray($score);
        self::assertNull($diff);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertSame('A', $composite['grade'] ?? null);
        self::assertSame('full-project', $score['scope'] ?? null);
        self::assertArrayNotHasKey('diff', $report);
    }

    /**
     * Verify analyse command outputs HTML report.
     *
     * @return void
     */
    public function testAnalyseCommandOutputsHtmlReport(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--format',
                                   'html',
                                   '--fail-on',
                                   'none',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<section class="verdict">', $process->getOutput());
        self::assertStringContainsString('<section class="pillars">', $process->getOutput());
        self::assertStringContainsString('<table class="pillar-list">', $process->getOutput());
        self::assertStringContainsString('score drivers', $process->getOutput());
        self::assertStringContainsString('Mutation is omitted when no Infection report is supplied.', $process->getOutput());
        self::assertStringNotContainsString('<td class="pillar-name">mutation</td>', $process->getOutput());
        self::assertStringNotContainsString('fonts.googleapis.com', $process->getOutput());
    }

    /**
     * Verify analyse command supports HTML editor links.
     *
     * @return void
     */
    public function testAnalyseCommandSupportsHtmlEditorLinks(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--format',
                                   'html',
                                   '--fail-on',
                                   'none',
                                   '--report-editor-link',
                                   'vscode',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('href="vscode://file/', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/Code/OrderCalculator.php:16', $process->getOutput());
    }

    /**
     * Verify analyse command defaults HTML locations to copyable spans.
     *
     * @return void
     */
    public function testAnalyseCommandDefaultsHtmlLocationsToCopyableSpans(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--format',
                                   'html',
                                   '--fail-on',
                                   'none',
                                   '--report-editor-link',
                                   'none',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<span class="loc-link" tabindex="0" data-path="tests/Fixtures/Source/Code/OrderCalculator.php:16">', $process->getOutput());
        self::assertStringNotContainsString('vscode://file/', $process->getOutput());
        self::assertStringNotContainsString('phpstorm://open', $process->getOutput());
    }

    /**
     * Verify analyse command supports interactive HTML report.
     *
     * @return void
     */
    public function testAnalyseCommandSupportsInteractiveHtmlReport(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--format',
                                   'html',
                                   '--fail-on',
                                   'none',
                                   '--report-interactive',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('class="finding-filters"', $process->getOutput());
        self::assertStringContainsString('<script type="module">', $process->getOutput());

        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--format',
                                   'html',
                                   '--fail-on',
                                   'none',
                                   '--report-interactive=false',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('class="finding-filters"', $process->getOutput());
        self::assertStringNotContainsString('<script type="module">', $process->getOutput());
    }

    /**
     * Verify analyse command reports invalid HTML report options.
     *
     * @return void
     */
    public function testAnalyseCommandReportsInvalidHtmlReportOptions(): void
    {
        $editorProcess = new Process([
                                         PHP_BINARY,
                                         __DIR__ . '/../../bin/gruff-php',
                                         'analyse',
                                         'tests/Fixtures/Source/Code',
                                         '--format',
                                         'html',
                                         '--fail-on',
                                         'none',
                                         '--report-editor-link=bad',
                                         '--no-config',
                                     ], __DIR__ . '/../..');
        $editorProcess->run();

        self::assertSame(2, $editorProcess->getExitCode());
        self::assertStringContainsString('<section class="diagnostics">', $editorProcess->getOutput());
        self::assertStringContainsString('--report-editor-link must be one of: vscode, phpstorm, none.', $editorProcess->getOutput());

        $interactiveProcess = new Process([
                                              PHP_BINARY,
                                              __DIR__ . '/../../bin/gruff-php',
                                              'analyse',
                                              'tests/Fixtures/Source/Code',
                                              '--format',
                                              'html',
                                              '--fail-on',
                                              'none',
                                              '--report-interactive=maybe',
                                              '--no-config',
                                          ], __DIR__ . '/../..');
        $interactiveProcess->run();

        self::assertSame(2, $interactiveProcess->getExitCode());
        self::assertStringContainsString('<section class="diagnostics">', $interactiveProcess->getOutput());
        self::assertStringContainsString('--report-interactive must be true or false.', $interactiveProcess->getOutput());
    }

    /**
     * Verify analyse command outputs github annotations.
     *
     * @return void
     */
    public function testAnalyseCommandOutputsGithubAnnotations(): void
    {
        $process = new Process([
                                   PHP_BINARY,
                                   __DIR__ . '/../../bin/gruff-php',
                                   'analyse',
                                   'tests/Fixtures/Source/Code',
                                   '--format',
                                   'github',
                                   '--fail-on',
                                   'none',
                                   '--no-config',
                               ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('::error file=tests/Fixtures/Source/Code/OrderCalculator.php', $process->getOutput());
        self::assertStringContainsString('title=docs.missing-public-phpdoc', $process->getOutput());
    }

    /**
     * Loads the expected CLI output users should see and normalises its version stamps before comparison.
     * This keeps renderer checks focused on the UI contract when the application version changes.
     *
     * @param string $fileName - Basename under tests/Fixtures/Cli/Golden whose contents are the expected output.
     *
     * @return string - fixture text with the header/`"version"` stamps replaced by the live version
     */
    private function goldenOutput(string $fileName): string
    {
        $contents = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/Cli/Golden/' . $fileName);
        self::assertIsString($contents);

        $normalised = preg_replace(
            ['/^gruff-php \d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)? /m', '/"version": "\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?"/'],
            ['gruff-php ' . Application::VERSION . ' ', '"version": "' . Application::VERSION . '"'],
            $contents,
        );
        self::assertIsString($normalised);

        return $normalised;
    }

}
