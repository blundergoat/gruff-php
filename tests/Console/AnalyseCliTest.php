<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Cli\Application;
use GruffPhp\Cli\Command\AnalyseCommandSetupBuilder;
use JsonException;
use Symfony\Component\Process\Process;

/**
 * Exercises analyse CLI reports, exit statuses, path selection, configuration, and scoring.
 * Covers text, JSON, HTML, and GitHub annotation output.
 * Includes launch-directory path resolution and run-invalidating diagnostic envelopes.
 */
final class AnalyseCliTest extends CliTestCase
{
    /**
     * Checks mixed-fixture discovery and report output when only errors fail the scan.
     *
     * @return void - Asserts exit 0, version text, discovery counts, and the ignored vendor path.
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
        // vendor/ignored.php is skipped despite the repository .gitignore, because vendor never defers to a .gitignore (section 7).
        self::assertStringContainsString('Discovered: 6', $process->getOutput());
        self::assertStringContainsString('Ignored: 1', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/mixed/vendor/ignored.php', $process->getOutput());
    }

    /**
     * Verify zero-file scans are explicit and unscored in both human and machine-readable reports.
     *
     * @return void - Asserts empty-scan text, null composite values, and a non-invalidating JSON diagnostic.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
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
        // A run that scored nothing publishes nulls because consumers could interpret 0 as a failing grade.
        self::assertSame(['grade' => null, 'score' => null], $score['composite'] ?? null);
        self::assertIsArray($diagnostics);
        $emptyAnalysisDiagnostic = $diagnostics[0] ?? null;
        self::assertIsArray($emptyAnalysisDiagnostic);
        self::assertSame('empty-analysis', $emptyAnalysisDiagnostic['type'] ?? null);
        self::assertSame(false, $emptyAnalysisDiagnostic['invalidatesRun'] ?? null);
    }

    /**
     * Verify analyse command supports an explicit single-file option.
     *
     * @return void - Asserts the requested input, one discovered and parsed file, and exit 0.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
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
     * Checks the parse-error report and exit 2 when a scan includes a file with invalid PHP.
     *
     * @return void - Asserts the diagnostic location, one parse error, and the reported exit code.
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
     * Checks warning-report output when the CLI explicitly uses the error failure threshold.
     *
     * @return void - Asserts exit 0 and exact text output against the warning fixture.
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
     * Checks exit 1 and the reported finding when the configured file-length rule emits an error.
     *
     * @return void - Asserts the error finding's location and rule ID plus exit 1.
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
        // FAMILY-CONTRACT section 1 fixes the location between severity and rule ID for text findings.

        self::assertStringContainsString('[error] tests/Fixtures/Source/mixed/alpha.php:1 size.file-length', $process->getOutput());
        self::assertStringContainsString('Exit code: 1', $process->getOutput());
    }

    /**
     * Verify analyse command can fail on warning threshold.
     *
     * @return void - Asserts exit 1 and the warning failure threshold in the text report.
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
     * @return void - Asserts golden output, the analysis-v3 schema, four findings, and a warning-severity file-length finding.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
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

    /**
     * Verify analyse command outputs JSON parse errors.
     *
     * @return void - Asserts exit 2, one parse error, and a first diagnostic of type parse-error.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
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
     * Verify analyse command applies configured path ignores.
     *
     * @return void - Asserts exit 0, five discovered files, two ignored paths, and the ignored alpha.php path.
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
        // alpha.php is config-ignored and vendor/ignored.php is a skipped vendor copy (section 7).
        self::assertStringContainsString('Discovered: 5', $process->getOutput());
        self::assertStringContainsString('Ignored: 2', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/mixed/alpha.php', $process->getOutput());
    }

    /**
     * Verify analyse command reports invalid selection config.
     *
     * @return void - Asserts exit 2 and the unknown rule ID in the selection configuration diagnostic.
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
     * Checks rejection of a non-empty legacy secret-preview list with exit 2 and a removal diagnostic.
     * Verifies that stdout omits the configured preview's T3R2 fragment.
     *
     * @return void - Asserts the rejection message and absence of the checked preview fragment.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
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
            'Config key "allowlists.secretPreviews" is removed in 0.6.0: FAMILY-CONTRACT.md section 5 makes category markers unconditional, so the key authorises nothing; delete it from the configuration.',
            $diagnostic['message'] ?? null,
        );
        self::assertStringNotContainsString('T3R2', $process->getOutput());
    }

    /**
     * Verify analyse command reports missing infection executable in run mode.
     *
     * @return void - Asserts exit 2 and a mutation-tool diagnostic naming the missing Infection executable.
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
     * @return void - Asserts grade A, full-project score scope, and absence of a diff field.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
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
     * @return void - Asserts report sections, omission of the mutation row, and absence of the Google Fonts domain.
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
     * @return void - Asserts a VS Code link prefix and the expected fixture path with line number.
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
     * Checks HTML location markup when editor links are explicitly disabled.
     *
     * @return void - Asserts location-span attributes and absence of VS Code and PhpStorm URI prefixes.
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
     * Checks that the interactive option controls HTML filter markup and the module-script marker.
     *
     * @return void - Asserts both markers appear when enabled and disappear with --report-interactive=false.
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
     * @return void - Asserts exit 2 and HTML diagnostics for invalid editor-link and interactive values.
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
     * @return void - Asserts exit 0, a GitHub error annotation prefix, and the missing-PHPDoc rule title.
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
        self::assertStringContainsString('title=docs.missing-phpdoc', $process->getOutput());
    }

    /**
     * Checks the JSON config-error envelope when a sibling-directory target uses a missing config file.
     * The envelope must omit the external input and config field and mark the first diagnostic as run-invalidating.
     *
     * @return void - Asserts exit 2, empty run inputs, no config field, and a run-invalidating config-error diagnostic.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
     */
    public function testAnalyseCommandPublishesTheConfigErrorEnvelopeForATargetNamedFromASiblingDirectory(): void
    {
        $workspace = $this->tempDir();
        self::assertTrue(mkdir($workspace . '/proj'));
        self::assertTrue(mkdir($workspace . '/sib'));
        self::assertNotFalse(file_put_contents($workspace . '/proj/a.php', "<?php\necho 1;\n"));

        try {
            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       '../proj',
                                       '--config',
                                       '../missing.yaml',
                                       '--format',
                                       'json',
                                       '--no-cache',
                                   ], $workspace . '/sib');
            $process->run();

