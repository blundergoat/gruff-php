<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Verifies the ADR-015 precedence chain for the analyse command's --fail-on resolution.
 *
 * CLI flag > config.minimumSeverity.analyse > binary default (advisory).
 */
final class AnalyseMinimumSeverityPrecedenceTest extends TestCase
{
    /**
     * Absolute path to the gruff-php repository root.
     */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Verify config minimumSeverity.analyse > binary default when --fail-on is omitted.
     *
     * The fixture sets `minimumSeverity.analyse: error` and configures the
     * size.file-length rule to emit warning findings. Without --fail-on, the
     * config-supplied error threshold wins and warning findings do not trigger
     * exit 1.
     *
     * @return void
     */
    public function testConfigErrorThresholdSuppressesWarningExit(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/minimum-severity-analyse-error.yaml',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('[warning] tests/Fixtures/Source/mixed/alpha.php:1 size.file-length', $process->getOutput());
    }

    /**
     * Verify CLI --fail-on wins over the config-supplied threshold.
     *
     * Same fixture as above, but the user passes --fail-on warning. The CLI
     * flag overrides the config's error threshold and warning findings now
     * trigger exit 1.
     *
     * @return void
     */
    public function testCliFlagWarningOverridesConfigErrorThreshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--config',
            'tests/Fixtures/Config/minimum-severity-analyse-error.yaml',
            '--fail-on',
            'warning',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('[warning] tests/Fixtures/Source/mixed/alpha.php:1 size.file-length', $process->getOutput());
    }

    /**
     * Verify the binary default for analyse is advisory (per ADR-015).
     *
     * Running with --no-config and no --fail-on uses registry defaults and
     * the new binary advisory threshold. The mixed fixture has at least one
     * advisory-tier finding under registry defaults, so the run exits 1.
     *
     * @return void
     */
    public function testNoConfigBinaryDefaultIsAdvisoryAndFailsOnFindings(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'analyse',
            'tests/Fixtures/Source/mixed/alpha.php',
            '--no-config',
        ], self::PROJECT_ROOT);
        $process->run();

        self::assertSame(1, $process->getExitCode());
    }
}
