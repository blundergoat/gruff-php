<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use GruffPhp\Cli\Command\MissingConfigPrompt;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Covers the analyse CLI's missing-config prompt guard: config-less runs with piped (non-TTY) stdin neither hang on the init prompt nor write
 * `.gruff-php.yaml` from piped data that happens to start with "y".
 */
final class AnalyseCliMissingConfigGuardTest extends CliTestCase
{
    /**
     * Upper bound, in seconds, a guarded config-less analyse may take before the run counts as hung.
     */
    private const HANG_GUARD_TIMEOUT_SECONDS = 30;

    /**
     * Verify piped input starting with "y" does not answer the init prompt or write config.
     *
     * Locks the regression where a config-less `analyse --format json` without
     * -n consumed the first piped line as the prompt answer and silently wrote
     * `.gruff-php.yaml` into the scanned repo.
     *
     * @return void
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
            self::assertSame('gruff.analysis.v2', $report['schemaVersion']);
        } finally {
            $this->removeDir($project);
        }
    }

    /**
     * Verify an open-but-silent stdin pipe no longer blocks a config-less analyse run.
     *
     * Locks the regression where the init prompt waited forever in fgets on a
     * pipe that never sends a line (job runners, captured subprocesses).
     *
     * @return void
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