            self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            $report = $this->decodeJsonOutput($process);
            $runBlock = $this->decodedJsonObjectAt($report, 'run');
            self::assertSame([], $runBlock['inputs'] ?? null);
            self::assertArrayNotHasKey('config', $runBlock);
            $diagnostics = $report['diagnostics'] ?? null;
            self::assertIsArray($diagnostics);
            $diagnostic = $this->decodedJsonObject($diagnostics[0] ?? null);
            self::assertSame('config-error', $diagnostic['type'] ?? null);
            self::assertTrue($diagnostic['invalidatesRun'] ?? null);
        } finally {
            $this->removeDir($workspace);
        }
    }

    /**
     * Checks a nested launch using a relative config path against a project-root launch.
     *
     * @return void - Asserts the nested run exits 0 and both decoded findings values match, with null used when absent.
     */
    public function testAnalyseCommandReadsAnExplicitRelativeConfigFromTheLaunchDirectory(): void
    {
        $project = $this->tempDir();
        self::assertTrue(mkdir($project . '/a/b', 0777, true));
        self::assertNotFalse(file_put_contents($project . '/a.php', "<?php\necho 1;\n"));
        self::assertNotFalse(file_put_contents($project . '/cfg.yaml', "schemaVersion: \"gruff-php.config.v0.1\"\nminimumSeverity: error\n"));
        $arguments = [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'analyse', '--format', 'json', '--fail-on', 'none', '--no-baseline', '--no-cache'];

        try {
            $nested = new Process([...$arguments, '--config', '../../cfg.yaml', '../..'], $project . '/a/b');
            $nested->run();
            $inside = new Process([...$arguments, '--config', 'cfg.yaml', '.'], $project);
            $inside->run();

            self::assertSame(0, $nested->getExitCode(), $nested->getOutput() . $nested->getErrorOutput());
            self::assertSame($this->decodeJsonOutput($inside)['findings'] ?? null, $this->decodeJsonOutput($nested)['findings'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Checks baseline generation and application from a nested launch directory using a relative baseline path.
     * Also checks that a missing baseline outside the project yields a versioned JSON error report without the workspace path.
     *
     * @return void - Asserts baseline placement and application, then exit 2 and an analysis-v3 envelope for a missing baseline.
     */
    public function testAnalyseCommandReadsTypedBaselinePathsFromTheLaunchDirectory(): void
    {
        $workspace = $this->tempDir();
        $project   = $workspace . '/outer/project';
        self::assertTrue(mkdir($project . '/a/b', 0777, true));
        self::assertNotFalse(file_put_contents($project . '/a.php', "<?php\necho 1;\n"));
        $arguments = [PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'analyse', '--no-config', '--fail-on', 'none', '--no-cache'];

        try {
            $generated = new Process([...$arguments, '--generate-baseline', '../../base.json', '../..'], $project . '/a/b');
            $generated->run();
            $applied = new Process([...$arguments, '--format', 'json', '--baseline', '../../base.json', '../..'], $project . '/a/b');
            $applied->run();
            $outside = new Process([...$arguments, '--format', 'json', '--baseline', '../missing.json', '.'], $project);
            $outside->run();

            self::assertSame(0, $generated->getExitCode(), $generated->getOutput() . $generated->getErrorOutput());
            self::assertFileExists($project . '/base.json');
            self::assertFileDoesNotExist($workspace . '/outer/base.json');
            self::assertSame(0, $applied->getExitCode(), $applied->getOutput() . $applied->getErrorOutput());
            $baseline = $this->decodedJsonObjectAt($this->decodeJsonOutput($applied), 'baseline');
            self::assertTrue($baseline['applied'] ?? null);
            self::assertSame('base.json', $baseline['path'] ?? null);
            self::assertSame(2, $outside->getExitCode(), $outside->getErrorOutput());
            self::assertStringNotContainsString($workspace, $outside->getOutput());
            self::assertSame('gruff.analysis.v3', $this->decodeJsonOutput($outside)['schemaVersion'] ?? null);
        } finally {
            $this->removeDir($workspace);
        }
    }

    /**
     * Checks rejection of two sibling targets with one run-invalidating target-error diagnostic and no findings.
     * Running one of those targets alone must exit 0.
     *
     * @return void - Asserts the rejected run's JSON envelope and the single-target run's successful exit.
     * @throws JsonException - If decoding the command's stdout as JSON fails.
     */
    public function testAnalyseCommandRefusesSeveralTargetsOutsideTheLaunchDirectoryWithAnEnvelope(): void
    {
        $workspace = $this->tempDir();
        self::assertTrue(mkdir($workspace . '/a'));
        self::assertTrue(mkdir($workspace . '/b'));
        self::assertTrue(mkdir($workspace . '/sib'));
        self::assertNotFalse(file_put_contents($workspace . '/a/one.php', "<?php\necho 1;\n"));
        self::assertNotFalse(file_put_contents($workspace . '/b/two.php', "<?php\necho 2;\n"));

        try {
            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       '../a',
                                       '../b',
                                       '--no-config',
                                       '--format',
                                       'json',
                                       '--no-cache',
                                   ], $workspace . '/sib');
            $process->run();

            self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            $report      = $this->decodeJsonOutput($process);
            $diagnostics = $report['diagnostics'] ?? null;
            self::assertIsArray($diagnostics);
            self::assertCount(1, $diagnostics);
            $diagnostic = $this->decodedJsonObject($diagnostics[0] ?? null);
            self::assertSame('target-error', $diagnostic['type'] ?? null);
            self::assertTrue($diagnostic['invalidatesRun'] ?? null);
            self::assertSame([], $report['findings'] ?? null);

            $single = new Process([
                                      PHP_BINARY,
                                      self::PROJECT_ROOT . '/bin/gruff-php',
                                      'analyse',
                                      '../a',
                                      '--no-config',
                                      '--format',
                                      'json',
                                      '--fail-on',
                                      'none',
                                      '--no-cache',
                                  ], $workspace . '/sib');
            $single->run();
            self::assertSame(0, $single->getExitCode(), $single->getErrorOutput());
        } finally {
            $this->removeDir($workspace);
        }
    }

    /**
     * Checks that unrelated POSIX target trees whose only common ancestor is the filesystem root return no project root.
     *
     * @return void - Asserts null when two unrelated top-level trees share only `/`.
     */
    public function testProjectRootFromTargetsReturnsNullForUnrelatedRootLevelTrees(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_dir('/usr') || !is_dir('/var')) {
            self::markTestSkipped('POSIX /usr and /var directories are required.');
        }

        $launchDirectory = $this->tempDir();

        try {
            self::assertNull(AnalyseCommandSetupBuilder::projectRootFromTargets($launchDirectory, ['/usr', '/var']));
        } finally {
            $this->removeDir($launchDirectory);
        }
    }

    /**
     * Loads the expected CLI output users should see and normalises its version stamps before comparison.
     * This keeps renderer checks focused on the UI contract when the application version changes.
     *
     * @param string $fileName - Basename under tests/Fixtures/Cli/Golden whose contents are the expected output.
     *
     * @return string - Fixture text with the header and JSON version stamps replaced by the live version.
     */
    private function goldenOutput(string $fileName): string
    {
        $contents = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/Cli/Golden/' . $fileName);
        self::assertIsString($contents);

        // Replace the version in the "gruff-php X.Y.Z" header and in the JSON "version" field with the live version.
        $normalised = preg_replace(
            ['/^gruff-php \d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)? /m', '/"version": "\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?"/'],
            ['gruff-php ' . Application::VERSION . ' ', '"version": "' . Application::VERSION . '"'],
            $contents,
        );
        self::assertIsString($normalised);

        return $normalised;
    }

}
