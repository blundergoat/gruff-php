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
        // A run that scored nothing publishes nulls: 'N/A' with a 0 read as a failing grade to any
        // consumer comparing numbers, which is the confusion the ratified applicability contract removes.
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
        // FAMILY-CONTRACT section 1: one dash-line per finding, with the location between the
        // severity and the rule id, so the rule no longer follows the bracket directly.
        self::assertStringContainsString('[error] tests/Fixtures/Source/mixed/alpha.php:1 size.file-length', $process->getOutput());
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
            'Config key "allowlists.secretPreviews" is removed in 0.6.0: FAMILY-CONTRACT.md section 5 makes category markers unconditional, so the key authorises nothing; delete it from the configuration.',
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
