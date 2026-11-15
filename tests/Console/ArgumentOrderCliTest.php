<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Exercises FAMILY-CONTRACT.md section 7 for path operands and option parsing.
 * Compares exit codes and timestamp-normalized stdout with flags before and after paths.
 * Also checks -- before a leading-dash path and rejection of an operand passed to init.
 */
final class ArgumentOrderCliTest extends CliTestCase
{
    /**
     * Verify that moving flags after a command path preserves its exit code and output apart from timestamp-shaped text.
     *
     * @return void - Asserts matching exit codes and timestamp-normalised output for every operand command.
     */
    public function testOperandCommandsAcceptFlagsAfterThePath(): void
    {
        $project = $this->argumentOrderProject();

        foreach ($this->operandCommands() as $label => $arguments) {
            [$command, $flags, $operand] = $arguments;

            $before = $this->runInProject($project, [$command, ...$flags, $operand]);
            $after  = $this->runInProject($project, [$command, $operand, ...$flags]);

            self::assertSame(
                $before->getExitCode(),
                $after->getExitCode(),
                sprintf('%s exits differently when its flags follow the path: %s', $label, $after->getErrorOutput()),
            );
            self::assertSame(
                $this->withoutTimestamps($before->getOutput()),
                $this->withoutTimestamps($after->getOutput()),
                sprintf('%s prints different output when its flags follow the path', $label),
            );
        }
    }

    /**
     * Verify that -- ends flag parsing, so a leading-dash operand after it is reachable as a path.
     *
     * @return void - Asserts exit 0 and output containing the leading-dash filename.
     */
    public function testDoubleDashEndsFlagParsing(): void
    {
        $project = $this->argumentOrderProject();
        file_put_contents($project . '/-Dashed.php', "<?php\n\nfunction dashed(int \$rx): int\n{\n    return \$rx + \$rx;\n}\n");

        $process = $this->runInProject($project, ['analyse', '--no-config', '--fail-on', 'none', '--format', 'json', '--', '-Dashed.php']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('-Dashed.php', $process->getOutput());
    }

    /**
     * Verify that a command taking no operands rejects one rather than discarding it silently.
     *
     * @return void - Asserts usage exit code 2 for init with a stray operand.
     */
    public function testCommandWithoutOperandsRejectsAStrayOne(): void
    {
        $process = $this->runInProject($this->argumentOrderProject(), ['init', 'stray-operand']);

        // Exit 2 is the family's usage exit; 1 would read to a CI gate as findings.
        self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * Build a project with one PHP source file and a README for the ordering comparison.
     *
     * @return string - absolute path to the created project root
     */
    private function argumentOrderProject(): string
    {
        $project = $this->tempDir();
        file_put_contents($project . '/Probe.php', "<?php\n\nfunction probe(int \$rx): int\n{\n    return \$rx + \$rx;\n}\n");
        file_put_contents($project . '/README.md', "Argument-order fixture.\n");

        return $project;
    }

    /**
     * Supplies the operand-command cases and flags covered by the ordering comparison.
     *
     * @return array<string, array{0: string, 1: list<string>, 2: string}> - command, flags, and operand per case
     */
    private function operandCommands(): array
    {
        return [
            'analyse' => ['analyse', ['--no-config', '--fail-on', 'none', '--format', 'json'], 'Probe.php'],
            'summary' => ['summary', ['--no-config', '--fail-on', 'none', '--format', 'json'], 'Probe.php'],
            'report' => ['report', ['--no-config', '--fail-on', 'none', '--format', 'json'], 'Probe.php'],
            'hook' => ['hook', ['--no-config', '--format', 'json'], 'Probe.php'],
            'check-ignore' => ['check-ignore', ['--no-config', '--format', 'json'], 'Probe.php'],
        ];
    }

    /**
     * Run one gruff-php invocation inside a project directory.
     *
     * @param string       $project   - Project root the command runs in.
     * @param list<string> $arguments - Command and arguments, without the interpreter or entry point.
     *
     * @return Process - the finished process, so the caller can read its exit code and both streams
     */
    private function runInProject(string $project, array $arguments): Process
    {
        $process = new Process([PHP_BINARY, self::PROJECT_ROOT . '/bin/gruff-php', ...$arguments], $project);
        $process->run();

        return $process;
    }

    /**
     * Normalises timestamp-shaped text before comparing output from two command runs.
     *
     * @param string $output - Raw stdout from one run.
     *
     * @return string - Output with every matching timestamp-shaped substring replaced by a fixed marker.
     */
    private function withoutTimestamps(string $output): string
    {
        // Ignore date-time-shaped differences, including values whose trailing Z is absent.
        return (string) preg_replace('/\d{4}-\d{2}-\d{2}T[\d:.]+Z?/', '<timestamp>', $output);
    }
}
