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
 * Covers the missing-config init offer with accepting and declining input streams.
 * Checks silent skips for config options, existing config files, and non-interactive or machine-readable runs.
 * Verifies init targets the supplied project root and sends prompt output to the error stream when available.
 */
final class MissingConfigPromptTest extends TestCase
{
    /**
     * Verify accepting the prompt creates the default config and prints baseline guidance.
     *
     * @return void - Asserts a null result, config creation, prompt text, and baseline guidance.
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
     * Verify declining the prompt returns null without creating the default config.
     *
     * @return void - Asserts a null result and absence of the default config file.
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
     * @return void - Asserts a null result, no output, and no default config file.
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
     * Verify a false TTY probe skips the prompt when no explicit stream is set.
     * The interactive flag alone must not allow prompting when the fallback TTY check fails.
     *
     * @return void - Asserts a null result, no output, and no default config file.
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
     * Verify an explicit stream permits prompting when the fallback TTY probe would return false.
     * Explicit streams supply test and embedder answers, so the real-STDIN probe applies only when no stream was set.
     *
     * @return void - Asserts a null result, prompt text, and no default config after the decline.
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
     * Machine consumers parse the payload, so the init offer must not run even when an answerable input stream exists.
     *
     * @return void - Asserts a null result, no output, and no default config file.
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
     * @return void - Asserts a null result, no output, and no default config file.
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
     * @return void - Asserts a null result, no output, and no default config file.
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
     * @return void - Asserts a null result, no output, and unchanged existing config contents.
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
     * Verify a legacy .gruff.yaml is treated as existing project config.
     *
     * @return void - Asserts a null result, no output, preserved legacy contents, and no default config file.
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
     * A caller targeting another repository must not create config in the current working directory.
     *
     * @return void - Asserts a null result, config creation in the supplied root, and no config in the CWD.
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
     * Verify ConsoleOutputInterface callers route prompt and init text to their error stream.
     * This keeps the main output stream available for the calling command's payload.
     *
     * @return void - Asserts a null result, empty main output, and prompt plus config-write text in the error buffer.
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
     * Build a ConsoleOutputInterface fake that sends error-stream writes to the supplied buffer.
     * Its BufferedOutput parent captures main-stream writes so tests can verify that standard output remains empty.
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

                return $this->bufferedOutput;
            }

            /**
             * Ignore attempts to swap the error stream; the fake exposes a single fixed buffer.
             *
             * @param OutputInterface $output - Replacement stream the fake silently discards.
             *
             * @return void - Leaves the injected error buffer unchanged.
             */
            public function setErrorOutput(OutputInterface $output): void
            {
                unset($output);
            }

            /**
             * Reject section creation; the fake never participates in sectioned rendering.
             *
             * @throws LogicException - Always because sectioned output is unsupported by this fake.
             *
             * @return ConsoleSectionOutput - Never returns; every call throws LogicException.
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
     * @return void - Invokes CWD restoration and project cleanup in finally after the callback, including when it throws.
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
     * @return void - Skips non-directory paths; otherwise invokes recursive removal after asserting readable directory listings.
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
