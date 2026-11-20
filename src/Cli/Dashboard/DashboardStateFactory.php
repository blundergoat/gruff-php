<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Builds the dashboard's form state from CLI launch options and browser query parameters.
 *
 * Two jobs: seed the form the first time the user opens the dashboard (from their launch flags), and
 * merge each submitted form back over those defaults on later requests. Everything a scan needs -
 * paths, scope, fail-on, config, baseline, and the checkboxes - is normalised here into UI state.
 */
final class DashboardStateFactory
{
    /**
     * Seeds the dashboard form the first time the user opens it, straight from their launch flags.
     *
     * @param InputInterface $input       - Console input used to seed dashboard controls.
     * @param string         $projectRoot - Active project root for the dashboard.
     *
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                        noConfig: string, deepScanBudget: string, includeIgnored: string, reportInteractive: string} - initial form control values for an unsubmitted
     *                        dashboard, with CLI options applied and checkbox flags as "1"/"0" strings
     */
    public function defaultQuery(InputInterface $input, string $projectRoot): array
    {
        /** @var list<string> $paths - The command definition declares a variadic paths argument. */
        $paths    = $input->getArgument('paths');
        $baseline = $input->hasParameterOption('--baseline', true)
            ? ($this->optionalStringOption($input, 'baseline') ?? 'gruff-baseline.json')
            : '';
        // No launch paths means "scan the whole project", so fall back to '.' before quoting for the form field.
        $pathState = implode(' ', array_map($this->pathToken(...), $paths === [] ? ['.'] : $paths));

        return [
            'project' => $projectRoot,
            'paths' => $pathState,
            'scanScope' => $input->hasParameterOption('--diff', true) ? 'diff' : 'full',
            'failOn' => $this->resolveDashboardFailOn($input, $projectRoot),
            'config' => $this->optionalStringOption($input, 'config') ?? ConfigLoader::DEFAULT_CONFIG_FILE,
            'baseline' => $baseline,
            'noBaseline' => (bool)$input->getOption('no-baseline') ? '1' : '0',
            'noConfig' => (bool)$input->getOption('no-config') ? '1' : '0',
            'deepScanBudget' => $this->optionalStringOption($input, 'deep-scan-budget') ?? '',
            'includeIgnored' => (bool)$input->getOption('include-ignored') ? '1' : '0',
            'reportInteractive' => '0',
        ];
    }

