<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs Infection with gruff's configured mutation-analysis arguments.
 */
final readonly class InfectionRunner
{
    /**
     * Execute Infection and capture its process result.
     *
     * @param string      $projectRoot - Project root where Infection should run.
     * @param string      $binary - Infection binary path or command name.
     * @param string|null $configPath - Infection config path, when supplied.
     * @param string|null $testFrameworkOptions - Extra test-framework options passed to Infection.
     *
     * @return InfectionRunResult - Process result and optional diagnostic.
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
            exitCode:    $process->getExitCode() ?? 2,
            output:      $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        );
    }

    /**
     * Resolve an Infection executable from a path, vendor bin, or PATH lookup.
     *
     * @param string $projectRoot - Anchor for relative binary paths and the vendor/bin lookup.
     * @param string $binary - Configured binary; a slash means an explicit path, otherwise a PATH/vendor name.
     *
     * @return string|null - Executable path, or null when not found.
     */
    private function resolveBinary(string $projectRoot, string $binary): ?string
    {
        if ($binary === '') {
            // Empty config gives nothing to resolve; let the caller surface the not-found diagnostic.
            return null;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            $candidate = $this->absolutePath($projectRoot, $binary);

            // An explicit path is honoured only when it actually points at a runnable file.
            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        $localBinary = rtrim($projectRoot, '/') . '/vendor/bin/' . $binary;

        if (is_file($localBinary) && is_executable($localBinary)) {
            // Prefer the project-local Infection over any global one on PATH.
            return $localBinary;
        }

        $resolved = (new ExecutableFinder())->find($binary);

        // Fall back to PATH; null here means resolution failed and the run cannot proceed.
        return is_string($resolved) ? $resolved : null;
    }

    /**
     * Resolve a path relative to the project root when needed.
     *
     * @param string $projectRoot - Base directory an already-relative path is joined onto.
     * @param string $path - Candidate path; returned unchanged when already absolute.
     *
     * @return string - Absolute path.
     */
    private function absolutePath(string $projectRoot, string $path): string
    {
        // Already-absolute paths pass through; only relative ones get anchored to the project root.
        return PathHelper::resolveAgainst($projectRoot, $path);
    }
}
