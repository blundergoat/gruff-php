<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Shells out to Infection to actually run mutation testing, then packages the outcome gruff needs.
 *
 * When a user asks gruff to run mutation analysis itself (`gruff-php analyse --infection-run`), this is
 * what launches Infection: it locates the executable (explicit path, the project's `vendor/bin`, or
 * PATH), assembles the run command with the right config and test-framework options, and captures the
 * exit code and output into an `InfectionRunResult`. A missing binary comes back as a diagnostic rather
 * than an exception, so a misconfigured `--infection-bin` degrades to a clear message instead of a crash.
 */
final readonly class InfectionRunner
{
    /**
     * Runs Infection once and hands back its outcome, turning a missing executable into a diagnostic
     * rather than throwing, so the user always gets a result to render.
     *
     * @param string      $projectRoot - Project root Infection runs in and relative paths resolve against.
     * @param string      $binary - Infection binary path or command name to launch.
     * @param string|null $configPath - Infection config file to use; null falls back to a project `infection.json5` when present, otherwise Infection's own default.
     * @param string|null $testFrameworkOptions - Extra options forwarded to the test framework; null or blank forwards none.
     *
     * @return InfectionRunResult - The captured exit code and output; or, when the binary cannot be found, a result carrying a `mutation-tool-error` diagnostic instead of a real run.
     */
    public function runInfection(
        string $projectRoot,
        string $binary,
        ?string $configPath,
        ?string $testFrameworkOptions = null,
    ): InfectionRunResult {
        $resolvedBinary = $this->resolveBinary($projectRoot, $binary);

        // The Infection executable could not be located, so there is nothing to run.
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

        // Only pass --configuration when we actually resolved a config file to hand Infection.
        if ($effectiveConfigPath !== null) {
            $command[] = '--configuration';
            $command[] = $this->absolutePath($projectRoot, $effectiveConfigPath);
        }

        // Forward test-framework options only when the user actually supplied some.
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
     * Finds a runnable Infection executable, preferring an explicit path, then the project's
     * vendor/bin, then PATH - so the user's configured binary wins but a bare `infection` still works.
     *
     * @param string $projectRoot - Anchor for relative binary paths and the vendor/bin lookup.
     * @param string $binary - Configured binary; a slash means an explicit path, otherwise a PATH/vendor name.
     *
     * @return string|null - Path to a runnable Infection executable; null when none was found, so the caller reports "not found".
     */
    private function resolveBinary(string $projectRoot, string $binary): ?string
    {
        // An empty binary setting gives nothing to resolve.
        if ($binary === '') {
            // Empty config gives nothing to resolve; let the caller surface the not-found diagnostic.
            return null;
        }

        // A slash marks an explicit path the user pointed at, rather than a bare command name to look up.
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            $candidate = $this->absolutePath($projectRoot, $binary);

            // An explicit path is honoured only when it actually points at a runnable file.
            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        $localBinary = rtrim($projectRoot, '/') . '/vendor/bin/' . $binary;

        // Prefer the project's own vendor/bin Infection so the run matches the version the project pinned.
        if (is_file($localBinary) && is_executable($localBinary)) {
            // Prefer the project-local Infection over any global one on PATH.
            return $localBinary;
        }

        $resolved = (new ExecutableFinder())->find($binary);

        // Fall back to PATH; null here means resolution failed and the run cannot proceed.
        return is_string($resolved) ? $resolved : null;
    }

    /**
     * Anchors a relative path onto the project root, leaving an already-absolute path as it is.
     *
     * @param string $projectRoot - Base directory a relative path is joined onto.
     * @param string $path - Candidate path; returned unchanged when already absolute.
     *
     * @return string - Absolute path to the target.
     */
    private function absolutePath(string $projectRoot, string $path): string
    {
        // Already-absolute paths pass through; only relative ones get anchored to the project root.
        return PathHelper::resolveAgainst($projectRoot, $path);
    }
}
