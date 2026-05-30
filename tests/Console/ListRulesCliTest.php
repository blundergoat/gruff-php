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
        self::assertStringContainsString('1.0.0', $process->getOutput());
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
     * Verify list-rules with a rule id argument renders the per-rule detail view.
     *
     * @return void
     */
    public function testListRulesRendersPerRuleDetailViewForKnownId(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'naming.identifier-quality',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = $process->getOutput();
        self::assertStringContainsString('Rule: naming.identifier-quality', $output);
        self::assertStringContainsString('Default options:', $output);
        self::assertStringContainsString('placeholderNames', $output);
        self::assertStringContainsString('Escape hatches:', $output);
        self::assertStringContainsString('rules.naming.identifier-quality.excludeFromScore', $output);
        self::assertStringContainsString('Common false-positive shapes:', $output);
    }

    /**
     * Verify list-rules detail view JSON includes the structured payload.
     *
     * @return void
     */
    public function testListRulesDetailJsonIncludesStructuredFields(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'waste.one-line-method',
            '--format',
            'json',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame('waste.one-line-method', $payload['id'] ?? null);
        self::assertArrayHasKey('escapeHatches', $payload);
        self::assertArrayHasKey('falsePositiveShapes', $payload);
        self::assertIsArray($payload['escapeHatches']);
        self::assertNotEmpty($payload['escapeHatches']);
    }

    /**
     * Verify list-rules with an unknown rule id suggests near matches and exits INVALID.
     *
     * @return void
     */
    public function testListRulesUnknownRuleSuggestsNearMatches(): void
    {
        $process = new Process([
            PHP_BINARY,
            self::PROJECT_ROOT . '/bin/gruff-php',
            'list-rules',
            'naming.identifier-qualty',
        ]);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        $combined = $process->getOutput() . $process->getErrorOutput();
        self::assertStringContainsString('Unknown rule', $combined);
        self::assertStringContainsString('Did you mean', $combined);
        self::assertStringContainsString('naming.identifier-quality', $combined);
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

    /**
     * Verify Composer's vendor/bin proxy boots the package through the consumer project's autoloader.
     *
     * @return void
     * @throws \JsonException
     */
    public function testInstalledVendorBinProxyUsesConsumerAutoloader(): void
    {
        $composerPath = (new ExecutableFinder())->find('composer');

        self::assertIsString($composerPath);

        $tempDir  = $this->tempDir();
        $package  = $tempDir . '/package/gruff-php';
        $consumer = $tempDir . '/consumer';

        try {
            $this->copyPackageTree(self::PROJECT_ROOT, $package);
            self::assertTrue(mkdir($consumer));

            $composerJson = json_encode([
                'repositories' => [[
                    'type' => 'path',
                    'url' => $package,
                    'options' => [
                        'symlink' => false,
                        'versions' => [
                            'blundergoat/gruff-php' => '0.1.x-dev',
                        ],
                    ],
                ]],
                'require-dev' => [
                    'blundergoat/gruff-php' => '0.1.x-dev',
                ],
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents($consumer . '/composer.json', $composerJson . "\n");

            $installProcess = new Process([
                $composerPath,
                'update',
                '--no-audit',
                '--no-interaction',
                '--prefer-dist',
                '--no-progress',
            ], $consumer);
            $installProcess->setTimeout(120);
            $installProcess->run();

            self::assertSame(0, $installProcess->getExitCode(), $installProcess->getErrorOutput() . $installProcess->getOutput());
            self::assertFileDoesNotExist($consumer . '/vendor/blundergoat/gruff-php/vendor/autoload.php');

            $initProcess = new Process([
                PHP_BINARY,
                $consumer . '/vendor/bin/gruff-php',
                'init',
            ], $consumer);
            $initProcess->run();

            self::assertSame(0, $initProcess->getExitCode(), $initProcess->getErrorOutput() . $initProcess->getOutput());
            self::assertFileExists($consumer . '/.gruff-php.yaml');
            self::assertStringContainsString('Wrote ' . $consumer . '/.gruff-php.yaml', $initProcess->getOutput());
        } finally {
            $this->removeDir($tempDir);
        }
    }
}
