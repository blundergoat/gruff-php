<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class GruffCliTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    public function testVersionCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff', '--version']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff', $process->getOutput());
        self::assertStringContainsString('0.1.0-dev', $process->getOutput());
    }

    public function testListCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff', 'list']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('analyse', $process->getOutput());
        self::assertStringContainsString('dashboard', $process->getOutput());
        self::assertStringContainsString('report', $process->getOutput());
    }

    public function testCleanCheckoutInstallRunsCliHelp(): void
    {
        $composerPath = shell_exec('command -v composer');

        self::assertIsString($composerPath);
        self::assertNotSame('', trim($composerPath));

        $tempDir = $this->tempDir();
        $checkout = $tempDir . '/gruff-php';

        try {
            $this->copyPackageTree(self::PROJECT_ROOT, $checkout);

            $install = new Process([
                'composer',
                'install',
                '--no-dev',
                '--no-interaction',
                '--prefer-dist',
                '--no-progress',
            ], $checkout);
            $install->setTimeout(120);
            $install->run();

            self::assertSame(0, $install->getExitCode(), $install->getErrorOutput() . $install->getOutput());

            $help = new Process([PHP_BINARY, $checkout . '/bin/gruff', '--help'], $checkout);
            $help->run();

            self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
            self::assertStringContainsString('Description:', $help->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }

    public function testAnalyseCommandRunsAsNoOp(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff 0.1.0-dev', $process->getOutput());
        self::assertStringContainsString('Discovered: 2', $process->getOutput());
        self::assertStringContainsString('Ignored: 4', $process->getOutput());
        self::assertStringNotContainsString('ignored.php', $process->getOutput());
    }

    public function testAnalyseCommandReportsSyntaxErrorsWithoutAborting(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed/alpha.php',
            'tests/Fixtures/M02/syntax-error/broken.php',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[PARSE-ERROR] tests/Fixtures/M02/syntax-error/broken.php:4', $process->getOutput());
        self::assertStringContainsString('Parse errors: 1', $process->getOutput());
        self::assertStringContainsString('Exit code: 2', $process->getOutput());
    }

    public function testAnalyseCommandReportsWarningFindingsWithoutFailingByDefault(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed/alpha.php',
            '--config',
            'tests/Fixtures/M03/Config/file-length-warning.json',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $expected = file_get_contents(__DIR__ . '/../Fixtures/M04/Golden/text-warning.txt');

        self::assertIsString($expected);
        self::assertSame($expected, $process->getOutput());
    }

    public function testAnalyseCommandFailsWhenFindingMeetsDefaultErrorThreshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed/alpha.php',
            '--config',
            'tests/Fixtures/M04/Config/file-length-error.json',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('[error] size.file-length', $process->getOutput());
        self::assertStringContainsString('Exit code: 1', $process->getOutput());
    }

    public function testAnalyseCommandCanFailOnWarningThreshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed/alpha.php',
            '--config',
            'tests/Fixtures/M03/Config/file-length-warning.json',
            '--fail-on',
            'warning',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Fail threshold: warning', $process->getOutput());
        self::assertStringContainsString('Exit code: 1', $process->getOutput());
    }

    /**
     * @throws JsonException
     */
    public function testAnalyseCommandOutputsJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed/alpha.php',
            '--config',
            'tests/Fixtures/M03/Config/file-length-warning.json',
            '--format',
            'json',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $expected = file_get_contents(__DIR__ . '/../Fixtures/M04/Golden/json-warning.json');

        self::assertIsString($expected);
        self::assertSame($expected, $process->getOutput());

        $report = $this->decodeJsonOutput($process);
        $summary = $report['summary'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertSame('gruff.analysis.v1', $report['schemaVersion'] ?? null);
        self::assertIsArray($summary);
        self::assertSame(1, $summary['filesDiscovered'] ?? null);
        self::assertSame(0, $summary['exitCode'] ?? null);
        self::assertIsArray($findings);
        self::assertCount(3, $findings);
        $firstFinding = $findings[0] ?? null;

        self::assertIsArray($firstFinding);
        self::assertSame('size.file-length', $firstFinding['ruleId'] ?? null);
        self::assertSame('warning', $firstFinding['severity'] ?? null);
    }

    /**
     * @throws JsonException
     */
    public function testAnalyseCommandOutputsJsonParseErrors(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/syntax-error',
            '--format',
            'json',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());

        $report = $this->decodeJsonOutput($process);
        $summary = $report['summary'] ?? null;
        $diagnostics = $report['diagnostics'] ?? null;

        self::assertIsArray($summary);
        self::assertSame(1, $summary['parseErrors'] ?? null);
        self::assertSame(2, $summary['exitCode'] ?? null);
        self::assertIsArray($diagnostics);
        $firstDiagnostic = $diagnostics[0] ?? null;

        self::assertIsArray($firstDiagnostic);
        self::assertSame('parse-error', $firstDiagnostic['type'] ?? null);
    }

    public function testAnalyseCommandFailsInvalidConfig(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            '--config',
            'tests/Fixtures/M03/Config/unknown-rule.json',
            'tests/Fixtures/M02/mixed/alpha.php',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[CONFIG-ERROR] Unknown rule id "size.nope".', $process->getOutput());
    }

    /**
     * @throws JsonException
     */
    public function testAnalyseCommandAppliesConfiguredRuleSelection(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--config',
            'tests/Fixtures/M16/Config/only-size-rules.json',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        $summary = $report['summary'] ?? null;
        self::assertIsArray($summary);
        $findingCounts = $summary['findings'] ?? null;
        self::assertIsArray($findingCounts);
        self::assertSame(0, $findingCounts['total'] ?? null);
    }

    public function testAnalyseCommandAppliesConfiguredPathIgnores(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed',
            '--config',
            'tests/Fixtures/M16/Config/ignore-alpha.json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Discovered: 1', $process->getOutput());
        self::assertStringContainsString('tests/Fixtures/M02/mixed/alpha.php', $process->getOutput());
    }

    public function testAnalyseCommandReportsInvalidSelectionConfig(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            '--config',
            'tests/Fixtures/M16/Config/invalid-selection-rule.json',
            'tests/Fixtures/M14/Source',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[CONFIG-ERROR] Unknown rule id "size.nope" in "selection.rules".', $process->getOutput());
    }

    /**
     * @throws JsonException
     */
    public function testAnalyseCommandAppliesConfiguredSecretPreviewAllowlist(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M11/Secrets/synthetic-secrets.php',
            '--config',
            'tests/Fixtures/M16/Config/allow-aws-preview.json',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        $ruleIds = array_map(
            static fn (mixed $finding): mixed => is_array($finding) ? ($finding['ruleId'] ?? null) : null,
            $findings,
        );

        self::assertNotContains('secrets.aws-access-key', $ruleIds);
        self::assertContains('secrets.api-key-pattern', $ruleIds);
    }

    /**
     * @throws JsonException
     */
    public function testAnalyseCommandIngestsInfectionReportInJson(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--infection-report',
            'tests/Fixtures/M14/Infection/infection-valid.json',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
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

    public function testAnalyseCommandRendersMutationSummaryInText(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--infection-report',
            'tests/Fixtures/M14/Infection/infection-valid.json',
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
     * @throws JsonException
     */
    public function testAnalyseCommandReportsMutationBudgetAndMsiRegression(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--infection-report',
            'tests/Fixtures/M14/Infection/infection-valid.json',
            '--mutation-baseline',
            'tests/Fixtures/M14/Infection/infection-baseline.json',
            '--mutation-budget',
            '1',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        $mutation = $report['mutation'] ?? null;
        $findings = $report['findings'] ?? null;

        self::assertIsArray($mutation);
        $baseline = $mutation['baseline'] ?? null;
        $budget = $mutation['budget'] ?? null;
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

    public function testAnalyseCommandReportsMissingInfectionExecutableInRunMode(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--infection-run',
            '--infection-bin',
            'tests/Fixtures/M14/missing-infection',
            '--infection-report',
            'tests/Fixtures/M14/Infection/infection-clean.json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('[MUTATION-TOOL-ERROR]', $process->getOutput());
        self::assertStringContainsString('Infection executable not found', $process->getOutput());
    }

    /**
     * @throws JsonException
     */
    public function testAnalyseCommandOutputsScoringDataInJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--format',
            'json',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        $score = $report['score'] ?? null;
        $diff = $report['diff'] ?? null;

        self::assertIsArray($score);
        self::assertIsArray($diff);
        $composite = $score['composite'] ?? null;
        self::assertIsArray($composite);
        self::assertSame('A', $composite['grade'] ?? null);
        self::assertSame('full-project', $score['scope'] ?? null);
        self::assertSame(false, $diff['active'] ?? null);
    }

    public function testAnalyseCommandOutputsHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--format',
            'html',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<section class="verdict">', $process->getOutput());
        self::assertStringContainsString('pillar grades', $process->getOutput());
        self::assertStringContainsString('Mutation data unavailable', $process->getOutput());
        self::assertStringNotContainsString('fonts.googleapis.com', $process->getOutput());
    }

    public function testReportCommandOutputsStaticHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'report',
            'tests/Fixtures/M14/Source',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<section class="verdict">', $process->getOutput());
        self::assertStringContainsString('inspection report', $process->getOutput());
        self::assertStringNotContainsString('gruff-dashboard-toolbar', $process->getOutput());
    }

    /**
     * @throws JsonException
     */
    public function testReportCommandOutputsJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'report',
            'tests/Fixtures/M14/Source',
            '--format',
            'json',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        self::assertSame('gruff.analysis.v1', $report['schemaVersion'] ?? null);
    }

    public function testReportCommandWritesStaticHtmlReport(): void
    {
        $tempDir = $this->tempDir();
        $reportPath = $tempDir . '/gruff-report.html';

        try {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff',
                'report',
                'tests/Fixtures/M14/Source',
                '--output',
                $reportPath,
            ], self::PROJECT_ROOT);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('Report written to', $process->getOutput());
            self::assertFileExists($reportPath);

            $html = file_get_contents($reportPath);
            self::assertIsString($html);
            self::assertStringContainsString('<section class="verdict">', $html);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    public function testDashboardCommandServesRefreshableHtmlReport(): void
    {
        $port = $this->unusedPort();
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'dashboard',
            'tests/Fixtures/M14/Source',
            '--host',
            '127.0.0.1',
            '--port',
            (string) $port,
            '--scan-timeout',
            '30',
        ], self::PROJECT_ROOT);
        $process->setTimeout(null);
        $process->start();

        try {
            $this->waitForHttpServer($port, $process);

            $response = $this->fetchHttp($port, '/');

            self::assertStringContainsString('HTTP/1.1 200 OK', $response);
            self::assertStringContainsString('gruff dashboard', $response);
            self::assertStringContainsString('controls-toggle', $response);
            self::assertStringContainsString('Dashboard controls', $response);
            self::assertStringContainsString('Project root', $response);

            $scan = $this->fetchHttp($port, '/scan');

            self::assertStringContainsString('HTTP/1.1 200 OK', $scan);
            self::assertStringContainsString('gruff-dashboard-meta', $scan);
            self::assertStringNotContainsString('gruff-dashboard-toolbar', $scan);
            self::assertStringContainsString('<section class="verdict">', $scan);
        } finally {
            $process->stop(1);
        }
    }

    public function testDashboardCommandCanScanAnotherProjectFromBrowserQuery(): void
    {
        $tempDir = $this->tempDir();
        $port = $this->unusedPort();
        file_put_contents($tempDir . '/Example.php', "<?php\n\nfinal class Example\n{\n    public function run(): void {}\n}\n");

        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff',
            'dashboard',
            'tests/Fixtures/M14/Source',
            '--host',
            '127.0.0.1',
            '--port',
            (string) $port,
            '--scan-timeout',
            '30',
        ], self::PROJECT_ROOT);
        $process->setTimeout(null);
        $process->start();

        try {
            $this->waitForHttpServer($port, $process);

            $scan = $this->fetchHttp($port, '/scan?project=' . rawurlencode($tempDir) . '&paths=.');

            self::assertStringContainsString('HTTP/1.1 200 OK', $scan);
            self::assertStringContainsString($tempDir, $scan);
            self::assertStringContainsString('Example.php', $scan);
        } finally {
            $process->stop(1);
            $this->removeDir($tempDir);
        }
    }

    public function testAnalyseCommandOutputsGithubAnnotations(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M14/Source',
            '--format',
            'github',
            '--fail-on',
            'none',
        ], __DIR__ . '/../..');
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('::notice file=tests/Fixtures/M14/Source/OrderCalculator.php', $process->getOutput());
    }

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
     * @throws JsonException
     */
    public function testAnalyseCommandWritesTrendHistoryFile(): void
    {
        $tempDir = $this->tempDir();
        $historyPath = $tempDir . '/history/gruff-history.json';

        try {
            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/M14/Source',
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
     * @throws JsonException
     */
    public function testAnalyseCommandGeneratesAndAppliesBaseline(): void
    {
        $tempDir = $this->tempDir();
        $baselinePath = $tempDir . '/gruff-baseline.json';

        try {
            $generate = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/M14/Source',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
                $baselinePath,
            ], __DIR__ . '/../..');
            $generate->run();

            self::assertSame(0, $generate->getExitCode(), $generate->getErrorOutput());
            self::assertFileExists($baselinePath);

            $generatedReport = $this->decodeJsonOutput($generate);
            $generatedBaseline = $generatedReport['baseline'] ?? null;
            self::assertIsArray($generatedBaseline);
            self::assertSame(true, $generatedBaseline['generated'] ?? null);
            self::assertSame(1, $generatedBaseline['totalEntries'] ?? null);

            $apply = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/M14/Source',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--baseline',
                $baselinePath,
            ], __DIR__ . '/../..');
            $apply->run();

            self::assertSame(0, $apply->getExitCode(), $apply->getErrorOutput());
            $appliedReport = $this->decodeJsonOutput($apply);
            $appliedBaseline = $appliedReport['baseline'] ?? null;
            $summary = $appliedReport['summary'] ?? null;
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

    public function testAnalyseCommandRejectsInvalidBaselineJson(): void
    {
        $tempDir = $this->tempDir();
        $baselinePath = $tempDir . '/broken-baseline.json';

        try {
            file_put_contents($baselinePath, '{"schemaVersion":"wrong","findings":[]}');

            $process = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'analyse',
                'tests/Fixtures/M14/Source',
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
     * @throws JsonException
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

            $generatedReport = $this->decodeJsonOutput($generate);
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
            $autoReport = $this->decodeJsonOutput($autoApply);
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
     * @throws JsonException
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
     * @throws JsonException
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
            $report = $this->decodeJsonOutput($rerun);
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
     * @throws JsonException
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
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixtures\\M14\\Source;\n\n/**\n * Documents the public surface so the docs.missing-public-phpdoc finding goes away.\n */\nfinal readonly class OrderCalculator\n{\n    /**\n     * Sum the subtotal and tax to produce the order total.\n     */\n    public function calculateTotal(int \$subtotal, int \$tax): int\n    {\n        return \$subtotal + \$tax;\n    }\n}\n",
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
            $report = $this->decodeJsonOutput($rerun);
            $baseline = $report['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame('full-project', $baseline['staleEvaluation'] ?? null);
            self::assertSame(1, $baseline['staleEntries'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

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
     * @throws JsonException
     */
    public function testReportCommandForwardsBaselineFlag(): void
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

            $report = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff',
                'report',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--baseline=gruff-baseline.json',
            ], $project);
            $report->run();

            self::assertSame(0, $report->getExitCode(), $report->getErrorOutput());
            $decoded = $this->decodeJsonOutput($report);
            $baseline = $decoded['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame(1, $baseline['suppressedFindings'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }

    private function createBaselineProject(): string
    {
        $project = $this->tempDir();
        self::assertTrue(mkdir($project . '/src', 0777, true));

        $fixture = file_get_contents(self::PROJECT_ROOT . '/tests/Fixtures/M14/Source/OrderCalculator.php');
        self::assertIsString($fixture);
        file_put_contents($project . '/src/OrderCalculator.php', $fixture);
        // Provide a README so docs.missing-readme does not add an extra baseline entry.
        file_put_contents($project . '/README.md', "Baseline workflow fixture.\n");

        return $project;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function decodeJsonOutput(Process $process): array
    {
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        $report = [];

        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $report[$key] = $value;
        }

        return $report;
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/gruff-cli-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path));

        return $path;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }

    private function copyPackageTree(string $source, string $destination): void
    {
        self::assertTrue(mkdir($destination, 0777, true));

        $source = rtrim($source, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($source): bool {
                    $name = $file->getFilename();

                    if ($file->isDir() && in_array($name, ['.git', 'vendor', 'node_modules', '.idea'], true)) {
                        return false;
                    }

                    $relativePath = substr($file->getPathname(), strlen($source) + 1);
                    $relativePath = str_replace('\\', '/', $relativePath);

                    foreach (['.goat-flow/logs/', '.goat-flow/scratchpad/', '.goat-flow/tasks/'] as $ignoredPrefix) {
                        if (str_starts_with($relativePath, $ignoredPrefix)) {
                            return false;
                        }
                    }

                    return true;
                },
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $destination . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    self::assertTrue(mkdir($targetPath, 0777, true));
                }

                continue;
            }

            self::assertTrue(copy($item->getPathname(), $targetPath));
        }
    }

    private function unusedPort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($server === false) {
            throw new RuntimeException(sprintf('Unable to allocate test port: %s (%d)', $errorMessage, $errorCode));
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $matches)) {
            throw new RuntimeException('Unable to read allocated test port.');
        }

        return (int) $matches[1];
    }

    private function waitForHttpServer(int $port, Process $process): void
    {
        $deadline = microtime(true) + 5.0;

        do {
            if (!$process->isRunning()) {
                self::fail($process->getErrorOutput() . $process->getOutput());
            }

            try {
                $response = $this->fetchHttp($port, '/health');

                if (str_contains($response, "HTTP/1.1 200 OK\r\n")) {
                    return;
                }
            } catch (RuntimeException) {
                usleep(50_000);
            }
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for gruff dashboard server. ' . $process->getErrorOutput() . $process->getOutput());
    }

    private function fetchHttp(int $port, string $path): string
    {
        $socket = @stream_socket_client(sprintf('tcp://127.0.0.1:%d', $port), $errorCode, $errorMessage, 1.0);

        if ($socket === false) {
            throw new RuntimeException(sprintf('Unable to connect to report server: %s (%d)', $errorMessage, $errorCode));
        }

        stream_set_timeout($socket, 5);
        fwrite($socket, sprintf("GET %s HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nConnection: close\r\n\r\n", $path, $port));
        $response = stream_get_contents($socket);
        fclose($socket);

        if (!is_string($response)) {
            throw new RuntimeException('Unable to read HTTP response.');
        }

        return $response;
    }
}
