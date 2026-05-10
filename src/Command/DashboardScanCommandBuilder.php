<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Process\Process;

final readonly class DashboardScanCommandBuilder
{
    public function __construct(private string $gruffBinary)
    {
    }

    /**
     * @return list<string>
     */
    public function parsePaths(string $paths): array
    {
        $parts = preg_split('/\s+/', trim($paths));

        if ($parts === false || $parts === ['']) {
            return ['.'];
        }

        return array_values(array_filter($parts, static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $state
     * @phpstan-param array{project: string, paths: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, mutation: string} $state
     * @param list<string> $editedUnitTestFiles
     * @return list<string>
     */
    public function analyseCommand(array $paths, array $state, string $projectRoot, array $editedUnitTestFiles): array
    {
        $command = [PHP_BINARY, $this->gruffBinary, 'analyse', ...$paths, '--format', 'html', '--fail-on', $state['failOn']];

        if ($state['noConfig'] === '1') {
            $command[] = '--no-config';
        } elseif ($state['config'] !== '') {
            $command[] = '--config';
            $command[] = $state['config'];
        }

        if ($state['noBaseline'] === '1') {
            $command[] = '--no-baseline';
        } elseif ($state['baseline'] !== '') {
            $command[] = '--baseline';
            $command[] = $state['baseline'];
        }

        if ($state['includeIgnored'] === '1') {
            $command[] = '--include-ignored';
        }

        if ($state['mutation'] === 'run') {
            $command[] = '--infection-run';
            $command[] = '--infection-report';
            $command[] = 'infection-report.json';
            $command[] = '--infection-test-framework-options=' . $this->infectionTestFrameworkOptions($projectRoot, $editedUnitTestFiles);
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function editedUnitTestFiles(string $projectRoot): array
    {
        $candidates = array_merge(
            $this->gitPaths($projectRoot, ['diff', '--name-only', '--diff-filter=AM', 'HEAD', '--', 'tests']),
            $this->gitPaths($projectRoot, ['ls-files', '--others', '--exclude-standard', '--', 'tests']),
        );
        $files = [];
        $seen = [];

        foreach ($candidates as $path) {
            if (!$this->isUnitTestFile($projectRoot, $path) || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $files[] = $path;
        }

        return $files;
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function gitPaths(string $projectRoot, array $arguments): array
    {
        $process = new Process(['git', ...$arguments], $projectRoot);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\r?\n/', trim($process->getOutput())) ?: [],
            static fn (string $path): bool => $path !== '',
        ));
    }

    private function isUnitTestFile(string $projectRoot, string $path): bool
    {
        return str_starts_with($path, 'tests/')
            && str_ends_with($path, 'Test.php')
            && !str_starts_with($path, 'tests/Console/')
            && !str_ends_with($path, 'IntegrationTest.php')
            && is_file(rtrim($projectRoot, '/') . '/' . $path);
    }

    /**
     * @param list<string> $editedUnitTestFiles
     */
    private function infectionTestFrameworkOptions(string $projectRoot, array $editedUnitTestFiles): string
    {
        $filters = [];

        foreach ($editedUnitTestFiles as $path) {
            $className = $this->testClassName($projectRoot, $path);
            if ($className !== '') {
                $filters[] = preg_quote($className, '/');
            }
        }

        return '--testsuite=unit --filter=' . implode('|', $filters);
    }

    private function testClassName(string $projectRoot, string $path): string
    {
        $absolutePath = rtrim($projectRoot, '/') . '/' . $path;
        $contents = file_get_contents($absolutePath);

        if (is_string($contents) && preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $matches) === 1) {
            return $matches[1];
        }

        return basename($path, '.php');
    }
}
