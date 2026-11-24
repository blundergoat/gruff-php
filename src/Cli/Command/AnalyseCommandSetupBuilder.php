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
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resolves scan targets, validates options, and loads configuration before analysis starts.
 *
 * May offer to initialize missing project configuration, then applies profile and CLI rule selections.
 * Returns a ready setup, the first detected error, or an exit code when initialization fails.
 */
final readonly class AnalyseCommandSetupBuilder
{
    /**
     * Resolve the launch directory and project root, then validate the run configuration.
     * Re-anchor target and baseline paths before preparing the setup.
     *
     * @param InputInterface          $input              - Symfony console input for the analyse command.
     * @param OutputInterface         $output             - Symfony console output for the optional first-run init prompt.
     * @param SymfonyApplication|null $symfonyApplication - Console application used to dispatch init; null skips the first-run config offer.
     *
     * @return AnalyseCommandSetupResult - Ready setup, a setup error, or an early exit after failed configuration initialization.
     */
    public function build(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
    ): AnalyseCommandSetupResult {
        $launchDirectory = getcwd();

        // The launch directory is unavailable, so stop before resolving targets against an unknown location.

        if ($launchDirectory === false) {
            return AnalyseCommandSetupResult::plainError(
                '<error>Unable to determine current working directory.</error>',
                Command::FAILURE,
            );
        }

        /** @var list<string> $requestedPaths - Path operands exactly as typed, before any root resolution. */
        $requestedPaths = (array) $input->getArgument('paths');
        $projectRoot    = self::projectRootFromTargets($launchDirectory, $requestedPaths);

        // Targets without a common filesystem ancestor cannot share the report's project root.
        if ($projectRoot === null) {
            return AnalyseCommandSetupResult::plainError(
                '<error>scan targets do not share a filesystem root</error>',
                Command::INVALID,
            );
        }

        $input->setArgument('paths', self::targetsForRoot($launchDirectory, $projectRoot, $requestedPaths));

        // A baseline path means what the user typed from the launch directory, as the targets do.
        foreach (['baseline', 'generate-baseline', 'migrate-baseline'] as $baselineOption) {
            $typedPath = $input->hasOption($baselineOption) ? $input->getOption($baselineOption) : null;

            // A bare --baseline names the default file at the project root, so only a typed path is rewritten.
            if (is_string($typedPath) && $typedPath !== '') {
                $input->setOption($baselineOption, self::pathForRoot($launchDirectory, $projectRoot, $typedPath));
            }
        }

        return $this->buildSetup(
            $input,
            $output,
            $symfonyApplication,
            $projectRoot,
            self::targetOutsideLaunchDirectory($launchDirectory, $requestedPaths),
        );
    }

    /**
     * Find the first resolvable target outside the launch directory when multiple targets were requested.
     *
     * The caller uses it to reject multi-target scans not contained by the launch directory.
     * Unresolved targets are left for discovery to diagnose.
     *
     * @param string       $launchDirectory - Working directory the command was started from.
     * @param list<string> $paths           - Scan targets as typed on the command line.
     *
     * @return string|null - First resolvable outside target in a multi-target request, or null when none is found.
     */
    private static function targetOutsideLaunchDirectory(string $launchDirectory, array $paths): ?string
    {

        // A single target can establish its own project root without comparing it with another operand.
        if (count($paths) < 2) {
            return null;
        }

        // Resolve each operand from the launch directory before deciding whether they belong to one scan.
        foreach ($paths as $path) {
            $absolute = self::isAbsolutePath($path) ? $path : $launchDirectory . DIRECTORY_SEPARATOR . $path;
            $resolved = realpath($absolute);
            // Leave unresolved targets for discovery rather than rejecting them as outside the launch directory.
            if ($resolved === false) {
                continue;
            }

            // Multi-target scans require launch-directory containment, even when the targets share another ancestor.
            if (!self::isSameOrDescendant($resolved, $launchDirectory)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Anchor relative scan targets to the launch directory when the selected project root differs.
     * This preserves their meaning when discovery resolves operands against the project root.
     *
     * @param string       $launchDirectory - Working directory the command was started from.
     * @param string       $projectRoot     - Root chosen by projectRootFromTargets().
     * @param list<string> $paths           - Scan targets as typed on the command line.
     *
     * @return list<string> - Targets unchanged when both directories match; otherwise relative paths anchored to the launch directory.
     */
    public static function targetsForRoot(string $launchDirectory, string $projectRoot, array $paths): array
    {

        // Paths the caller supplied already refer to the scan root when the command starts there.
        if ($launchDirectory === $projectRoot) {
            return $paths;
        }

        return array_map(
            static fn(string $path): string => self::isAbsolutePath($path) ? $path : $launchDirectory . DIRECTORY_SEPARATOR . $path,
            $paths,
        );
    }

    /**
     * Preserve a CLI path's launch-directory meaning when the selected project root differs.
     * Use a root-relative path where possible, or an absolute path when the target lies outside the project.
     *
     * @param string $launchDirectory - Working directory the command was started from.
     * @param string $projectRoot     - Root chosen by projectRootFromTargets().
     * @param string $path            - The path as typed on the command line.
     *
     * @return string - Original path when no rewrite is needed; otherwise a root-relative or absolute path to the same target.
     */
    public static function pathForRoot(string $launchDirectory, string $projectRoot, string $path): string
    {

        // Rewrite relative paths only when the scan root differs from where the command started.
        if ($launchDirectory === $projectRoot || PathHelper::isAbsolute($path)) {
            return $path;
        }

        $target = PathHelper::canonical(PathHelper::resolveAgainst($launchDirectory, $path));

        return PathHelper::relativeToRoot($target, $projectRoot) ?? $target;
    }

    /**
     * Choose a project root from the common ancestor of targets that realpath() can resolve.
     *
     * Keep the launch directory when all resolved targets lie within it or no target resolves.
     * For outside targets, use their common directory ancestor; a file contributes its containing directory.
     *
     * @param string       $launchDirectory - Working directory the command was started from.
     * @param list<string> $paths           - CLI scan targets; empty or wholly unresolved lists use the launch directory.
     *
     * @return string|null - Selected directory, or null when resolved targets have no common filesystem ancestor.
     */
    public static function projectRootFromTargets(string $launchDirectory, array $paths): ?string
    {

        // With no operands, the launch directory is the project the caller asked to scan.
        if ($paths === []) {
            return $launchDirectory;
        }

        $common = null;

        // Use existing targets to find the root that owns the scan and its config.
        foreach ($paths as $path) {
            $absolute = self::isAbsolutePath($path) ? $path : $launchDirectory . DIRECTORY_SEPARATOR . $path;
            $resolved = realpath($absolute);

            // A missing path cannot choose a project root. The scan reports that operand separately.
            if ($resolved === false) {
                continue;
            }


            $directory = is_dir($resolved) ? $resolved : dirname($resolved);

            // The first target sets the starting answer; later ones can only widen it.
            if ($common === null) {
                $common = $directory;
                continue;
            }

            while (!self::isSameOrDescendant($directory, $common)) {
                $parent = dirname($common);
                // Reaching a filesystem root without containing this target means no common ancestor exists.
                if ($parent === $common) {
                    return null;
                }
                $common = $parent;
            }
        }

        // Keep the launch directory when targets stay inside it or none resolve, so config, ignores, and baselines retain their anchor.

        if ($common === null || self::isSameOrDescendant($common, $launchDirectory)) {
            return $launchDirectory;
        }

        return $common;
    }

    /**
     * Report whether a path already names a location on its own, so it needs no launch-directory prefix.
     *
     * @param string $path - Path as typed on the command line; empty is treated as relative.
     *
     * @return bool - True for a POSIX path such as /srv/app or a Windows path such as C:\app.
     */
    private static function isAbsolutePath(string $path): bool
    {
        // A Windows absolute path is a drive letter, a colon, then either slash: C:\app or C:/app.
        return $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * Compares directory path strings for equality or a whole-segment ancestor prefix.
     * The separator boundary keeps /work/apidocs outside the /work/api prefix.
     *
     * @param string $candidate - Directory being tested.
     * @param string $ancestor  - Directory that may contain it.
     *
     * @return bool - True when the paths match or candidate has ancestor as a whole-segment prefix.
     */
    private static function isSameOrDescendant(string $candidate, string $ancestor): bool
    {

        // A path can name the project root itself.
        if ($candidate === $ancestor) {
            return true;
        }

        return str_starts_with($candidate, rtrim($ancestor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /**
     * Validate options, offer first-run configuration, and build the settings used for analysis.
     * Return the first detected setup error, or an early exit when configuration initialization fails.
     *
     * @param InputInterface          $input              - Symfony console input for the analyse command.
     * @param OutputInterface         $output             - Console output used for the optional first-run init prompt.
     * @param SymfonyApplication|null $symfonyApplication - Console application used to dispatch init; null skips the first-run config offer.
     * @param string                  $projectRoot        - Root selected for target resolution and configuration discovery.
     * @param string|null             $outsideTarget      - First of several targets outside the launch directory, or null.
     *
     * @return AnalyseCommandSetupResult - Ready setup, the first detected setup error, or an early initialization exit.
     */
    private function buildSetup(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
        string $projectRoot,
        ?string $outsideTarget,
    ): AnalyseCommandSetupResult {
        $options = AnalyseCommandOptions::fromInput($input);
        // The user passed --no-config and --config together; obeying one would silently ignore the
        // other, so reject the contradictory pair before anything else.
        if ($options->usageError() === '--no-config cannot be combined with --config.') {
            return AnalyseCommandSetupResult::plainError(
                '<error>--no-config cannot be combined with --config.</error>',
                Command::INVALID,
            );
        }

        $formatResult = $this->format($input->getOption('format'));
        // --format named an unsupported renderer, such as --format=xml, so the resolved value is the
        // usage-error string; show it instead of starting a scan that can't be printed.
        if (!$formatResult instanceof OutputFormat) {
            return AnalyseCommandSetupResult::plainError($formatResult, Command::INVALID);
        }

        $failThreshold = $this->failThreshold($input->getOption('fail-on'));
        // --fail-on was not an accepted severity, so echo the bad value instead of guessing which gate the user meant.
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
        // --mutation-budget was set to something other than a whole number, so we cannot tell how many
        // surviving mutants the user is willing to tolerate.
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

        // A multi-target request escaped the launch directory; report this policy error through the selected format.

        if ($outsideTarget !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport(
                    $options,
                    $formatResult,
                    $failThreshold->value,
                    sprintf(
                        'Target "%s" is outside the launch directory; gruff-php analyses several targets only from a directory that contains them all.',
                        $outsideTarget,
                    ),
                    'target-error',
                ),
                $formatResult,
            );
        }

        $options    = $options->withMutationBudget($mutationBudget);
        $usageError = $options->usageError();
        // Another flag combination is invalid, such as --changed-only without --diff-vs or two mutually exclusive
        // diff selectors; surface the specific reason so the user can fix the offending flag.
        if ($usageError !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $usageError),
                $formatResult,
            );
        }

        $registry        = RuleRegistry::defaults();
        $ruleFilterError = $this->ruleFilterError($registry, $options);
        // Unknown includes can omit intended checks; unknown excludes leave intended checks enabled.

        if ($ruleFilterError !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $ruleFilterError),
                $formatResult,
            );
        }

        $profileIncludeError = $this->profileIncludeRuleError($registry, $options);
        // The user paired --profile with an --include-rule from a pillar that profile never scores,
        // so the finding could never move the grade; explain both ways out.
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
        // Initialization failed after the first-run offer, so return its exit code without starting analysis.

        if ($promptExitCode !== null) {
            return AnalyseCommandSetupResult::exitCode($promptExitCode);
        }

        $options      = $options->withDefaultBaseline($projectRoot);
        $configLoader = new ConfigLoader($projectRoot, shouldResolveFromLaunchDir: true);
        $configResult = $this->config(
            options:       $options,
            registry:      $registry,
            format:        $formatResult,
            failThreshold: $failThreshold,
            configLoader:  $configLoader,
        );
        // The project's config couldn't be loaded (missing, malformed, or naming an unknown rule); the
        // config step already turned that failure into an error report, so hand it straight back.
        if ($configResult instanceof AnalysisReport) {
            return AnalyseCommandSetupResult::reportError($configResult, $formatResult);
        }
        $failThreshold  = $this->resolveFailThresholdWithConfig($input, $configResult, $failThreshold);
        $failThresholds = $this->resolveFailThresholds($input, $configResult, $failThreshold);
        $referenceError = $this->newFindingsReferenceError($options, $failThresholds);
        // A new-findings gate is armed but nothing defines "new" yet; tell the user to add a baseline or
        // --diff-vs <ref> before the gate can mean anything.
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
        // --profile security narrows execution to its own pillars, overriding the config's rule selection.
        if ($profileRuleSelection !== null) {
            $configResult = $configResult->withRuleSelection($profileRuleSelection);
        }
        // Explicit includes replace the selection with those ids; without includes, CLI excludes extend the existing exclusions.

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
     * Apply CLI rule filters to a selection already resolved from configuration and profile.
     * Explicit includes replace that selection, including its exclusions; otherwise merge only the CLI exclusions.
     *
     * @param RuleSelection $existing     - Selection already resolved from config and profile.
     * @param list<string>  $includeRules - CLI --include-rule ids; when non-empty they focus the run.
     * @param list<string>  $excludeRules - CLI exclusions applied to explicit includes or merged with existing exclusions.
     *
     * @return RuleSelection - Focused selection for --include-rule, or the existing selection plus excludes.
     */
    private function refinedSelection(RuleSelection $existing, array $includeRules, array $excludeRules): RuleSelection
    {
        // --include-rule means "run only these ids", so focus on exactly them and drop the inherited
        // tiers and pillars that would otherwise widen the run back out.
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
     * Reject explicit includes from pillars the selected profile does not score.
     * This prevents requested rules from producing findings that cannot affect the profile's grade.
     *
     * @param RuleRegistry          $registry - Registry resolving rule ids to their definitions.
     * @param AnalyseCommandOptions $options  - Validated options carrying the profile and include filters.
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
     * Rejects the first included rule outside the profile's scored pillars, using wording shared with ReportCommand.
     *
     * @param RuleRegistry                           $registry            - Resolves rule ids to definitions; ids must already be validated.
     * @param string                                 $profile             - Requested profile name, echoed into the error message.
     * @param list<\GruffPhp\Results\Finding\Pillar> $profileScorePillars - Pillars the profile's composite counts.
     * @param list<string>                           $includeRuleIds      - Requested --include-rule ids.
     *
     * @return string|null - Error with both remedies for the first incompatible include; null when all includes match or the list is empty.
     */
    public static function outOfProfileIncludeError(
        RuleRegistry $registry,
        string       $profile,
        array        $profileScorePillars,
        array        $includeRuleIds,
    ): ?string {
        $profilePillarNames = [];

        // Seed the score with the pillars that belong to the selected profile.
        foreach ($profileScorePillars as $profileScorePillar) {
            $profilePillarNames[] = $profileScorePillar->value;
        }
        $profilePillarsLabel = self::formatPillarList($profilePillarNames);
        $profilePillarIds    = implode('/', $profilePillarNames);


        // An explicit rule can add a pillar outside the profile's default score.
        foreach ($includeRuleIds as $ruleId) {
            $pillar = $registry->get($ruleId)->definition()->pillar;
            // An unscored pillar would emit findings the grade ignores; tell the user both ways out.
            if (!in_array($pillar, $profileScorePillars, true)) {
                return sprintf(
                    '--include-rule %s selects a %s rule, but --profile %s executes and scores only %s rules. Drop --profile %s or include only %s rule ids.',
                    $ruleId,
                    $pillar->value,
                    $profile,
                    $profilePillarsLabel,
                    $profile,
                    $profilePillarIds,
                );
            }
        }

        return null;
    }

    /**
     * Format pillar names as a readable list for the usage error.
     *
     * @param list<string> $pillarNames - Pillar names in display order; an empty list reads as "no".
     *
     * @return string - Names joined as "a", "a and b", or "a, b and c".
     */
    private static function formatPillarList(array $pillarNames): string
    {

        // Show that no pillar is selected rather than rendering a blank answer.
        if ($pillarNames === []) {
            return 'no';
        }


        // A single pillar needs no list punctuation in the selection prompt.
        if (count($pillarNames) === 1) {
            return $pillarNames[0];
        }

        $lastPillarName = array_pop($pillarNames);

        return implode(', ', $pillarNames) . ' and ' . $lastPillarName;
    }

    /**
     * Validate CLI include and exclude ids against the registry before rule selection.
     * Return an error for the first unknown id so a misspelled filter is not silently ignored.
     *
     * @param RuleRegistry          $registry - Registry whose ids define valid CLI rule filters.
     * @param AnalyseCommandOptions $options  - Execution and display rule selectors supplied by the caller.
     *
     * @return string|null - Usage error for the first unknown id, or null when every id is registered.
     */
    private function ruleFilterError(RuleRegistry $registry, AnalyseCommandOptions $options): ?string
    {


        $ruleSelectors = [
            '--include-rule' => $options->includeRules,
            '--exclude-rule' => $options->excludeRules,
            '--show-rule' => $options->presentation->showRules,
            '--hide-rule' => $options->presentation->hideRules,
        ];
        // Validate execution and display flags against the same catalogue so unknown rule IDs cannot disappear silently.
        foreach ($ruleSelectors as $option => $ruleIds) {
            $unknownRuleIds = $registry->unknownRuleIds($ruleIds);

            // Name the first invalid entry and its flag so the caller can correct the invocation.
            if ($unknownRuleIds !== []) {
                return sprintf('Unknown rule id "%s" for %s.', $unknownRuleIds[0], $option);
            }
        }

        return null;
    }

    /**
     * Turns the raw --format value into a renderer choice, or a ready-to-print usage error when it
     * names a format gruff can't produce. Runs before any scan so the user never waits for a report
     * that can't be rendered.
     *
     * @param mixed $optionValue - Raw --format console option; a non-string (option absent) defaults to text.
     *
     * @return OutputFormat|string - Parsed format, or a formatted usage error string when the value is unsupported.
     */
    private function format(mixed $optionValue): OutputFormat|string
    {
        $rawValue = is_string($optionValue) ? $optionValue : OutputFormat::Text->value;
        $format   = OutputFormat::fromInput($rawValue);


        return $format ?? sprintf(
            '<error>USAGE-ERROR Unsupported output format "%s". Use text, json, html, markdown, github, hotspot, or sarif.</error>',
            $rawValue,
        );
    }

    /**
     * Settles which severity fails the run: an explicit --fail-on value wins, followed by a per-command
     * config threshold and then the built-in default.
     *
     * @param InputInterface $input             - Console input used for explicit-flag detection.
     * @param AnalysisConfig $config            - Loaded analysis config supplying per-command overrides.
     * @param FailThreshold  $explicitOrDefault - Already-parsed CLI value; binary default when --fail-on omitted.
     *
     * @return FailThreshold - Resolved threshold honouring CLI > config > binary precedence.
     */
    private function resolveFailThresholdWithConfig(
        InputInterface $input,
        AnalysisConfig $config,
        FailThreshold $explicitOrDefault,
    ): FailThreshold {
        // An explicit --fail-on outranks the config threshold, so keep the parsed CLI value.
        if ($input->hasParameterOption('--fail-on', true)) {
            return $explicitOrDefault;
        }

        // No explicit flag: a per-command config threshold wins, falling back to the binary default.
        return $config->failThresholdFor('analyse') ?? $explicitOrDefault;
    }

    /**
     * Resolve exit-code gates from explicit CLI flags, configuration, or the resolved severity threshold.
     *
     * An explicit --fail-on overrides configured gates; --fail-on-new adds an error-only new-findings gate.
     * With --fail-on-new alone, disable the overall gate; without either flag, prefer configured failure conditions.
     *
     * @param InputInterface $input         - Console input used for explicit-flag detection.
     * @param AnalysisConfig $config        - Loaded config supplying the optional failureConditions block.
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

        // Explicit --fail-on replaces configured gates; --fail-on-new adds an error-only new-findings gate.
        if ($hasExplicitFailOn) {
            return FailThresholds::fromFailOn($failThreshold)->withNewFindingsGate(
                $hasExplicitFailOnNew ? FailThresholds::fromFailOn(FailThreshold::Error) : null,
            );
        }

        // With only --fail-on-new, disable the overall gate and trip the new-findings sub-gate only on new errors.
        if ($hasExplicitFailOnNew) {
            return FailThresholds::fromFailOn(FailThreshold::None)
                ->withNewFindingsGate(FailThresholds::fromFailOn(FailThreshold::Error));
        }

        // Without CLI gate flags, use the project's configured failure conditions unchanged.
        if ($configFailureConditions instanceof FailThresholds) {
            return $configFailureConditions;
        }


        return FailThresholds::fromFailOn($failThreshold);
    }

    /**
     * Require a baseline or diff reference only when a new-findings gate is enabled.
     * Baseline generation alone does not supply a reference for that gate.
     *
     * @param AnalyseCommandOptions $options        - Validated options carrying baseline and diff-vs selections.
     * @param FailThresholds        $failThresholds - Resolved gate, whose new-findings sub-gate may be set.
     *
     * @return string|null - Remediation message when an enabled gate lacks a reference; null otherwise.
     */
    private function newFindingsReferenceError(AnalyseCommandOptions $options, FailThresholds $failThresholds): ?string
    {
        // No new-findings sub-gate is armed, so there's no "new" to measure and nothing to complain about.
        if ($failThresholds->newFindingsGate === null) {
            return null;
        }

        $baselineWillApply = $options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null;
        // A selected baseline counts only outside generation mode; a diff reference works in either mode.

        if ($baselineWillApply || $options->changeScope->diffVs !== null) {
            return null;
        }

        return 'The new-findings gate needs a reference point. Configure --baseline or --diff-vs <ref> before enabling --fail-on-new or failureConditions.newFindings.';
    }

    /**
     * Parses the requested --fail-on severity, defaulting to advisory when the flag is absent and
     * handing the raw text straight back when it isn't a severity so the caller can quote it in an error.
     *
     * @param mixed $optionValue - Raw --fail-on console option; a non-string (option absent) defaults to advisory.
     *
     * @return FailThreshold|string - Parsed threshold, or the unsupported raw value the caller reports back.
     */
    private function failThreshold(mixed $optionValue): FailThreshold|string
    {
        $rawValue = is_string($optionValue) ? $optionValue : FailThreshold::Advisory->value;


        return FailThreshold::fromInput($rawValue) ?? $rawValue;
    }

    /**
     * Parses the optional --mutation-budget: how many surviving mutants the user will tolerate before
     * the run is treated as having failed its testing bar.
     *
     * @param mixed $optionValue - Raw --mutation-budget console option; null means the flag was not supplied.
     *
     * @return int|false|null - Non-negative parsed budget, false when validation rejects the option, or null when omitted.
     */
    private function mutationBudget(mixed $optionValue): int|false|null
    {
        // The --mutation-budget flag was never passed, so report "not set" rather than a budget of zero.
        if ($optionValue === null) {
            return null;
        }

        // Accept only unsigned decimal digits for the optional mutation budget flag.
        return is_string($optionValue) && preg_match('/^\d+$/', $optionValue) === 1 ? (int) $optionValue : false;
    }

    /**
     * Loads the project's analysis configuration, or turns a broken config into a printable error report
     * so a bad .gruff-php.yaml fails the run cleanly instead of throwing at the user.
     *
     * @param AnalyseCommandOptions $options       - Validated options; its noConfig/configPath select the load path.
     * @param RuleRegistry          $registry      - Default rule set used to seed config when loading is disabled.
     * @param OutputFormat          $format        - Output format stamped onto the error report when loading fails.
     * @param FailThreshold         $failThreshold - Threshold echoed into the error report so its failOn stays accurate.
     * @param ConfigLoader          $configLoader  - Loader that reads and validates the on-disk config file.
     *
     * @return AnalysisConfig|AnalysisReport - Loaded config, or a formatted config-error report when the file can't be used.
     */
    private function config(
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        OutputFormat $format,
        FailThreshold $failThreshold,
        ConfigLoader $configLoader,
    ): AnalysisConfig|AnalysisReport {
        try {
            // --no-config uses registry defaults; otherwise load and validate the project's configuration.
            $config = $options->noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($options->configPath, $registry);

            // An explicit command-line scan budget takes precedence over the target project's config.
            if ($options->deepScanBudgetOverride !== null) {
                $budget = $options->deepScanBudgetOverride;
                $config = $config->withDeepScanBudget(
                    $budget['enabled'],
                    $budget['maxLines'],
                    $budget['maxBytes'],
                    'cli',
                );
            }

            return $config;
        } catch (ConfigException $exception) {
            // A config with invalid YAML or an unreadable path cannot choose the scan settings. Report the error from the loader.
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
     * Return the typed config path or ask the loader to discover one for reporting.
     * Return null when configuration loading is disabled or discovery finds no config file.
     *
     * @param AnalyseCommandOptions $options      - Validated options; noConfig and an explicit configPath drive the result.
     * @param ConfigLoader          $configLoader - Loader used to auto-discover the path when none was given explicitly.
     *
     * @return string|null - Explicit or discovered path; null when configuration is disabled or no file is found.
     */
    private function effectiveConfigPath(AnalyseCommandOptions $options, ConfigLoader $configLoader): ?string
    {
        // --no-config ran without reading any file, so there's no config path to show in the report.
        if ($options->noConfig) {
            return null;
        }


        return $options->configPath ?? $configLoader->resolveConfigPath(null);
    }

    /**
     * Create a report with no findings and one diagnostic for a setup error.
     * The caller renders it in the selected format; its exit code is always Command::INVALID.
     *
     * @param AnalyseCommandOptions $options - Parsed options supplying requested paths and the explicit config path.
     * @param OutputFormat          $format  - Format the caller will render this error report in.
     * @param string                $failOn  - Fail-on value to record on the report so its threshold field stays accurate.
     * @param string                $message - Human-readable remediation text shown to the user as the diagnostic.
     * @param string                $type    - Diagnostic category: 'usage-error' (default), 'config-error' or 'target-error'.
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
