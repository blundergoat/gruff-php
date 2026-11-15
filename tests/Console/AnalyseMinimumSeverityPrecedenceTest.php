<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the ADR-015 failure-threshold precedence cases for analyse.
 * The config and explicit CLI cases check warning output and exit codes.
 * The no-config case checks the exit code when --fail-on is omitted.
 */
final class AnalyseMinimumSeverityPrecedenceTest extends TestCase
{
    /**
     * Absolute path to the gruff-php repository root.
     */
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * Checks that a configured error threshold allows reported warnings when --fail-on is omitted.
     *
     * @return void - Asserts exit 0 and the size.file-length warning for the fixture.
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
     * Checks that --fail-on warning overrides the fixture's configured error threshold.
     *
     * @return void - Asserts exit 1 and the size.file-length warning with the CLI override.
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
     * Checks the no-config invocation without an explicit failure threshold.
     *
     * @return void - Asserts exit 1 for the mixed-source fixture.
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
