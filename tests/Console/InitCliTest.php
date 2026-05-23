<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Config\ConfigLoader;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers the init CLI command: writes registry-default config, refuses to overwrite, and respects --force.
 */
final class InitCliTest extends CliTestCase
{
    /**
     * Verify init writes a default config file at the project root.
     *
     * @return void
     */
    public function testInitWritesDefaultConfigFile(): void
    {
        $project = $this->tempDir();

        try {
            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'init',
            ], $project);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            $configPath = $project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE;
            self::assertFileExists($configPath);
            self::assertStringContainsString('Wrote ' . $configPath, $process->getOutput());
            self::assertStringContainsString('Next steps:', $process->getOutput());
            self::assertStringContainsString('gruff-php analyse --generate-baseline', $process->getOutput());
            self::assertStringContainsString('known debt', $process->getOutput());
            self::assertStringContainsString('gruff-php analyse --no-baseline', $process->getOutput());

            $contents = file_get_contents($configPath);
            self::assertIsString($contents);
            self::assertStringStartsWith('# .gruff-php.yaml', $contents);
            self::assertStringContainsString('paths.ignore', $contents);
            self::assertStringContainsString('gruff-php analyse --generate-baseline', $contents);

            $decoded = Yaml::parse($contents);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('minimumPhpVersion', $decoded);
            self::assertArrayHasKey('paths', $decoded);
            self::assertArrayHasKey('allowlists', $decoded);
            self::assertArrayHasKey('selection', $decoded);
            self::assertArrayHasKey('rules', $decoded);

            self::assertIsArray($decoded['rules']);
            self::assertArrayHasKey('complexity.cognitive', $decoded['rules']);
            self::assertSame(
                ['enabled' => true, 'threshold' => 30, 'severity' => 'error'],
                $decoded['rules']['complexity.cognitive'],
            );
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify init refuses to overwrite an existing config file.
     *
     * @return void
     */
    public function testInitRefusesToOverwriteExistingFile(): void
    {
        $project    = $this->tempDir();
        $configPath = $project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE;

        try {
            file_put_contents($configPath, "# existing\n");

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'init',
            ], $project);
            $process->run();

            self::assertNotSame(0, $process->getExitCode());
            self::assertStringContainsString('already exists', $process->getErrorOutput() . $process->getOutput());
            self::assertSame("# existing\n", file_get_contents($configPath));
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify --force overwrites an existing config file.
     *
     * @return void
     */
    public function testInitForceOverwritesExistingFile(): void
    {
        $project    = $this->tempDir();
        $configPath = $project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE;

        try {
            file_put_contents($configPath, "# existing\n");

            $process = new Process([
                PHP_BINARY,
                self::PROJECT_ROOT . '/bin/gruff-php',
                'init',
                '--force',
            ], $project);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            $contents = file_get_contents($configPath);
            self::assertIsString($contents);
            self::assertStringStartsWith('# .gruff-php.yaml', $contents);
            self::assertStringNotContainsString('# existing', $contents);
            self::assertStringContainsString('known debt', $process->getOutput());
        } finally {
            $this->removeDir($project);
        }
    }
}
