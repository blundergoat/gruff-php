<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Covers ListRulesCliTest behavior.
 */
final class ListRulesCliTest extends CliTestCase
{
    /**
     * Verify version command runs through binary.
     *
     * @return void No return value.
     */
    public function testVersionCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', '--version']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff-php', $process->getOutput());
        self::assertStringContainsString('0.1.0-dev', $process->getOutput());
    }

    /**
     * Verify list command runs through binary.
     *
     * @return void No return value.
     */
    public function testListCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('analyse', $process->getOutput());
        self::assertStringContainsString('dashboard', $process->getOutput());
        self::assertStringContainsString('report', $process->getOutput());
    }

    /**
     * Verify clean checkout install runs CLI help.
     *
     * @return void No return value.
     */
    public function testCleanCheckoutInstallRunsCliHelp(): void
    {
        $composerPath = (new ExecutableFinder())->find('composer');

        self::assertIsString($composerPath);

        $tempDir  = $this->tempDir();
        $checkout = $tempDir . '/gruff-php';

        try {
            $this->copyPackageTree(self::PROJECT_ROOT, $checkout);

            $installProcess = new Process([
                $composerPath,
                'install',
                '--no-dev',
                '--no-interaction',
                '--prefer-dist',
                '--no-progress',
            ], $checkout);
            $installProcess->setTimeout(120);
            $installProcess->run();

            self::assertSame(0, $installProcess->getExitCode(), $installProcess->getErrorOutput() . $installProcess->getOutput());

            $helpProcess = new Process([PHP_BINARY, $checkout . '/bin/gruff-php', '--help'], $checkout);
            $helpProcess->run();

            self::assertSame(0, $helpProcess->getExitCode(), $helpProcess->getErrorOutput());
            self::assertStringContainsString('Description:', $helpProcess->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }
}
