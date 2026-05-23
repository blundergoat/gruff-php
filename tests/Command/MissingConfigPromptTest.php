<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Command\MissingConfigPrompt;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Console\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers MissingConfigPrompt: triggers init on accept, skips on decline, --no-config, --config, and non-interactive runs.
 */
final class MissingConfigPromptTest extends TestCase
{
    /**
     * Verify accepting the prompt runs init and writes a config file.
     *
     * @return void
     */
    public function testAcceptedPromptWritesConfigFile(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput    = $this->interactiveInput("y\n");
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $bufferedOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: null,
                shouldSkipConfig:   false,
            );

            self::assertNull($result);
            self::assertFileExists($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
            $promptOutput = $bufferedOutput->fetch() . "\n";
            self::assertStringContainsString(MissingConfigPrompt::PROMPT_TEXT, $promptOutput);
            self::assertStringContainsString('gruff-php analyse --generate-baseline', $promptOutput);
            self::assertStringContainsString('known debt', $promptOutput);
        });
    }

    /**
     * Verify declining the prompt leaves the project untouched.
     *
     * @return void
     */
    public function testDeclinedPromptDoesNotWriteConfigFile(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput    = $this->interactiveInput("n\n");
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $bufferedOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: null,
                shouldSkipConfig:   false,
            );

            self::assertNull($result);
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Verify non-interactive callers skip the prompt silently.
     *
     * @return void
     */
    public function testNonInteractiveInputSkipsPromptSilently(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput = new StringInput('');
            $stringInput->setInteractive(false);
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $bufferedOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: null,
                shouldSkipConfig:   false,
            );

            self::assertNull($result);
            self::assertSame('', $bufferedOutput->fetch());
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Verify --no-config callers skip the prompt silently.
     *
     * @return void
     */
    public function testNoConfigSkipsPromptSilently(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput    = $this->interactiveInput("y\n");
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $bufferedOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: null,
                shouldSkipConfig:   true,
            );

            self::assertNull($result);
            self::assertSame('', $bufferedOutput->fetch());
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Verify an explicit --config path skips the prompt silently.
     *
     * @return void
     */
    public function testExplicitConfigPathSkipsPromptSilently(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput    = $this->interactiveInput("y\n");
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $bufferedOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: $project . '/custom.yaml',
                shouldSkipConfig:   false,
            );

            self::assertNull($result);
            self::assertSame('', $bufferedOutput->fetch());
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Verify an existing project config skips the prompt silently.
     *
     * @return void
     */
    public function testExistingConfigSkipsPromptSilently(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $existingConfigPath = $project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE;
            file_put_contents($existingConfigPath, "# existing\n");

            $stringInput    = $this->interactiveInput("y\n");
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $bufferedOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: null,
                shouldSkipConfig:   false,
            );

            self::assertNull($result);
            self::assertSame('', $bufferedOutput->fetch());
            self::assertSame("# existing\n", file_get_contents($existingConfigPath));
        });
    }

    /**
     * Run the closure with a fresh temporary project as the working directory.
     *
     * @param callable(string): void $callable Closure that receives the project root.
     * @return void
     */
    private function withTemporaryProject(callable $callable): void
    {
        $project = $this->createTempDirectory();
        $origCwd = getcwd();
        self::assertIsString($origCwd);
        self::assertTrue(chdir($project));

        try {
            $callable($project);
        } finally {
            chdir($origCwd);
            $this->removeTempDirectory($project);
        }
    }

    /**
     * Build a StringInput marked interactive with the given stream contents.
     *
     * @param string $streamContents Input data the QuestionHelper should read.
     * @return StringInput Configured interactive input.
     */
    private function interactiveInput(string $streamContents): StringInput
    {
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to open memory stream.');
        }

        fwrite($stream, $streamContents);
        rewind($stream);

        $stringInput = new StringInput('');
        $stringInput->setStream($stream);
        $stringInput->setInteractive(true);

        return $stringInput;
    }

    /**
     * Create a unique temporary directory for an isolated project root.
     *
     * @return string Absolute path to the new directory.
     */
    private function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/gruff-prompt-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($path));

        return $path;
    }

    /**
     * Remove a temporary directory tree.
     *
     * @param string $path Absolute path to remove.
     * @return void
     */
    private function removeTempDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);

        foreach ($items as $directoryEntry) {
            if ($directoryEntry === '.' || $directoryEntry === '..') {
                continue;
            }
            $child = $path . '/' . $directoryEntry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTempDirectory($child);
                continue;
            }
            unlink($child);
        }

        rmdir($path);
    }
}
