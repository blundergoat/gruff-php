<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Covers ReportCliTest behavior.
 */
final class ReportCliTest extends CliTestCase
{
    /**
     * Verify report command outputs static HTML report.
     *
     * @return void No return value.
     */
    public function testReportCommandOutputsStaticHtmlReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('<section class="verdict">', $process->getOutput());
        self::assertStringContainsString('inspection report', $process->getOutput());
        self::assertStringNotContainsString('gruff-dashboard-toolbar', $process->getOutput());
    }

    /**
     * Verify report command forwards HTML report flags.
     *
     * @return void No return value.
     */
    public function testReportCommandForwardsHtmlReportFlags(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--fail-on',
            'none',
            '--report-editor-link',
            'vscode',
            '--report-interactive',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('href="vscode://file/', $process->getOutput());
        self::assertStringContainsString('class="finding-filters"', $process->getOutput());

        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--fail-on',
            'none',
            '--report-interactive=false',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('class="finding-filters"', $process->getOutput());
    }

    /**
     * Verify report command outputs JSON report.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testReportCommandOutputsJsonReport(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report = $this->decodeJsonOutput($process);
        self::assertSame('gruff.analysis.v1', $report['schemaVersion'] ?? null);
    }

    /**
     * Verify report command forwards repeated rule filters.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testReportCommandForwardsRepeatedRuleFilters(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'report',
            'tests/Fixtures/Source/Code',
            '--format',
            'json',
            '--fail-on',
            'none',
            '--no-config',
            '--include-rule',
            'docs.missing-public-phpdoc',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $report   = $this->decodeJsonOutput($process);
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        self::assertCount(1, $findings);
        $firstFinding = $findings[0] ?? null;
        self::assertIsArray($firstFinding);
        self::assertSame('docs.missing-public-phpdoc', $firstFinding['ruleId'] ?? null);
    }

    /**
     * Verify report command writes static HTML report.
     *
     * @return void No return value.
     */
    public function testReportCommandWritesStaticHtmlReport(): void
    {
        $tempDir    = $this->tempDir();
        $reportPath = $tempDir . '/gruff-report.html';

        try {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'report',
                'tests/Fixtures/Source/Code',
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

    /**
     * Verify report command forwards baseline flag.
     *
     * @throws JsonException
     * @return void No return value.
     */
    public function testReportCommandForwardsBaselineFlag(): void
    {
        $project = $this->createBaselineProject();

        try {
            $generateProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'analyse',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--generate-baseline',
            ], $project);
            $generateProcess->run();
            self::assertSame(0, $generateProcess->getExitCode(), $generateProcess->getErrorOutput());

            $reportProcess = new Process([
                PHP_BINARY,
                __DIR__ . '/../../bin/gruff-php',
                'report',
                'src',
                '--format',
                'json',
                '--fail-on',
                'none',
                '--baseline=gruff-baseline.json',
            ], $project);
            $reportProcess->run();

            self::assertSame(0, $reportProcess->getExitCode(), $reportProcess->getErrorOutput());
            $decoded  = $this->decodeJsonOutput($reportProcess);
            $baseline = $decoded['baseline'] ?? null;
            self::assertIsArray($baseline);
            self::assertSame(1, $baseline['suppressedFindings'] ?? null);
        } finally {
            $this->removeDir($project);
        }
    }
}
