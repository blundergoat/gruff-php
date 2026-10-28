<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Console;

use Symfony\Component\Process\Process;

/**
 * Pins the argument-order clause FAMILY-CONTRACT.md section 7 ratifies on 2026-09-06.
 *
 * Every operand-accepting command must produce the same output and the same exit code whether its flags are written
 * before or after the path. The defect the clause exists to prevent is real and was shipped: gruff-go silently
 * discarded flags placed after a path, so `analyse . --fail-on=error` ran at the default threshold and a CI gate
 * nobody had disabled stopped gating.
 *
 * Reach for this test when adding a command that takes paths, or when changing how the console parses arguments.
 */
final class ArgumentOrderCliTest extends CliTestCase
{
    /**
     * Verify that moving a command's flags after its path changes neither its output nor its exit code.
     *
     * @return void
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
     * Verify that `--` ends flag parsing, so a leading-dash operand after it is reachable as a path.
     *
     * @return void
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
     * @return void
     */
    public function testCommandWithoutOperandsRejectsAStrayOne(): void
    {
        $process = $this->runInProject($this->argumentOrderProject(), ['init', 'stray-operand']);

        // Exit 2 is the family's usage exit; 1 would read to a CI gate as findings.
        self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * Build a one-file project the ordering comparison can scan.
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
     * Every operand-accepting command, with the flags whose placement is under test.
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
     * Remove the one field two runs of the same command are allowed to differ on.
     *
     * @param string $output - Raw stdout from one run.
     *
     * @return string - the same output with every generated timestamp replaced by a fixed marker
     */
    private function withoutTimestamps(string $output): string
    {
        return (string) preg_replace('/\d{4}-\d{2}-\d{2}T[\d:.]+Z?/', '<timestamp>', $output);
    }
}
