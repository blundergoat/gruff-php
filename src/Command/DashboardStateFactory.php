<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigLoader;
use Symfony\Component\Console\Input\InputInterface;

final class DashboardStateFactory
{
    /**
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string}
     */
    public function defaultQuery(InputInterface $input, string $projectRoot): array
    {
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $baseline = $input->hasParameterOption('--baseline', true)
            ? ($this->optionalStringOption($input, 'baseline') ?? '')
            : '';

        return [
            'project' => $projectRoot,
            'paths' => implode(' ', $paths === [] ? ['.'] : $paths),
            'scanScope' => $input->hasParameterOption('--diff', true) ? 'diff' : 'full',
            'failOn' => $this->optionalStringOption($input, 'fail-on') ?? 'none',
            'config' => $this->optionalStringOption($input, 'config') ?? ConfigLoader::DEFAULT_CONFIG_FILE,
            'baseline' => $baseline,
            'noBaseline' => (bool) $input->getOption('no-baseline') ? '1' : '0',
            'noConfig' => (bool) $input->getOption('no-config') ? '1' : '0',
            'includeIgnored' => (bool) $input->getOption('include-ignored') ? '1' : '0',
            'reportInteractive' => '0',
        ];
    }

    /**
     * Resolves the startup project option against the shell directory.
     */
    public function initialProjectRoot(InputInterface $input, string $launchRoot): ?string
    {
        return $this->resolveProjectRoot($this->optionalStringOption($input, 'project') ?? $launchRoot, $launchRoot);
    }

    /**
     * @param array<string, string> $query
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string}
     */
    public function state(InputInterface $input, string $projectRoot, array $query): array
    {
        $defaults = $this->defaultQuery($input, $projectRoot);
        $scanScope = $query['scanScope'] ?? $defaults['scanScope'];

        return [
            'project' => $query['project'] ?? $defaults['project'],
            'paths' => $query['paths'] ?? $defaults['paths'],
            'scanScope' => $scanScope === 'diff' ? 'diff' : 'full',
            'failOn' => $query['failOn'] ?? $defaults['failOn'],
            'config' => $query['config'] ?? $defaults['config'],
            'baseline' => $query['baseline'] ?? $defaults['baseline'],
            'noBaseline' => ($query['noBaseline'] ?? $defaults['noBaseline']) === '1' ? '1' : '0',
            'noConfig' => ($query['noConfig'] ?? $defaults['noConfig']) === '1' ? '1' : '0',
            'includeIgnored' => ($query['includeIgnored'] ?? $defaults['includeIgnored']) === '1' ? '1' : '0',
            'reportInteractive' => ($query['reportInteractive'] ?? $defaults['reportInteractive']) === '1' ? '1' : '0',
        ];
    }

    /**
     * Returns an existing absolute project directory, or null when invalid.
     */
    public function resolveProjectRoot(string $project, string $baseRoot): ?string
    {
        $path = str_starts_with($project, '/') ? $project : $baseRoot . '/' . $project;
        $realPath = realpath($path);

        return is_string($realPath) && is_dir($realPath) ? $realPath : null;
    }

    /**
     * Reads a non-empty string option from console input.
     */
    public function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
