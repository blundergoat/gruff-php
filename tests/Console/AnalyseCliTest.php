<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

final class AnalyseCliTest extends CliTestCase
{
    /**
     * Verify analyse command runs as no op.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRunsAsNoOp(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff 0.1.0-dev', $process->getOutput());
        self::assertStringContainsString('Discovered: 2', $process->getOutput());
        self::assertStringContainsString('Ignored: 4', $process->getOutput());
        self::assertStringNotContainsString('ignored.php', $process->getOutput());
    }

    /**
     * Verify analyse command reports syntax errors without aborting.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandReportsSyntaxErrorsWithoutAborting(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
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
     * @return void No return value.
     */
    public function testAnalyseCommandReportsWarningFindingsWithoutFailingByDefault(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/file-length-warning.yaml',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $expected = file_get_contents(__DIR__ . '/../Fixtures/Cli/Golden/text-warning.txt');

        self::assertIsString($expected);
        self::assertSame($expected, $process->getOutput());
    }

    /**
     * Verify analyse command fails when finding meets default error threshold.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandFailsWhenFindingMeetsDefaultErrorThreshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * @return void No return value.
     */
    public function testAnalyseCommandCanFailOnWarningThreshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandOutputsJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/file-length-warning.yaml',
            '--format',
            'json',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $expected = file_get_contents(__DIR__ . '/../Fixtures/Cli/Golden/json-warning.json');

        self::assertIsString($expected);
        self::assertSame($expected, $process->getOutput());

