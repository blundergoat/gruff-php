<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Rule\RuleRegistry;
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
     *
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                        noConfig: string, includeIgnored: string, reportInteractive: string} - initial form control values for an unsubmitted
     *                        dashboard, with CLI options applied and checkbox flags as "1"/"0" strings
     */
    public function defaultQuery(InputInterface $input, string $projectRoot): array
    {
        /** @var list<string> $paths The command definition declares a variadic paths argument. */
        $paths     = $input->getArgument('paths');
        $baseline  = $input->hasParameterOption('--baseline', true)
            ? ($this->optionalStringOption($input, 'baseline') ?? 'gruff-baseline.json')
            : '';
        $pathState = implode(' ', array_map($this->pathToken(...), $paths === [] ? ['.'] : $paths));

        // Initial control values for an unsubmitted form: CLI options win, with safe fallbacks for the rest.
        return [
            'project'           => $projectRoot,
            'paths'             => $pathState,
            'scanScope'         => $input->hasParameterOption('--diff', true) ? 'diff' : 'full',
            'failOn'            => $this->resolveDashboardFailOn($input, $projectRoot),
            'config'            => $this->optionalStringOption($input, 'config') ?? ConfigLoader::DEFAULT_CONFIG_FILE,
            'baseline'          => $baseline,
            'noBaseline'        => (bool)$input->getOption('no-baseline') ? '1' : '0',
            'noConfig'          => (bool)$input->getOption('no-config') ? '1' : '0',
            'includeIgnored'    => (bool)$input->getOption('include-ignored') ? '1' : '0',
            'reportInteractive' => '0',
        ];
    }

    /**
     * Quote a dashboard path token when the parser needs help preserving it.
     *
     * @param string $path Console path argument.
     *
     * @return string - the path re-encoded so DashboardScanCommandBuilder::parsePaths() round-trips it intact, quoted and escaped only when it
     *                contains characters the tokenizer would split on
     */
    private function pathToken(string $path): string
    {
        if ($path !== '' && strpbrk($path, " \t\r\n\"\\") === false) {
            // No whitespace, quote, or backslash: the tokenizer reads it verbatim, so emit it bare.
            return $path;
        }

        // Path carries characters the parser would split on; wrap in quotes and escape \ and " for round-trip safety.
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"';
    }

    /**
     * Resolves the startup project option against the shell directory.
     *
     * @param InputInterface $input      Console input containing the optional project override.
     * @param string         $launchRoot Shell working directory that launched the dashboard.
     *
     * @return string|null - canonical project root chosen from --project-root/--project/launchRoot; null when the resolved path is not an existing
     *                     directory
     */
    public function initialProjectRoot(InputInterface $input, string $launchRoot): ?string
    {
        $project = $this->optionalStringOption($input, 'project-root')
                   ?? $this->optionalStringOption($input, 'project')
                      ?? $launchRoot;

        // Resolve and validate the chosen path; null here means the option pointed at a non-directory.
        return $this->resolveProjectRoot($project, $launchRoot);
    }

    /**
     * Merge dashboard request query values with console-input defaults.
     *
     * @param InputInterface        $input       Console input used to seed dashboard defaults.
     * @param string                $projectRoot Active project root for the dashboard.
     * @param array<string, string> $query       Request query values from the dashboard form.
     *
     * @return array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                        noConfig: string, includeIgnored: string, reportInteractive: string} - merged form state where submitted query values
     *                        override defaults and checkbox flags resolve to "1"/"0" strings
     */
    public function state(InputInterface $input, string $projectRoot, array $query): array
    {
        $defaults        = $this->defaultQuery($input, $projectRoot);
        $scanScope       = $query['scanScope'] ?? $defaults['scanScope'];
        $isSubmittedForm = $query !== [];

        // Submitted form values override defaults; checkboxes route through checkboxState so an absent box reads as off.
        return [
            'project'           => $query['project'] ?? $defaults['project'],
            'paths'             => $query['paths'] ?? $defaults['paths'],
            'scanScope'         => $scanScope === 'diff' ? 'diff' : 'full',
            'failOn'            => $query['failOn'] ?? $defaults['failOn'],
            'config'            => $query['config'] ?? $defaults['config'],
            'baseline'          => $query['baseline'] ?? $defaults['baseline'],
            'noBaseline'        => $this->checkboxState('noBaseline', $query, $defaults, $isSubmittedForm),
            'noConfig'          => $this->checkboxState('noConfig', $query, $defaults, $isSubmittedForm),
            'includeIgnored'    => $this->checkboxState('includeIgnored', $query, $defaults, $isSubmittedForm),
            'reportInteractive' => $this->checkboxState('reportInteractive', $query, $defaults, $isSubmittedForm),
        ];
    }

    /**
     * Resolve a submitted dashboard checkbox value.
     *
     * @param string                $key             Checkbox state key (such as noBaseline) to resolve.
     * @param array<string, string> $query           Raw request query; an unchecked HTML checkbox is absent, not "0".
     * @param array<string, string> $defaults        Initial state used only before the first submission.
     * @param bool                  $isSubmittedForm True once any query value was posted, so a missing box means off.
     *
     * @return string - "1" when the box resolves to checked, else "0"; an absent key on a submitted form reads as off, falling back to the default
     *                only before the first submission
     */
    private function checkboxState(string $key, array $query, array $defaults, bool $isSubmittedForm): string
    {
        if (array_key_exists($key, $query)) {
            // The form sent this key explicitly; normalise anything other than "1" to off.
            return $query[$key] === '1' ? '1' : '0';
        }

        if ($isSubmittedForm) {
            // Form was submitted but this checkbox is absent, which HTML represents as unchecked.
            return '0';
        }

        // No submission yet, so fall back to the initial default for this control.
        return $defaults[$key] === '1' ? '1' : '0';
    }

    /**
     * Returns an existing absolute project directory, or null when invalid.
     *
     * @param string $project  Project path from the request or command input.
     * @param string $baseRoot Base directory used for relative project paths.
     *
     * @return string|null - realpath-canonicalised absolute directory when it exists, or null signalling an invalid or missing project root
     */
    public function resolveProjectRoot(string $project, string $baseRoot): ?string
    {
        $path     = PathHelper::resolveAgainst($baseRoot, $project);
        $realPath = realpath($path);

        // Canonical path only when it exists and is a directory; null signals an invalid or missing project root.
        return is_string($realPath) && is_dir($realPath) ? $realPath : null;
    }

    /**
     * Apply ADR-015 precedence to the dashboard's initial-state --fail-on value.
     *
     * Explicit CLI flag > config.minimumSeverity.dashboard > binary default `none`.
     * M12 will extend this through the form rendering and round-trip; M11 only
     * fixes the initial-state default-source chain.
     *
     * @param InputInterface $input       Console input for the dashboard command.
     * @param string         $projectRoot Active project root resolved from --project/--project-root.
     *
     * @return string - fail-on threshold seeded by ADR-015 precedence (explicit flag, then config, then "none"), never null so the form always has
     *                an initial value
     */
    private function resolveDashboardFailOn(InputInterface $input, string $projectRoot): string
    {
        if ($input->hasParameterOption('--fail-on', true)) {
            // Explicit --fail-on wins (ADR-015); a bare flag with no value degrades to the "none" default.
            return $this->optionalStringOption($input, 'fail-on') ?? 'none';
        }

        // No flag: fall to config.minimumSeverity.dashboard, then the binary default of "none".
        return $this->loadConfigFailThreshold($input, $projectRoot) ?? 'none';
    }

    /**
     * Load the project config (best-effort) and read `minimumSeverity.dashboard`.
     *
     * Config errors are swallowed because the dashboard server re-loads the
     * same config for every request; this lookup just seeds the initial form
     * value. Returning null lets the caller fall back to the binary default.
     *
     * The config is read from the resolved `--project/--project-root`, not the
     * shell's `getcwd()`, so launching the dashboard from outside the target
     * project still reads the right `.gruff-php.yaml`.
     *
     * @param InputInterface $input       Console input for the dashboard command.
     * @param string         $projectRoot Resolved project root to load config from.
     *
     * @return string|null - configured minimumSeverity.dashboard value, or null when --no-config is set, the key is unset, or config loading failed
     */
    private function loadConfigFailThreshold(InputInterface $input, string $projectRoot): ?string
    {
        if ((bool)$input->getOption('no-config')) {
            // --no-config opts out of config entirely, so there is no threshold to seed; let the caller default it.
            return null;
        }

        try {
            $config = (new ConfigLoader($projectRoot, ConfigLoader::packageRoot()))
                ->load($this->optionalStringOption($input, 'config'), RuleRegistry::defaults());
        } catch (ConfigException) {
            // Swallow config errors: the request handler re-loads config and reports them; this lookup just seeds the form.
            return null;
        }

        // The configured dashboard threshold, or null when the key is unset, so the caller can apply its default.
        return $config->failThresholdFor('dashboard')?->value;
    }

    /**
     * Reads a non-empty string option from console input.
     *
     * @param InputInterface $input Console input to read.
     * @param string         $name  Option name without leading dashes.
     *
     * @return string|null - the option's non-empty string value, or null collapsing both "undefined on this command" and "" so callers can ?? a
     *                     single fallback
     */
    public function optionalStringOption(InputInterface $input, string $name): ?string
    {
        if (!$input->hasOption($name)) {
            // Option is not defined on this command at all, so treat it as unset rather than letting Symfony throw.
            return null;
        }

        $optionValue = $input->getOption($name);

        // Non-empty string only; null collapses both "absent" and "" so callers can use ?? for a single fallback.
        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
    }
}
