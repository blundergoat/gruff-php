<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Cli\Command\MissingConfigPrompt;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Cli\Application;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
     * Verify a non-TTY real stdin skips the prompt even when the interactive flag is on.
     *
     * Symfony leaves Input::$interactive true for piped callers that omit -n,
     * so when no explicit stream was set the guard must probe the real STDIN
     * instead of blocking forever in the question helper.
     *
     * @return void
     */
    public function testNonTtyRealStdinWithoutExplicitStreamSkipsPromptSilently(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput = new StringInput('');
            $stringInput->setInteractive(true);
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $bufferedOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: null,
                shouldSkipConfig:   false,
                stdinTtyProbe:      static fn (): bool => false,
            );

            self::assertNull($result);
            self::assertSame('', $bufferedOutput->fetch());
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Verify an explicitly set stream counts as interactive even when STDIN is not a TTY.
     *
     * Embedders and tests answer the prompt through setStream(), so the real
     * STDIN probe must only apply when no stream was set.
     *
     * @return void
     */
    public function testExplicitStreamStillPromptsWhenStdinProbeReportsNoTty(): void
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
                stdinTtyProbe:      static fn (): bool => false,
            );

            self::assertNull($result);
            self::assertStringContainsString(MissingConfigPrompt::PROMPT_TEXT, $bufferedOutput->fetch());
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Verify a machine-readable output format skips the prompt despite an interactive stream.
     *
     * Machine consumers parse the payload, so the init offer must never run
     * even when an answerable input stream exists.
     *
     * @return void
     */
    public function testMachineReadableFormatSkipsPromptDespiteInteractiveStream(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput    = $this->interactiveInput("y\n");
            $bufferedOutput = new BufferedOutput();

            $result = MissingConfigPrompt::maybeOffer(
                input:                   $stringInput,
                output:                  $bufferedOutput,
                symfonyApplication:      new Application(),
                projectRoot:             $project,
                explicitConfigPath:      null,
                shouldSkipConfig:        false,
                isMachineReadableFormat: true,
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
     * Verify a legacy `.gruff.yaml` is treated as existing project config.
     *
     * @return void
     */
    public function testExistingLegacyConfigSkipsPromptSilently(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $legacyConfigPath = $project . '/' . ConfigLoader::LEGACY_DEFAULT_CONFIG_FILE;
            file_put_contents($legacyConfigPath, "# legacy\n");

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
            self::assertSame("# legacy\n", file_get_contents($legacyConfigPath));
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Verify init writes to the supplied project root, not the process CWD.
     *
     * Locks the dashboard regression where `--project /other/repo` would
     * dispatch init against CWD and create config in the wrong directory.
     *
     * @return void
     */
    public function testPromptDispatchesInitInGivenProjectRoot(): void
    {
        $cwd     = $this->createTempDirectory();
        $project = $this->createTempDirectory();
        $origCwd = getcwd();
        self::assertIsString($origCwd);
        self::assertTrue(chdir($cwd));

        try {
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
            self::assertFileDoesNotExist($cwd . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        } finally {
            chdir($origCwd);
            $this->removeTempDirectory($cwd);
            $this->removeTempDirectory($project);
        }
    }

    /**
     * Verify ConsoleOutputInterface callers route prompt chatter to STDERR.
     *
     * Stops the prompt from corrupting machine-readable payloads written to STDOUT.
     *
     * @return void
     */
    public function testConsoleOutputInterfaceRoutesPromptToErrorStream(): void
    {
        $this->withTemporaryProject(function (string $project): void {
            $stringInput    = $this->interactiveInput("y\n");
            $bufferedOutput = new BufferedOutput();
            $consoleOutput  = $this->fakeConsoleOutput($bufferedOutput);

            $result = MissingConfigPrompt::maybeOffer(
                input:              $stringInput,
                output:             $consoleOutput,
                symfonyApplication: new Application(),
                projectRoot:        $project,
                explicitConfigPath: null,
                shouldSkipConfig:   false,
            );

            self::assertNull($result);
            self::assertSame('', $consoleOutput->fetch());
            $errorOutput = $bufferedOutput->fetch();
            self::assertStringContainsString(MissingConfigPrompt::PROMPT_TEXT, $errorOutput);
            self::assertStringContainsString('Wrote ' . $project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE, $errorOutput);
        });
    }

    /**
     * Build a minimal ConsoleOutputInterface that captures error chatter into the given buffer.
     *
     * The fake's own BufferedOutput parent captures any main-stream writes so the
     * caller can assert that the routing under test never leaks payloads onto stdout.
     *
     * @param BufferedOutput $bufferedOutput - Buffer that should receive the error-stream chatter.
     *
     * @return BufferedOutput&ConsoleOutputInterface - Test double exposing the supplied buffer as the error stream.
     */
    private function fakeConsoleOutput(BufferedOutput $bufferedOutput): BufferedOutput&ConsoleOutputInterface
    {
        return new class ($bufferedOutput) extends BufferedOutput implements ConsoleOutputInterface
        {
            /**
             * Capture the buffer returned to callers asking for the error stream.
             *
             * @param BufferedOutput $bufferedOutput - Buffer exposed as the error stream.
             */
            public function __construct(private readonly BufferedOutput $bufferedOutput)
            {
                parent::__construct();
            }

            /**
             * Return the BufferedOutput used by tests to capture STDERR-bound chatter.
             *
             * @return OutputInterface - Error stream backed by the captured buffer.
             */
            public function getErrorOutput(): OutputInterface
            {
                // Expose the injected buffer so assertions can read whatever was routed to STDERR.
                return $this->bufferedOutput;
            }

            /**
             * Ignore attempts to swap the error stream; the fake exposes a single fixed buffer.
             *
             * @param OutputInterface $output - Replacement stream the fake silently discards.
             *
             * @return void
             */
            public function setErrorOutput(OutputInterface $output): void
            {
                unset($output);
            }

            /**
             * Reject section creation; the fake never participates in sectioned rendering.
             *
             * @throws LogicException Always; the test scenario never exercises sectioned output.
             *
             * @return ConsoleSectionOutput - Never returns — the call always throws.
             */
            public function section(): ConsoleSectionOutput
            {
                $reason = 'section() not supported by test fake.';

                throw new LogicException($reason);
            }
        };
    }

    /**
     * Run the closure with a fresh temporary project as the working directory.
     *
     * @param callable(string): void $callable - Closure that receives the project root.
     *
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
     * @param string $streamContents - Input data the QuestionHelper should read.
     *
     * @return StringInput - Configured interactive input.
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
     * @return string - Absolute path to the new directory.
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
     * @param string $path - Absolute path to remove.
     *
     * @return void
     */
    private function removeTempDirectory(string $path): void
    {
        if (!is_dir($path)) {
            // Already gone (or never created), so there is nothing to recurse into.
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
