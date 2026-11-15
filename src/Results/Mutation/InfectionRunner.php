<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Resolves and launches Infection for mutation analysis, capturing its exit status and output.
 * Explicit paths are checked directly; bare names prefer the project's vendor/bin before PATH.
 *
 * An unresolved executable produces a diagnostic result before any process is started.
 */
final readonly class InfectionRunner
{
    /**
     * Runs Infection once, returning a diagnostic result when its executable cannot be resolved.
     * Process-launch and wait errors propagate to the caller.
     *
     * @param string $projectRoot - Working directory and base for relative executable and configuration paths.
     * @param string $binary - Infection executable path or command name.
     * @param string|null $configPath - Explicit config; null uses project infection.json5 when present, otherwise Infection's default.
     * @param string|null $testFrameworkOptions - Options forwarded to the test framework; null or blank forwards none.
     *
     * @return InfectionRunResult - Captured process outcome, or a mutation-tool-error diagnostic when executable resolution fails.
     */
    public function runInfection(
        string $projectRoot,
        string $binary,
        ?string $configPath,
        ?string $testFrameworkOptions = null,
    ): InfectionRunResult {
        $resolvedBinary = $this->resolveBinary($projectRoot, $binary);


        if ($resolvedBinary === null) {
            // No executable means no run; report the failure as a result rather than throwing.
            return new InfectionRunResult(
                exitCode:    2,
                output:      '',
                errorOutput: '',
                diagnostic:  new RunDiagnostic(
                    type:        'mutation-tool-error',
                    message:     sprintf('Infection executable not found: %s', $binary),
                    path:        $binary,
                ),
            );
        }

        $defaultConfigPath   = 'infection.json5';
        $effectiveConfigPath = $configPath
            // With no explicit config, fall back to a project-local infection.json5 when one exists, else let Infection find its own.
            ?? (is_file(rtrim($projectRoot, '/') . '/' . $defaultConfigPath) ? $defaultConfigPath : null);
        $command = [$resolvedBinary, 'run', '--no-progress', '--log-verbosity=none'];


        if ($effectiveConfigPath !== null) {
            $command[] = '--configuration';
            $command[] = $this->absolutePath($projectRoot, $effectiveConfigPath);
        }


        if ($testFrameworkOptions !== null && trim($testFrameworkOptions) !== '') {
            $command[] = '--test-framework-options=' . $testFrameworkOptions;
        }

        $process = new Process($command, $projectRoot);
        $process->setTimeout(null);
        $process->run();

        return new InfectionRunResult(
            // A process that reported no exit code stands in as 2, the same "errored" code used when the binary was missing.
            exitCode:    $process->getExitCode() ?? 2,
            output:      $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        );
    }

    /**
     * Checks an explicit executable path directly, or searches a bare name in vendor/bin and then PATH.
     *
     * @param string $projectRoot - Base for relative executable paths and the project-local vendor/bin lookup.
     * @param string $binary - Executable path when it contains a slash or backslash; otherwise a command name.
     *
     * @return string|null - Resolved executable, or null for an empty name, an unusable explicit path, or a failed search.
     */
    private function resolveBinary(string $projectRoot, string $binary): ?string
    {

        if ($binary === '') {

            return null;
        }


        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            $candidate = $this->absolutePath($projectRoot, $binary);


            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        $localBinary = rtrim($projectRoot, '/') . '/vendor/bin/' . $binary;

        // Prefer the project's executable to avoid selecting a different installation from PATH.
        if (is_file($localBinary) && is_executable($localBinary)) {

            return $localBinary;
        }

        $resolved = (new ExecutableFinder())->find($binary);


        return is_string($resolved) ? $resolved : null;
    }

    /**
     * Resolves a path against the project root after normalizing whitespace and separators.
     *
     * @param string $projectRoot - Base directory used for relative or empty paths.
     * @param string $path - Path to resolve; blank selects the normalized project root.
     *
     * @return string - Normalized absolute input or a path joined to the supplied root.
     */
    private function absolutePath(string $projectRoot, string $path): string
    {

        return PathHelper::resolveAgainst($projectRoot, $path);
    }
}
