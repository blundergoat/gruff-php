<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GruffCliTest extends TestCase
{
    public function testVersionCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../bin/gruff', '--version']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff', $process->getOutput());
        self::assertStringContainsString('0.1.0-dev', $process->getOutput());
    }

    public function testAnalyseCommandRunsAsNoOp(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed',
        ], __DIR__ . '/../..');
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
            __DIR__ . '/../../bin/gruff',
            'analyse',
            'tests/Fixtures/M02/mixed/alpha.php',
            'tests/Fixtures/M02/syntax-error/broken.php',
        ], __DIR__ . '/../..');
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

    public function testAnalyseCommandOutputsHtmlDashboard(): void
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
}
