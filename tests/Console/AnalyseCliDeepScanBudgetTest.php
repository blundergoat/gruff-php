<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Exercises the analyse command's deep-scan budget with real CLI processes.
 *
 * Cases check retained sensitive-data findings, invalid-budget diagnostics, and CLI precedence over config.
 * Every supported output format must include the bounded-scan diagnostic and its configured limits.
 */
final class AnalyseCliDeepScanBudgetTest extends CliTestCase
{
    /**
     * Verify bounded PHP remains analysed and retains sensitive-data scanning.
     *
     * @return void - Asserts one parsed file, no parse errors, a non-invalidating diagnostic, and an AWS access-key finding.
     */
    public function testBoundedDeepScanKeepsTextLevelSecurityFindings(): void
    {
        $project = $this->syntheticSecretsProject();
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
            'src/synthetic-secrets.php',
        ], $project);

        try {
            $process->run();
        } finally {
            $this->removeDir($project);
        }

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report      = $this->decodeJsonOutput($process);
        $summary     = $report['summary'] ?? null;
        $diagnostics = $report['diagnostics'] ?? null;
        $findings    = $report['findings'] ?? null;

        self::assertIsArray($summary);
        self::assertSame(1, $summary['discoveredFiles'] ?? null);
        self::assertSame(1, $summary['parsedFiles'] ?? null);
        self::assertSame(0, $summary['parseErrors'] ?? null);
        self::assertSame(0, $this->decodedJsonObject($report['score'] ?? null)['evaluatedFiles'] ?? null);
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
     * Verifies that a partial CLI budget produces usage exit code 2 and the expected diagnostic text.
     *
     * @return void - Asserts exit code 2 and the required budget syntax in the diagnostic.
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
     * @return void - Asserts the config-sourced diagnostic, then a successful CLI override with no diagnostics.
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
     * @return void - Asserts successful exits plus the bounded-scan marker and budget text in each format.
     */
    public function testBoundedDeepScanDiagnosticIsVisibleInEveryFormat(): void
    {
        // Run all supported report formats to catch a renderer that hides the skipped-structural-scan notice.
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
