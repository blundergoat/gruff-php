<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use GruffPhp\Analysis\RunDiagnostic;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class InfectionRunner
{
    public function run(string $projectRoot, string $binary, ?string $configPath): InfectionRunResult
    {
        $resolvedBinary = $this->resolveBinary($projectRoot, $binary);

        if ($resolvedBinary === null) {
            return new InfectionRunResult(
                exitCode: 2,
                output: '',
                errorOutput: '',
                diagnostic: new RunDiagnostic(
                    type: 'mutation-tool-error',
                    message: sprintf('Infection executable not found: %s', $binary),
                    path: $binary,
                ),
            );
        }

        $command = [$resolvedBinary, 'run', '--no-progress', '--log-verbosity=none'];

        if ($configPath !== null) {
            $command[] = '--configuration';
            $command[] = $this->absolutePath($projectRoot, $configPath);
        }

        $process = new Process($command, $projectRoot);
        $process->setTimeout(null);
        $process->run();

        return new InfectionRunResult(
            exitCode: $process->getExitCode() ?? 2,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        );
    }

    private function resolveBinary(string $projectRoot, string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            $candidate = $this->absolutePath($projectRoot, $binary);

            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        $resolved = (new ExecutableFinder())->find($binary);

        return is_string($resolved) ? $resolved : null;
    }

    private function absolutePath(string $projectRoot, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . $path;
    }
}