        $report   = $this->decodeJsonOutput($process);
        $summary  = $report['summary'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertSame('gruff.analysis.v1', $report['schemaVersion'] ?? null);
        self::assertIsArray($summary);
        self::assertSame(1, $summary['filesDiscovered'] ?? null);
        self::assertSame(0, $summary['exitCode'] ?? null);
        self::assertIsArray($findings);
        self::assertCount(2, $findings);

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
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandOutputsJsonParseErrors(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * Verify analyse command fails invalid config.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandFailsInvalidConfig(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            '--config',
            'tests/Fixtures/Config/unknown-rule.yaml',
            'tests/Fixtures/Source/mixed/alpha.php',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[CONFIG-ERROR] Unknown rule id "size.nope".', $process->getOutput());
    }

    /**
     * Verify analyse command applies configured rule selection.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandAppliesConfiguredRuleSelection(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * Verify analyse command applies configured path ignores.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandAppliesConfiguredPathIgnores(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/mixed',
            '--config',
            'tests/Fixtures/Config/ignore-alpha.yaml',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Discovered: 1', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/Source/mixed/alpha.php', $process->getOutput());
    }

    /**
     * Verify analyse command reports invalid selection config.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandReportsInvalidSelectionConfig(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * Verify analyse command applies configured secret preview allowlist.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandAppliesConfiguredSecretPreviewAllowlist(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        $ruleIds = array_map(
            static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertNotContains('sensitive-data.aws-access-key', $ruleIds);
        self::assertContains('sensitive-data.api-key-pattern', $ruleIds);
    }

    /**
     * Verify analyse command ingests infection report in JSON.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandIngestsInfectionReportInJson(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $mutation = $report['mutation'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertIsArray($mutation);
        $totals = $mutation['totals'] ?? null;
        self::assertIsArray($totals);
        self::assertEquals(50.0, $totals['msi'] ?? null);
        self::assertSame(2, $totals['survivedMutants'] ?? null);
        self::assertIsArray($findings);
        self::assertContains(
            'mutation.survived-mutant',
            array_map(
                static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
                $findings,
            ),
        );

        $mutationFindings = array_values(array_filter(
            $findings,
            static fn (mixed $finding): bool => is_array($finding)
                && ($finding['ruleId'] ?? null) === 'mutation.survived-mutant',
        ));
        $firstMutationFinding = $mutationFindings[0] ?? null;

        self::assertIsArray($firstMutationFinding);
        $metadata = $firstMutationFinding['metadata'] ?? null;
        self::assertIsArray($metadata);
        self::assertEquals(50.0, $metadata['msi'] ?? null);
        self::assertEquals(50.0, $metadata['coveredMsi'] ?? null);
    }

    /**
     * Verify analyse command renders mutation summary in text.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRendersMutationSummaryInText(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Mutation', $process->getOutput());
        self::assertStringContainsString('MSI: 50.00%', $process->getOutput());
        self::assertStringContainsString('mutation.survived-mutant', $process->getOutput());
    }

    /**
     * Verify analyse command reports mutation budget and msi regression.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandReportsMutationBudgetAndMsiRegression(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--infection-report',
            'tests/Fixtures/Mutation/Infection/infection-valid.json',
            '--mutation-baseline',
            'tests/Fixtures/Mutation/Infection/infection-baseline.json',
            '--mutation-budget',
            '1',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $mutation = $report['mutation'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertIsArray($mutation);
        $baseline = $mutation['baseline'] ?? null;
        $budget   = $mutation['budget'] ?? null;
        self::assertIsArray($baseline);
        self::assertIsArray($budget);
        self::assertEquals(-30.0, $baseline['delta'] ?? null);
        self::assertSame(true, $budget['exceeded'] ?? null);
        self::assertIsArray($findings);

        $ruleIds = array_map(
            static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertContains('mutation.budget-exceeded', $ruleIds);
        self::assertContains('mutation.msi-regression', $ruleIds);
    }

    /**
     * Verify analyse command reports missing infection executable in run mode.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandReportsMissingInfectionExecutableInRunMode(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandOutputsScoringDataInJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        $score  = $report['score'] ?? null;
        $diff   = $report['diff'] ?? null;

        self::assertIsArray($score);
        self::assertIsArray($diff);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertSame('A', $composite['grade'] ?? null);
        self::assertSame('full-project', $score['scope'] ?? null);
        self::assertSame(false, $diff['active'] ?? null);
    }

    /**
     * Verify analyse command outputs HTML report.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandOutputsHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<section class="verdict">', $process->getOutput());
        self::assertStringContainsString('pillar grades', $process->getOutput());
        self::assertStringNotContainsString('mutation', $process->getOutput());
        self::assertStringNotContainsString('fonts.googleapis.com', $process->getOutput());
    }

    /**
     * Verify analyse command supports HTML editor links.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandSupportsHtmlEditorLinks(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * @return void No return value.
     */
    public function testAnalyseCommandDefaultsHtmlLocationsToCopyableSpans(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * @return void No return value.
     */
    public function testAnalyseCommandSupportsInteractiveHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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

        $static = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-interactive=false',
            '--no-config',
        ], __DIR__ . '/../..');
        $static->run();

        self::assertSame(0, $static->getExitCode(), $static->getErrorOutput());
        self::assertStringNotContainsString('class="finding-filters"', $static->getOutput());
        self::assertStringNotContainsString('<script type="module">', $static->getOutput());
    }

