<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigLoader;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Builds dashboard query state from console input and request parameters.
 */
final class DashboardStateFactory
{
    /**
     * Build the default dashboard query values from console input.
     *
     * @param InputInterface $input       Console input used to seed dashboard controls.
     * @param string         $projectRoot Active project root for the dashboard.
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string}
     */
    public function defaultQuery(InputInterface $input, string $projectRoot): array
    {
        /** @var list<string> $paths The command definition declares a variadic paths argument. */
        $paths    = $input->getArgument('paths');
        $baseline = $input->hasParameterOption('--baseline', true)
            ? ($this->optionalStringOption($input, 'baseline') ?? 'gruff-baseline.json')
            : '';
        $pathState = implode(' ', array_map($this->pathToken(...), $paths === [] ? ['.'] : $paths));

        return [
            'project' => $projectRoot,
            'paths' => $pathState,
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
     * Quote a dashboard path token when the parser needs help preserving it.
     *
     * @param string $path Console path argument.
     * @return string Token suitable for DashboardScanCommandBuilder::parsePaths().
     */
    private function pathToken(string $path): string
    {
        if ($path !== '' && strpbrk($path, " \t\r\n\"\\") === false) {
            return $path;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"';
    }

    /**
     * Resolves the startup project option against the shell directory.
     *
     * @param InputInterface $input      Console input containing the optional project override.
     * @param string         $launchRoot Shell working directory that launched the dashboard.
     * @return string|null Existing project root, or null when the option is invalid.
     */
    public function initialProjectRoot(InputInterface $input, string $launchRoot): ?string
    {
        $project = $this->optionalStringOption($input, 'project-root')
            ?? $this->optionalStringOption($input, 'project')
            ?? $launchRoot;

        return $this->resolveProjectRoot($project, $launchRoot);
    }

    /**
     * Merge dashboard request query values with console-input defaults.
     *
     * @param InputInterface        $input       Console input used to seed dashboard defaults.
     * @param string                $projectRoot Active project root for the dashboard.
     * @param array<string, string> $query       Request query values from the dashboard form.
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string}
     */
    public function state(InputInterface $input, string $projectRoot, array $query): array
    {
        $defaults        = $this->defaultQuery($input, $projectRoot);
        $scanScope       = $query['scanScope'] ?? $defaults['scanScope'];
        $isSubmittedForm = $query !== [];

        return [
            'project' => $query['project'] ?? $defaults['project'],
            'paths' => $query['paths'] ?? $defaults['paths'],
            'scanScope' => $scanScope === 'diff' ? 'diff' : 'full',
            'failOn' => $query['failOn'] ?? $defaults['failOn'],
            'config' => $query['config'] ?? $defaults['config'],
            'baseline' => $query['baseline'] ?? $defaults['baseline'],
            'noBaseline' => $this->checkboxState('noBaseline', $query, $defaults, $isSubmittedForm),
            'noConfig' => $this->checkboxState('noConfig', $query, $defaults, $isSubmittedForm),
            'includeIgnored' => $this->checkboxState('includeIgnored', $query, $defaults, $isSubmittedForm),
            'reportInteractive' => $this->checkboxState('reportInteractive', $query, $defaults, $isSubmittedForm),
        ];
    }

    /**
     * Resolve a submitted dashboard checkbox value.
     *
     * @param array<string, string> $query
     * @param array<string, string> $defaults
     * @return string "1" when checked, otherwise "0".
     */
    private function checkboxState(string $key, array $query, array $defaults, bool $isSubmittedForm): string
    {
        if (array_key_exists($key, $query)) {
            return $query[$key] === '1' ? '1' : '0';
        }

        if ($isSubmittedForm) {
            return '0';
        }

        return $defaults[$key] === '1' ? '1' : '0';
    }

    /**
     * Returns an existing absolute project directory, or null when invalid.
     *
     * @param string $project  Project path from the request or command input.
     * @param string $baseRoot Base directory used for relative project paths.
     * @return string|null Existing absolute project directory.
     */
    public function resolveProjectRoot(string $project, string $baseRoot): ?string
    {
        $path     = PathHelper::resolveAgainst($baseRoot, $project);
        $realPath = realpath($path);

        return is_string($realPath) && is_dir($realPath) ? $realPath : null;
    }

    /**
     * Reads a non-empty string option from console input.
     *
     * @param InputInterface $input Console input to read.
     * @param string         $name  Option name without leading dashes.
     * @return string|null Option value, or null when missing or empty.
     */
    public function optionalStringOption(InputInterface $input, string $name): ?string
    {
        if (!$input->hasOption($name)) {
            return null;
        }

        $optionValue = $input->getOption($name);

        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
    }
}
