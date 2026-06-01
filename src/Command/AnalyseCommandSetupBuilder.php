<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Console\Application;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\FailThresholds;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Rule\RuleRegistry;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds validated analyse command setup from console input.
 */
final readonly class AnalyseCommandSetupBuilder
{
    /**
     * Build the validated analysis setup from console input.
     *
     * @param InputInterface           $input - Symfony console input for the analyse command.
     * @param OutputInterface          $output - Symfony console output for optional init prompting.
     * @param SymfonyApplication|null  $symfonyApplication - Console application used to dispatch the init command.
     *
     * @return AnalyseCommandSetupResult - Ready setup or formatted usage/config error.
     */
    public function build(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
    ): AnalyseCommandSetupResult {
        $projectRoot = getcwd();

        if ($projectRoot === false) {
            // Without a working directory there is no project to resolve config/paths against; fail hard.
            return AnalyseCommandSetupResult::plainError(
                '<error>Unable to determine current working directory.</error>',
                Command::FAILURE,
            );
        }

        // Project root is known, so defer all option validation and config loading to the worker.
        return $this->buildSetup($input, $output, $symfonyApplication, $projectRoot);
    }

    /**
     * Build setup, prompting for an init config only after option validation passes.
     *
     * @param InputInterface          $input - Symfony console input for the analyse command.
     * @param OutputInterface         $output - Console output used for the optional init prompt.
     * @param SymfonyApplication|null $symfonyApplication - Console application used to dispatch the init command.
     * @param string                  $projectRoot - Current project root.
     *
     * @return AnalyseCommandSetupResult - Ready setup or formatted usage/config error.
     */
    private function buildSetup(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
        string $projectRoot,
    ): AnalyseCommandSetupResult {
        $options = AnalyseCommandOptions::fromInput($input);
        if ($options->usageError() === '--no-config cannot be combined with --config.') {
            // These two flags are contradictory; report before a format is parsed, so the message stays plain text.
            return AnalyseCommandSetupResult::plainError(
                '<error>--no-config cannot be combined with --config.</error>',
                Command::INVALID,
            );
        }

        $formatResult = $this->format($input->getOption('format'));
        if (!$formatResult instanceof OutputFormat) {
            // An unparseable format means we cannot render a structured report, so the error stays plain text.
            return AnalyseCommandSetupResult::plainError($formatResult, Command::INVALID);
        }

        $failThreshold = $this->failThreshold($input->getOption('fail-on'));
        if (!$failThreshold instanceof FailThreshold) {
            // Non-enum result is the rejected raw value; echo it back through a structured usage report.
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport(
                    $options,
                    $formatResult,
                    $failThreshold,
                    sprintf('Unsupported fail threshold "%s". Use advisory, warning, error, or none.', $failThreshold),
                ),
                $formatResult,
            );
        }

        $mutationBudget = $this->mutationBudget($input->getOption('mutation-budget'));
        if ($mutationBudget === false) {
            // false is the parser's "given but malformed" signal (null would mean omitted); reject it as a usage error.
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport(
                    $options,
                    $formatResult,
                    $failThreshold->value,
                    'Unsupported mutation budget. Use a non-negative integer.',
                ),
                $formatResult,
            );
        }

        $options    = $options->withMutationBudget($mutationBudget);
        $usageError = $options->usageError();
        if ($usageError !== null) {
            // Any remaining cross-option conflict surfaces here, after the budget is folded into the options.
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $usageError),
                $formatResult,
            );
        }

        $promptExitCode = MissingConfigPrompt::maybeOffer(
            input:              $input,
            output:             $output,
            symfonyApplication: $symfonyApplication,
            projectRoot:        $projectRoot,
            explicitConfigPath: $options->configPath,
            shouldSkipConfig:   $options->noConfig,
        );
        if ($promptExitCode !== null) {
            // A non-null code means the init prompt already handled this run (declined or dispatched); stop here.
            return AnalyseCommandSetupResult::exitCode($promptExitCode);
        }

        $options      = $options->withDefaultBaseline($projectRoot);
        $registry     = RuleRegistry::defaults();
        $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());
        $configResult = $this->config(
            options:       $options,
            registry:      $registry,
            format:        $formatResult,
            failThreshold: $failThreshold,
            configLoader:  $configLoader,
        );
        if ($configResult instanceof AnalysisReport) {
            // A report rather than a config means loading raised a ConfigException already rendered as the error.
            return AnalyseCommandSetupResult::reportError($configResult, $formatResult);
        }
        $failThreshold        = $this->resolveFailThresholdWithConfig($input, $configResult, $failThreshold);
        $failThresholds       = $this->resolveFailThresholds($input, $configResult, $failThreshold);
        $referenceError       = $this->newFindingsReferenceError($options, $failThresholds);
        if ($referenceError !== null) {
            // A new-findings gate without a baseline or diff reference cannot decide what "new" means; refuse to run.
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport(
                    options: $options,
                    format:  $formatResult,
                    failOn:  $failThreshold->value,
                    message: $referenceError,
                    type:    'config-error',
                ),
                $formatResult,
            );
        }
        $profileRuleSelection = $options->profileRuleSelection();
        if ($profileRuleSelection !== null) {
            $configResult = $configResult->withRuleSelection($profileRuleSelection);
        }

        // Every gate passed; hand back the assembled setup the analyse command will execute against.
        return AnalyseCommandSetupResult::ready(new AnalyseCommandSetup(
            projectRoot:    $projectRoot,
            options:        $options,
            format:         $formatResult,
            failThreshold:  $failThreshold,
            failThresholds: $failThresholds,
            config:         $configResult,
            configPath:     $this->effectiveConfigPath($options, $configLoader),
            registry:       $registry,
        ));
    }

    /**
     * Parse the requested output format.
     *
     * @param mixed $optionValue - Raw --format console option; a non-string (option absent) defaults to text.
     *
     * @return OutputFormat|string - Parsed format, or a formatted usage error string.
     */
    private function format(mixed $optionValue): OutputFormat|string
    {
        $rawValue = is_string($optionValue) ? $optionValue : OutputFormat::Text->value;
        $format   = OutputFormat::fromInput($rawValue);

        // Null coalesce turns an unrecognised format into the tagged usage-error string the caller checks for.
        return $format ?? sprintf(
            '<error>USAGE-ERROR Unsupported output format "%s". Use text, json, html, markdown, github, hotspot, or sarif.</error>',
            $rawValue,
        );
    }

    /**
     * Apply ADR-015 precedence to the parsed --fail-on value.
     *
     * @param InputInterface $input - Console input used for explicit-flag detection.
     * @param AnalysisConfig $config - Loaded analysis config supplying per-command overrides.
     * @param FailThreshold  $explicitOrDefault - Already-parsed CLI value; binary default when --fail-on omitted.
     *
     * @return FailThreshold - Resolved threshold honouring CLI > config > binary precedence.
     */
    private function resolveFailThresholdWithConfig(
        InputInterface $input,
        AnalysisConfig $config,
        FailThreshold $explicitOrDefault,
    ): FailThreshold {
        if ($input->hasParameterOption('--fail-on', true)) {
            // An explicit CLI --fail-on outranks config under ADR-015, so use the parsed value as-is.
            return $explicitOrDefault;
        }

        // No explicit flag: a per-command config threshold wins, falling back to the binary default.
        return $config->failThresholdFor('analyse') ?? $explicitOrDefault;
    }

    /**
     * Resolve the count-gate thresholds with explicit CLI > failureConditions > resolved-default precedence.
     *
     * An explicit `--fail-on` always wins (back-compat); otherwise an explicit
     * `failureConditions:` block is used; otherwise the already-resolved singular
     * threshold (config minimumSeverity or the binary default) is desugared so the
     * gate stays byte-identical to today.
     *
     * @param InputInterface $input - Console input used for explicit-flag detection.
     * @param AnalysisConfig $config - Loaded config supplying the optional failureConditions block.
     * @param FailThreshold  $failThreshold - Already-resolved singular threshold for the run.
     *
     * @return FailThresholds - Count-gate thresholds that decide the exit code.
     */
    private function resolveFailThresholds(
        InputInterface $input,
        AnalysisConfig $config,
        FailThreshold $failThreshold,
    ): FailThresholds {
        $configFailureConditions = $config->failureConditions();

        if ($input->hasParameterOption('--fail-on', true)) {
            $totalGate = FailThresholds::fromFailOn($failThreshold);
        } elseif ($configFailureConditions instanceof FailThresholds) {
            $totalGate = $configFailureConditions;
        } else {
            $totalGate = FailThresholds::fromFailOn($failThreshold);
        }

        // New-findings gate is independent of the total gate: explicit --fail-on-new
        // wins, else the config's failureConditions.newFindings sub-gate, else none.
        $newFindingsGate = $input->hasParameterOption('--fail-on-new', true)
            ? FailThresholds::fromFailOn(FailThreshold::Error)
            : $configFailureConditions?->newFindingsGate;

        // Combine the resolved total gate with the independent new-findings sub-gate into the final exit-code policy.
        return $totalGate->withNewFindingsGate($newFindingsGate);
    }

    /**
     * Return the "no reference point" error when a new-findings gate is configured
     * without a baseline or --diff-vs to define "new" against, else null.
     *
     * @param AnalyseCommandOptions $options - Validated options carrying baseline and diff-vs selections.
     * @param FailThresholds        $failThresholds - Resolved gate, whose new-findings sub-gate may be set.
     *
     * @return string|null - Remediation message, or null when a reference point exists.
     */
    private function newFindingsReferenceError(AnalyseCommandOptions $options, FailThresholds $failThresholds): ?string
    {
        if ($failThresholds->newFindingsGate === null) {
            // No new-findings gate is in play, so there is nothing to anchor and no error to report.
            return null;
        }

        $baselineWillApply = $options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null;
        if ($baselineWillApply || $options->diffVs !== null) {
            // A consuming baseline (not a generate run) or a --diff-vs ref supplies the reference; the gate is valid.
            return null;
        }

        // Gate configured but no reference exists: return the remediation text naming the flags that fix it.
        return 'The new-findings gate needs a reference point. Configure --baseline or --diff-vs <ref> before enabling --fail-on-new or failureConditions.newFindings.';
    }

    /**
     * Parse the requested failure threshold.
     *
     * @param mixed $optionValue - Raw --fail-on console option; a non-string (option absent) defaults to advisory.
     *
     * @return FailThreshold|string - Parsed threshold, or the unsupported raw value.
     */
    private function failThreshold(mixed $optionValue): FailThreshold|string
    {
        $rawValue = is_string($optionValue) ? $optionValue : FailThreshold::Advisory->value;

        // Return the raw string on failure so the caller can name the rejected value in its error message.
        return FailThreshold::fromInput($rawValue) ?? $rawValue;
    }

    /**
     * Parse the optional mutation finding budget.
     *
     * @param mixed $optionValue - Raw --mutation-budget console option; null means the flag was not supplied.
     *
     * @return int|false|null - Non-negative budget, false for invalid input, or null when omitted.
     */
    private function mutationBudget(mixed $optionValue): int|false|null
    {
        if ($optionValue === null) {
            // null propagates the "flag omitted" state, kept distinct from false so callers do not treat it as invalid.
            return null;
        }

        // Accept only unsigned decimal digits for the optional mutation budget flag.
        return is_string($optionValue) && preg_match('/^\d+$/', $optionValue) === 1 ? (int) $optionValue : false;
    }

    /**
     * Load analysis configuration or convert configuration failures to a report.
     *
     * @param AnalyseCommandOptions $options - Validated options; its noConfig/configPath select the load path.
     * @param RuleRegistry          $registry - Default rule set used to seed config when loading is disabled.
     * @param OutputFormat          $format - Output format stamped onto the error report when loading fails.
     * @param FailThreshold         $failThreshold - Threshold echoed into the error report so its failOn stays accurate.
     * @param ConfigLoader          $configLoader - Loader that reads and validates the on-disk config file.
     *
     * @return AnalysisConfig|AnalysisReport - Loaded config or a formatted config error report.
     */
    private function config(
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        OutputFormat $format,
        FailThreshold $failThreshold,
        ConfigLoader $configLoader,
    ): AnalysisConfig|AnalysisReport {
        try {
            // --no-config builds config from the registry alone; otherwise read and validate the on-disk file.
            return $options->noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($options->configPath, $registry);
        } catch (ConfigException $exception) {
            // Convert a load/validation failure into a structured error report rather than letting it propagate.
            return $this->usageReport(
                options: $options,
                format:  $format,
                failOn:  $failThreshold->value,
                message: $exception->getMessage(),
                type:    'config-error',
            );
        }
    }

    /**
     * Resolve the config path that should be reported for this run.
     *
     * @param AnalyseCommandOptions $options - Validated options; noConfig and an explicit configPath drive the result.
     * @param ConfigLoader          $configLoader - Loader used to auto-discover the path when none was given explicitly.
     *
     * @return string|null - Resolved path, explicit path, or null when config loading is disabled.
     */
    private function effectiveConfigPath(AnalyseCommandOptions $options, ConfigLoader $configLoader): ?string
    {
        if ($options->noConfig) {
            // --no-config means no file was consulted, so report no path rather than a misleading discovered one.
            return null;
        }

        // Prefer the user's explicit --config; otherwise report whatever path discovery would have loaded.
        return $options->configPath ?? $configLoader->resolveConfigPath(null);
    }

    /**
     * Build a zero-finding report for CLI usage and configuration errors.
     *
     * @param AnalyseCommandOptions $options - Validated options supplying the requested paths and config path for context.
     * @param OutputFormat          $format - Format the caller will render this error report in.
     * @param string                $failOn - Fail-on value to record on the report so its threshold field stays accurate.
     * @param string                $message - Human-readable remediation text shown to the user as the diagnostic.
     * @param string                $type - Diagnostic category, either 'usage-error' (default) or 'config-error'.
     *
     * @return AnalysisReport - Report carrying the diagnostic and invalid exit code.
     */
    private function usageReport(
        AnalyseCommandOptions $options,
        OutputFormat $format,
        string $failOn,
        string $message,
        string $type = 'usage-error',
    ): AnalysisReport {
        // No analysis ran, so emit a report with zero files/findings carrying only the diagnostic and INVALID code.
        return new AnalysisReport(
            toolVersion:     Application::VERSION,
            requestedPaths:  $options->paths,
            format:          $format->value,
            failOn:          $failOn,
            filesDiscovered: 0,
            filesParsed:     0,
            ignoredPaths:    [],
            missingPaths:    [],
            diagnostics:     [new RunDiagnostic($type, $message)],
            findings:        [],
            exitCode:        Command::INVALID,
            configPath:      $options->configPath,
        );
    }
}
