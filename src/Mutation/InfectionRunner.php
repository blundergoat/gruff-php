<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Analysis\RunDiagnostic;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs Infection with gruff's configured mutation-analysis arguments.
 */
final readonly class InfectionRunner
{
    /**
     * Run Infection and capture its process result.
     *
     * @param string      $projectRoot          Project root where Infection should run.
     * @param string      $binary               Infection binary path or command name.
     * @param string|null $configPath           Infection config path, when supplied.
     * @param string|null $testFrameworkOptions Extra test-framework options passed to Infection.
     * @return InfectionRunResult Process result and optional diagnostic.
     */
    public function run(
        string $projectRoot,
        string $binary,
        ?string $configPath,
        ?string $testFrameworkOptions = null,
    ): InfectionRunResult {
        $resolvedBinary = $this->resolveBinary($projectRoot, $binary);

        if ($resolvedBinary === null) {
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
     * @return string|null Executable path, or null when not found.
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

        if (is_file($localBinary) && is_executable($localBinary)) {
            return $localBinary;
        }

        $resolved = (new ExecutableFinder())->find($binary);

        return is_string($resolved) ? $resolved : null;
    }

    /**
     * Resolve a path relative to the project root when needed.
     *
     * @return string Absolute path.
     */
    private function absolutePath(string $projectRoot, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . $path;
    }
}
