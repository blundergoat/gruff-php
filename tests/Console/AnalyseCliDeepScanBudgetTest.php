<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Covers the bounded deep-scan budget as a user meets it on the `analyse` command line.
 *
 * The budget degrades structural analysis on very large PHP sources while keeping size, sensitive-data, and
 * config rules running, so these cases check that a bounded file is still analysed, that its diagnostic reaches
 * every renderer, that a malformed override fails before analysis, and that the CLI wins over the config block.
 * Split from AnalyseCliTest so neither class exceeds the family public-method threshold.
 */
final class AnalyseCliDeepScanBudgetTest extends CliTestCase
{
    /**
     * Verify bounded PHP remains analysed and retains sensitive-data scanning.
     *
     * @return void
     */
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

    /**
     * Verify malformed CLI budgets fail before analysis.
     *
     * @return void
     */
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

    /**
     * Verify an atomic CLI budget wins over the config block.
     *
     * @return void
     */
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

    /**
     * Verify every analyse renderer exposes the bounded-scan note.
     *
     * @return void
     */
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
}
