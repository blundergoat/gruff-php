<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Covers the version, list, and help CLI commands running end-to-end through the built binary on a clean checkout.
 */
final class ListRulesCliTest extends CliTestCase
{
    /**
     * Verify version command runs through binary.
     *
     * @return void
     */
    public function testVersionCommandRunsThroughBinary(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', '--version']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('gruff-php', $process->getOutput());
        self::assertStringContainsString('0.1.2', $process->getOutput());
    }

    /**
     * Verify list command runs through binary.
     *
     * @return void
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
     * Verify list-rules accepts the shared text format alias.
     *
     * @return void
     */
    public function testListRulesAcceptsTextFormatAlias(): void
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', 'list-rules', '--format', 'text']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Rule ID | Pillar | Tier | Severity | Confidence | Enabled | Description', $process->getOutput());
    }

    /**
     * Verify clean checkout install runs CLI help.
     *
     * @return void
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
