<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Cli\Command\MissingConfigPrompt;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Covers the missing-config prompt guard for analyse runs with piped stdin.
 * The tests check prompt absence on stderr and prevent config creation from piped input.
 * An open, silent pipe must let analyse finish within the timeout.
 */
final class AnalyseCliMissingConfigGuardTest extends CliTestCase
{
    /**
     * Upper bound, in seconds, a guarded config-less analyse may take before the run counts as hung.
     */
    private const HANG_GUARD_TIMEOUT_SECONDS = 30;

    /**
     * Verify a piped "y" leaves config absent and omits the init prompt from stderr.
     *
     * @return void - Asserts exit zero, no generated config, and a gruff.analysis.v3 JSON report.
     */
    public function testPipedYesLineDoesNotWriteConfigFile(): void
    {
        $project = $this->createBaselineProject();

        try {
            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       '--format',
                                       'json',
                                       '--fail-on',
                                       'none',
                                       '--no-baseline',
                                       'src/OrderCalculator.php',
                                   ], $project);
            $process->setInput("y\n");
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertFileDoesNotExist($project . '/.gruff-php.yaml');
            self::assertStringNotContainsString(MissingConfigPrompt::PROMPT_TEXT, $process->getErrorOutput());
            $report = $this->decodeJsonOutput($process);
            self::assertSame('gruff.analysis.v3', $report['schemaVersion']);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify analyse finishes within the timeout while an input pipe remains open and silent.
     *
     * @return void - Asserts exit zero, no generated config, and no init prompt on stderr.
     */
    public function testOpenSilentStdinPipeDoesNotBlockAnalyse(): void
    {
        $project     = $this->createBaselineProject();
        $inputStream = new InputStream();

        try {
            $process = new Process([
                                       PHP_BINARY,
                                       self::PROJECT_ROOT . '/bin/gruff-php',
                                       'analyse',
                                       '--format',
                                       'json',
                                       '--fail-on',
                                       'none',
                                       '--no-baseline',
                                       'src/OrderCalculator.php',
                                   ], $project);
            $process->setInput($inputStream);
            $process->setTimeout(self::HANG_GUARD_TIMEOUT_SECONDS);
            $process->start();
            $process->wait();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertFileDoesNotExist($project . '/.gruff-php.yaml');
            self::assertStringNotContainsString(MissingConfigPrompt::PROMPT_TEXT, $process->getErrorOutput());
        } finally {
            $inputStream->close();
            $this->removeDir($project);
        }
    }
}
