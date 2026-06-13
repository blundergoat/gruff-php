<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Command;

use GruffPhp\Cli\Command\MissingConfigPrompt;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Cli\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers CLI rule-filter validation order: an unknown --include-rule/--exclude-rule id must fail as a usage error before the missing-config init
 * prompt may run (and write `.gruff-php.yaml`) for a run that was already doomed by invalid input.
 */
final class RuleFilterPromptOrderTest extends TestCase
{
    /**
     * Rule id registered in no registry; shaped like a typo of a real id.
     */
    private const UNKNOWN_RULE_ID = 'docs.missing-public-phpdox';

    /**
     * Verify analyse rejects an unknown rule filter before offering the init prompt.
     *
     * @return void
     */
    public function testAnalyseRejectsUnknownRuleFilterBeforeInitPrompt(): void
    {
        $this->assertRejectsUnknownRuleFilterBeforeInitPrompt('analyse');
    }

    /**
     * Verify report rejects an unknown rule filter before offering the init prompt.
     *
     * @return void
     */
    public function testReportRejectsUnknownRuleFilterBeforeInitPrompt(): void
    {
        $this->assertRejectsUnknownRuleFilterBeforeInitPrompt('report');
    }

    /**
     * Run the command with an unknown --include-rule id and a consenting interactive stream in a config-less project.
     *
     * Locks the ordering regression where the init offer ran before rule-filter
     * validation: answering "y" wrote `.gruff-php.yaml`, then the run exited
     * with the unknown-rule usage error anyway - a side effect after invalid
     * CLI input.
     *
     * @param string $commandName - Console command under test (analyse or report).
     *
     * @return void
     */
    private function assertRejectsUnknownRuleFilterBeforeInitPrompt(string $commandName): void
    {
        $this->withTemporaryProject(function (string $project) use ($commandName): void {
            $command        = (new Application())->find($commandName);
            $consentingInput = $this->interactiveInput(sprintf('--include-rule %s', self::UNKNOWN_RULE_ID));
            $bufferedOutput = new BufferedOutput();

            $exitCode = $command->run($consentingInput, $bufferedOutput);
            $rendered = $bufferedOutput->fetch();

            self::assertSame(Command::INVALID, $exitCode, $rendered);
            self::assertStringContainsString(
                sprintf('Unknown rule id "%s" for --include-rule.', self::UNKNOWN_RULE_ID),
                $rendered,
            );
            self::assertStringNotContainsString(MissingConfigPrompt::PROMPT_TEXT, $rendered);
            self::assertFileDoesNotExist($project . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
        });
    }

    /**
     * Build an interactive StringInput whose stream would answer "y" to any prompt.
     *
     * @param string $commandLine - Option string for the command under test.
     *
     * @return StringInput - Interactive input with a consenting answer stream.
     */
    private function interactiveInput(string $commandLine): StringInput
    {
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to open memory stream.');
        }

        fwrite($stream, "y\n");
        rewind($stream);

        $stringInput = new StringInput($commandLine);
        $stringInput->setStream($stream);
        $stringInput->setInteractive(true);

        return $stringInput;
    }

    /**
     * Run the closure with a fresh temporary config-less project as the working directory.
     *
     * @param callable(string): void $callable - Closure that receives the project root.
     *
     * @return void
     */
    private function withTemporaryProject(callable $callable): void
    {
        $project = sys_get_temp_dir() . '/gruff-rule-filter-order-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($project));
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

            $entryPath = $path . '/' . $directoryEntry;
            if (is_dir($entryPath)) {
                $this->removeTempDirectory($entryPath);
                continue;
            }

            self::assertTrue(unlink($entryPath));
        }

        self::assertTrue(rmdir($path));
    }
}