    /**
     * Verify analyse command reports invalid HTML report options.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandReportsInvalidHtmlReportOptions(): void
    {
        $editor = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-editor-link=bad',
            '--no-config',
        ], __DIR__ . '/../..');
        $editor->run();

        self::assertSame(2, $editor->getExitCode());
        self::assertStringContainsString('<section class="diagnostics">', $editor->getOutput());
        self::assertStringContainsString('--report-editor-link must be one of: vscode, phpstorm, none.', $editor->getOutput());

        $interactive = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/Source/Code',
            '--format',
            'html',
            '--fail-on',
            'none',
            '--report-interactive=maybe',
            '--no-config',
        ], __DIR__ . '/../..');
        $interactive->run();

        self::assertSame(2, $interactive->getExitCode());
        self::assertStringContainsString('<section class="diagnostics">', $interactive->getOutput());
        self::assertStringContainsString('--report-interactive must be true or false.', $interactive->getOutput());
    }

    /**
     * Verify analyse command outputs github annotations.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandOutputsGithubAnnotations(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
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
     * Verify analyse command reports non Git diff mode clearly.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandReportsNonGitDiffModeClearly(): void
    {
        $tempDir = $this->tempDir();

        try {
            file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function run(): void {}\n}\n");

            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                '.',
                '--diff',
                'unstaged',
                '--fail-on',
                'none',
            ], $tempDir);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('[DIFF-MODE-ERROR]', $process->getOutput());
            self::assertStringContainsString('Diff mode requires a git working tree.', $process->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command writes trend history file.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandWritesTrendHistoryFile(): void
    {
        $tempDir     = $this->tempDir();
        $historyPath = $tempDir . '/history/gruff-history.json';

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--history-file',
                $historyPath,
            ], __DIR__ . '/../..');
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertFileExists($historyPath);

            $report = $this->decodeJsonOutput($process);
            self::assertIsArray($report['trend'] ?? null);

            $decodedHistory = json_decode((string) file_get_contents($historyPath), true, 512, JSON_THROW_ON_ERROR);

            self::assertIsArray($decodedHistory);
            self::assertCount(1, $decodedHistory);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command generates and applies baseline.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandGeneratesAndAppliesBaseline(): void
    {
        $tempDir      = $this->tempDir();
        $baselinePath = $tempDir . '/gruff-baseline.json';

        try {
            $generate = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
                $baselinePath,
                '--no-config',
            ], __DIR__ . '/../..');
            $generate->run();

            self::assertSame(0, $generate->getExitCode(), $generate->getErrorOutput());
            self::assertFileExists($baselinePath);

            $generatedReport   = $this->decodeJsonOutput($generate);
            $generatedBaseline = $generatedReport['baseline'] ?? null;
            self::assertIsArray($generatedBaseline);
            self::assertSame(true, $generatedBaseline['generated'] ?? null);
            self::assertSame(1, $generatedBaseline['totalEntries'] ?? null);

            $apply = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
                '--no-config',
            ], __DIR__ . '/../..');
            $apply->run();

            self::assertSame(0, $apply->getExitCode(), $apply->getErrorOutput());
            $appliedReport   = $this->decodeJsonOutput($apply);
            $appliedBaseline = $appliedReport['baseline'] ?? null;
            $summary         = $appliedReport['summary'] ?? null;
            self::assertIsArray($appliedBaseline);
            self::assertIsArray($summary);
            self::assertSame(1, $appliedBaseline['suppressedFindings'] ?? null);
            $findingCounts = $summary['findings'] ?? null;
            self::assertIsArray($findingCounts);
            self::assertSame(0, $findingCounts['total'] ?? null);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command rejects invalid baseline JSON.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRejectsInvalidBaselineJson(): void
    {
        $tempDir      = $this->tempDir();
        $baselinePath = $tempDir . '/broken-baseline.json';

        try {
            file_put_contents($baselinePath, '{"schemaVersion":"wrong","findings":[]}');

            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/Source/Code',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
            ], __DIR__ . '/../..');
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('[BASELINE-ERROR]', $process->getOutput());
            self::assertStringContainsString('Baseline schemaVersion must be "gruff.baseline.v1".', $process->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * Verify analyse command writes and auto applies default baseline file.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandWritesAndAutoAppliesDefaultBaselineFile(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generate = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generate->run();

            self::assertSame(0, $generate->getExitCode(), $generate->getErrorOutput());
            self::assertFileExists($project . '/gruff-baseline.json');

            $generatedReport   = $this->decodeJsonOutput($generate);
            $generatedBaseline = $generatedReport['baseline'] ?? null;
            self::assertIsArray($generatedBaseline);
            self::assertSame('gruff-baseline.json', $generatedBaseline['path'] ?? null);
            self::assertSame(true, $generatedBaseline['generated'] ?? null);
            self::assertSame(1, $generatedBaseline['totalEntries'] ?? null);
            self::assertSame('default', $generatedBaseline['source'] ?? null);

            $autoApply = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $autoApply->run();

            self::assertSame(0, $autoApply->getExitCode(), $autoApply->getErrorOutput());
            $autoReport   = $this->decodeJsonOutput($autoApply);
            $autoBaseline = $autoReport['baseline'] ?? null;
            self::assertIsArray($autoBaseline);
            self::assertSame('gruff-baseline.json', $autoBaseline['path'] ?? null);
            self::assertSame(false, $autoBaseline['generated'] ?? null);
            self::assertSame(1, $autoBaseline['suppressedFindings'] ?? null);
            self::assertSame('default', $autoBaseline['source'] ?? null);
            $autoSummary = $autoReport['summary'] ?? null;
            self::assertIsArray($autoSummary);
            $autoCounts = $autoSummary['findings'] ?? null;
            self::assertIsArray($autoCounts);
            self::assertSame(0, $autoCounts['total'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command skips auto baseline with no baseline flag.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandSkipsAutoBaselineWithNoBaselineFlag(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generate = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generate->run();
            self::assertSame(0, $generate->getExitCode(), $generate->getErrorOutput());

            $skipped = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--no-baseline',
            ], $project);
            $skipped->run();

            self::assertSame(0, $skipped->getExitCode(), $skipped->getErrorOutput());
            $report = $this->decodeJsonOutput($skipped);
            self::assertArrayNotHasKey('baseline', $report);
            $skippedSummary = $report['summary'] ?? null;
            self::assertIsArray($skippedSummary);
            $skippedCounts = $skippedSummary['findings'] ?? null;
            self::assertIsArray($skippedCounts);
            self::assertGreaterThan(0, $skippedCounts['total'] ?? 0);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command shows new findings after baseline generation.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandShowsNewFindingsAfterBaselineGeneration(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generate = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generate->run();
            self::assertSame(0, $generate->getExitCode(), $generate->getErrorOutput());

            file_put_contents(
                $project . '/src/Newcomer.php',
                "<?php\n\ndeclare(strict_types=1);\n\nfinal readonly class Newcomer\n{\n    public function arrive(int \$x): int\n    {\n        return \$x;\n    }\n}\n",
            );

            $rerun = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $rerun->run();

            self::assertSame(0, $rerun->getExitCode(), $rerun->getErrorOutput());
            $report   = $this->decodeJsonOutput($rerun);
            $baseline = $report['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame(1, $baseline['suppressedFindings'] ?? null);

            $findings = $report['findings'] ?? null;
            self::assertIsArray($findings);
            $newFiles = array_values(array_filter(
                $findings,
                static fn (mixed $finding): bool => is_array($finding)
                    && ($finding['file'] ?? null) === 'src/Newcomer.php',
            ));
            self::assertNotSame([], $newFiles, 'A new finding introduced after baseline generation must still be reported.');
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command reports stale baseline entries.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testAnalyseCommandReportsStaleBaselineEntries(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generate = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generate->run();
            self::assertSame(0, $generate->getExitCode(), $generate->getErrorOutput());

            file_put_contents(
                $project . '/src/OrderCalculator.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixtures\\Source\\Code;\n\n/**\n * Documents the public surface so the docs.missing-public-phpdoc finding goes away.\n */\nfinal readonly class OrderCalculator\n{\n    /**\n     * Sum the subtotal and tax to produce the order total.\n     */\n    public function calculateTotal(int \$subtotal, int \$tax): int\n    {\n        return \$subtotal + \$tax;\n    }\n}\n",
            );

            $rerun = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
            ], $project);
            $rerun->run();

            self::assertSame(0, $rerun->getExitCode(), $rerun->getErrorOutput());
            $report   = $this->decodeJsonOutput($rerun);
            $baseline = $report['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame('full-project', $baseline['staleEvaluation'] ?? null);
            self::assertSame(1, $baseline['staleEntries'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command rejects no baseline combined with baseline.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRejectsNoBaselineCombinedWithBaseline(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'text',
                '--fail-on',
                'none',
                '--no-baseline',
                '--baseline=gruff-baseline.json',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('--no-baseline cannot be combined with --baseline.', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse command rejects baseline combined with generate baseline.
     *
     * @return void No return value.
     */
    public function testAnalyseCommandRejectsBaselineCombinedWithGenerateBaseline(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'src',
                '--format',
                'text',
                '--fail-on',
                'none',
                '--baseline=gruff-baseline.json',
                '--generate-baseline',
            ], $project);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertStringContainsString('--baseline and --generate-baseline are mutually exclusive.', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }
}
