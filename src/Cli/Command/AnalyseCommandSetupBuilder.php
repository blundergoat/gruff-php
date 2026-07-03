<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSelection;
use GruffPhp\Cli\Application;
use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Output\Reporter\OutputFormat;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Rules\RuleRegistry;
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
            return AnalyseCommandSetupResult::plainError(
                '<error>Unable to determine current working directory.</error>',
                Command::FAILURE,
            );
        }

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
            return AnalyseCommandSetupResult::plainError(
                '<error>--no-config cannot be combined with --config.</error>',
                Command::INVALID,
            );
        }

        $formatResult = $this->format($input->getOption('format'));
        if (!$formatResult instanceof OutputFormat) {
            return AnalyseCommandSetupResult::plainError($formatResult, Command::INVALID);
        }

        $failThreshold = $this->failThreshold($input->getOption('fail-on'));
        if (!$failThreshold instanceof FailThreshold) {
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
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $usageError),
                $formatResult,
            );
        }

        $registry        = RuleRegistry::defaults();
        $ruleFilterError = $this->ruleFilterError($registry, $options->includeRules, $options->excludeRules);
        if ($ruleFilterError !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $ruleFilterError),
                $formatResult,
            );
        }

        $profileIncludeError = $this->profileIncludeRuleError($registry, $options);
        if ($profileIncludeError !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $profileIncludeError),
                $formatResult,
            );
        }

        $promptExitCode = MissingConfigPrompt::maybeOffer(
            input:                   $input,
            output:                  $output,
            symfonyApplication:      $symfonyApplication,
            projectRoot:             $projectRoot,
            explicitConfigPath:      $options->configPath,
            shouldSkipConfig:        $options->noConfig,
            isMachineReadableFormat: $formatResult->isMachineReadable(),
        );
        if ($promptExitCode !== null) {
            return AnalyseCommandSetupResult::exitCode($promptExitCode);
        }

        $options      = $options->withDefaultBaseline($projectRoot);
        $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());
        $configResult = $this->config(
            options:       $options,
            registry:      $registry,
            format:        $formatResult,
            failThreshold: $failThreshold,
            configLoader:  $configLoader,
        );
        if ($configResult instanceof AnalysisReport) {
            return AnalyseCommandSetupResult::reportError($configResult, $formatResult);
        }
        $failThreshold        = $this->resolveFailThresholdWithConfig($input, $configResult, $failThreshold);
        $failThresholds       = $this->resolveFailThresholds($input, $configResult, $failThreshold);
        $referenceError       = $this->newFindingsReferenceError($options, $failThresholds);
        if ($referenceError !== null) {
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
        if ($options->includeRules !== [] || $options->excludeRules !== []) {
            $configResult = $configResult->withRuleSelection(
                $this->refinedSelection($configResult->ruleSelection(), $options->includeRules, $options->excludeRules),
            );
        }

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
     * Resolve the execution-level rule selection for analyse --include-rule/--exclude-rule.
     *
     * Mirrors the hook command's selection refinement: --include-rule means "run
     * only these ids", so it must narrow to exactly those rules because
     * RuleSelection::allows() ORs tier/pillar/rule includes and inheriting the
     * config's tiers/pillars would widen the focused run. A bare --exclude-rule
     * keeps the configured selection and only drops the named rules, so a
     * configured selection.rules narrowing is not widened to the whole rule set.
     *
     * @param RuleSelection $existing - Selection already resolved from config and profile.
     * @param list<string>  $includeRules - CLI --include-rule ids; when non-empty they focus the run.
     * @param list<string>  $excludeRules - CLI --exclude-rule ids dropped on top of the existing selection.
     *
     * @return RuleSelection - Focused selection for --include-rule, or the existing selection plus excludes.
     */
    private function refinedSelection(RuleSelection $existing, array $includeRules, array $excludeRules): RuleSelection
    {
        if ($includeRules !== []) {
            return new RuleSelection(rules: $includeRules, excludeRules: $excludeRules);
        }

        return new RuleSelection(
            tiers:          $existing->tiers,
            pillars:        $existing->pillars,
            rules:          $existing->rules,
            excludePillars: $existing->excludePillars,
            excludeRules:   array_values(array_unique([...$existing->excludeRules, ...$excludeRules])),
        );
    }

    /**
     * Reject --include-rule ids whose pillar the active profile never scores.
     *
     * A command like `gruff-php analyse --profile security --include-rule docs.missing-public-phpdoc`
     * fails fast here: without this gate it would emit a docs error while the user's grade
     * stayed a security-only 100 (ADR-030). Excludes stay a plain narrowing operation.
     *
     * @param RuleRegistry          $registry - Registry resolving rule ids to their definitions.
     * @param AnalyseCommandOptions $options - Validated options carrying the profile and include filters.
     *
     * @return string|null - Usage error naming the first out-of-profile include, or null when compatible.
     */
    private function profileIncludeRuleError(RuleRegistry $registry, AnalyseCommandOptions $options): ?string
    {
        $profileScorePillars = $options->profileScorePillars();
        // The default profile scores every pillar, so there is nothing to reject.
        if ($profileScorePillars === null) {
            return null;
        }

        return self::outOfProfileIncludeError($registry, $options->profile, $profileScorePillars, $options->includeRules);
    }

    /**
     * Build the usage error for --include-rule ids outside a profile's scored pillars.
     *
     * Shared with ReportCommand so both commands reject the same incoherent combinations
     * with identical wording, and report can do it before its init prompt runs.
     *
     * @param RuleRegistry         $registry - Registry resolving rule ids to their definitions; ids must already be validated.
     * @param string               $profile - Requested profile name, echoed into the error message.
     * @param list<\GruffPhp\Results\Finding\Pillar> $profileScorePillars - Pillars the profile's composite counts.
     * @param list<string>         $includeRuleIds - Requested --include-rule ids.
     *
     * @return string|null - usage error naming the first out-of-profile include and both remedies, or null when
     *                     every include belongs to a scored pillar (including the no-includes case)
     */
    public static function outOfProfileIncludeError(
        RuleRegistry $registry,
        string       $profile,
        array        $profileScorePillars,
        array        $includeRuleIds,
    ): ?string {
        $profilePillarNames = self::profilePillarNames($profileScorePillars);
        $profilePillarList  = self::formatPillarList($profilePillarNames);
        $profilePillarIds   = implode('/', $profilePillarNames);

        // Check each requested rule against the pillars the profile's grade actually counts.
        foreach ($includeRuleIds as $ruleId) {
            $pillar = $registry->get($ruleId)->definition()->pillar;
            // An unscored pillar would emit findings the grade ignores; tell the user both ways out.
            if (!in_array($pillar, $profileScorePillars, true)) {
                return sprintf(
                    '--include-rule %s selects a %s rule, but --profile %s executes and scores only %s rules. Drop --profile %s or include only %s rule ids.',
                    $ruleId,
                    $pillar->value,
                    $profile,
                    $profilePillarList,
                    $profile,
                    $profilePillarIds,
                );
            }
        }

        return null;
    }

    /**
     * Convert a profile's scored pillars into their CLI-facing names.
     *
     * @param list<Pillar> $profileScorePillars - Pillars scored by the selected profile.
     *
     * @return list<string> - Pillar values in the profile's declared order.
     */
    private static function profilePillarNames(array $profileScorePillars): array
    {
        return array_map(static fn(Pillar $pillar): string => $pillar->value, $profileScorePillars);
    }

    /**
     * Format a short human-readable pillar list.
     *
     * @param list<string> $pillarNames - Pillar names in display order.
     *
     * @return string - Names joined as "a", "a and b", or "a, b and c".
     */
    private static function formatPillarList(array $pillarNames): string
    {
        if ($pillarNames === []) {
            return 'no';
        }

        if (count($pillarNames) === 1) {
            return $pillarNames[0];
        }

        $lastPillarName = array_pop($pillarNames);

        return implode(', ', $pillarNames) . ' and ' . $lastPillarName;
    }

    /**
     * Validate execution-level rule filters before they can narrow the run to zero rules.
     *
     * @param RuleRegistry $registry - Registry whose ids define valid CLI rule filters.
     * @param list<string> $includeRules - Rule ids from --include-rule.
     * @param list<string> $excludeRules - Rule ids from --exclude-rule.
     *
     * @return string|null - Usage error for the first unknown id, or null when every id is registered.
     */
    private function ruleFilterError(RuleRegistry $registry, array $includeRules, array $excludeRules): ?string
    {
        foreach (['--include-rule' => $includeRules, '--exclude-rule' => $excludeRules] as $option => $ruleIds) {
            $unknownRuleIds = $registry->unknownRuleIds($ruleIds);
            if ($unknownRuleIds !== []) {
                return sprintf('Unknown rule id "%s" for %s.', $unknownRuleIds[0], $option);
            }
        }

        return null;
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
        $hasExplicitFailOn       = $input->hasParameterOption('--fail-on', true);
        $hasExplicitFailOnNew    = $input->hasParameterOption('--fail-on-new', true);

        if ($hasExplicitFailOn) {
            return FailThresholds::fromFailOn($failThreshold)->withNewFindingsGate(
                $hasExplicitFailOnNew ? FailThresholds::fromFailOn(FailThreshold::Error) : null,
            );
        }

        if ($hasExplicitFailOnNew) {
            return FailThresholds::fromFailOn(FailThreshold::None)
                ->withNewFindingsGate(FailThresholds::fromFailOn(FailThreshold::Error));
        }

        if ($configFailureConditions instanceof FailThresholds) {
            return $configFailureConditions;
        }

        return FailThresholds::fromFailOn($failThreshold);
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
            return null;
        }

        $baselineWillApply = $options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null;
        if ($baselineWillApply || $options->diffVs !== null) {
            return null;
        }

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
            return $options->noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($options->configPath, $registry);
        } catch (ConfigException $exception) {
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
            return null;
        }

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