    /**
     * Encodes a console path for the dashboard field; an empty path is later treated as absent.
     *
     * @param string $path - Console path argument.
     *
     * @return string - Encoded token; non-empty paths round-trip intact, while an empty path is later treated as absent.
     */
    private function pathToken(string $path): string
    {
        // A path with no whitespace, quotes, or backslashes is safe to drop into the field bare.
        if ($path !== '' && strpbrk($path, " \t\r\n\"\\") === false) {
            return $path;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"';
    }

    /**
     * Chooses the dashboard's starting project root from the user's --project flags or the launch dir.
     *
     * @param InputInterface $input      - Console input containing the optional project override.
     * @param string         $launchRoot - Shell working directory that launched the dashboard.
     *
     * @return string|null - Canonical directory from --project-root, then --project, then $launchRoot; null if resolution fails.
     */
    public function initialProjectRoot(InputInterface $input, string $launchRoot): ?string
    {
        $project = $this->optionalStringOption($input, 'project-root')
                   ?? $this->optionalStringOption($input, 'project')
                      ?? $launchRoot;

        return $this->resolveProjectRoot($project, $launchRoot);
    }

    /**
     * Merges a submitted dashboard form over the launch defaults, producing the state a scan runs from.
     *
     * @param InputInterface        $input       - Console input used to seed dashboard defaults.
     * @param string                $projectRoot - Active project root for the dashboard.
     * @param array<string, string> $query       - Request query values from the dashboard form.
     *
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                        noConfig: string, deepScanBudget: string, includeIgnored: string, reportInteractive: string} - merged form state where submitted query values
     *                        override defaults and checkbox flags resolve to "1"/"0" strings
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
            'deepScanBudget' => $query['deepScanBudget'] ?? $defaults['deepScanBudget'],
            'includeIgnored' => $this->checkboxState('includeIgnored', $query, $defaults, $isSubmittedForm),
            'reportInteractive' => $this->checkboxState('reportInteractive', $query, $defaults, $isSubmittedForm),
        ];
    }

    /**
     * Reads one dashboard checkbox back from the submitted form, honouring HTML's "absent means off".
     *
     * @param string                $key             - Checkbox state key (such as noBaseline) to resolve.
     * @param array<string, string> $query           - Raw request query; an unchecked HTML checkbox is absent, not "0".
     * @param array<string, string> $defaults - Initial state used when this request has no query values.
     * @param bool $isSubmittedForm - True when this request has any query values, so a missing box means off.
     *
     * @return string - "1" or "0"; absent keys use defaults only when $isSubmittedForm is false.
     */
    private function checkboxState(string $key, array $query, array $defaults, bool $isSubmittedForm): string
    {

        if (array_key_exists($key, $query)) {
            return $query[$key] === '1' ? '1' : '0';
        }

        // Form was submitted but this checkbox is absent, which HTML represents as unchecked.
        if ($isSubmittedForm) {
            return '0';
        }


        return $defaults[$key] === '1' ? '1' : '0';
    }

    /**
     * Canonicalises a project path and confirms it exists, so scans never point at a missing directory.
     *
     * @param string $project  - Project path from the request or command input.
     * @param string $baseRoot - Base directory used for relative project paths.
     *
     * @return string|null - realpath-canonicalised absolute directory when it exists, or null signalling an invalid or missing project root
     */
    public function resolveProjectRoot(string $project, string $baseRoot): ?string
    {
        $path     = PathHelper::resolveAgainst($baseRoot, $project);
        $realPath = realpath($path);

        return is_string($realPath) && is_dir($realPath) ? $realPath : null;
    }

    /**
     * Chooses the initial dashboard threshold; a present --fail-on flag bypasses configuration.
     *
     * @param InputInterface $input       - Console input for the dashboard command.
     * @param string $projectRoot - Active project root used to load the initial dashboard threshold.
     *
     * @return string - With --fail-on, its non-empty string value or "none"; otherwise the configured threshold or "none".
     */
    private function resolveDashboardFailOn(InputInterface $input, string $projectRoot): string
    {
        // An explicit --fail-on flag wins outright, so honour it before touching the project config.
        if ($input->hasParameterOption('--fail-on', true)) {
            return $this->optionalStringOption($input, 'fail-on') ?? 'none';
        }

        return $this->loadConfigFailThreshold($input, $projectRoot) ?? 'none';
    }

    /**
     * Reads failOn.dashboard from configuration resolved against the selected project root.
     * Invalid configuration leaves the initial form usable by falling back to the caller's default.
     *
     * @param InputInterface $input       - Console input for the dashboard command.
     * @param string         $projectRoot - Resolved project root to load config from.
     *
     * @return string|null - Configured dashboard threshold; null if config is disabled, loading fails, or no threshold is set.
     */
    private function loadConfigFailThreshold(InputInterface $input, string $projectRoot): ?string
    {

        if ((bool)$input->getOption('no-config')) {
            return null;
        }

        try {
            $config = (new ConfigLoader($projectRoot, ConfigLoader::packageRoot()))
                ->load($this->optionalStringOption($input, 'config'), RuleRegistry::defaults());
        } catch (ConfigException) {
            // Invalid config must not block the form; return null so the caller can use its default threshold.
            return null;
        }

        return $config->failThresholdFor('dashboard')?->value;
    }

    /**
     * Reads a non-empty string option, returning null for unset, empty or non-string values so callers can use one fallback.
     *
     * @param InputInterface $input - Console input to read.
     * @param string         $name  - Option name without leading dashes.
     *
     * @return string|null - Non-empty string option, or null when the option is undefined, empty, or not a string.
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
