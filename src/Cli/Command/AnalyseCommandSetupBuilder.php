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
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turns everything typed after `gruff-php analyse` into one validated setup object, or the first usage or config error the
 * invocation tripped.
 *
 * Every `analyse` run passes through here before a single file is scanned:
 *
 * - parses and range-checks the flags (`--format`, `--fail-on`, `--mutation-budget`, `--include-rule`);
 * - rejects contradictory pairs such as `--no-config` with `--config` in a one-line message;
 * - offers to write a starter config the first time someone runs it in a project without one;
 * - loads the project config and folds any CLI overrides into the final rule selection.
 *
 * A ready result lets the command scan at once. An error result stops it and shows how to fix the command rather than
 * emitting a misleading report.
 */
final readonly class AnalyseCommandSetupBuilder
{
    /**
     * Entry point the `analyse` command calls first: capture the working directory the user launched
     * from, then hand off to the full validation pass. Returns a ready setup or the error they must fix.
     *
     * @param InputInterface          $input              - Symfony console input for the analyse command.
     * @param OutputInterface         $output             - Symfony console output for the optional first-run init prompt.
     * @param SymfonyApplication|null $symfonyApplication - Console application used to dispatch `init`; null skips the first-run config offer.
     *
     * @return AnalyseCommandSetupResult - Ready-to-scan setup when the directory is known, otherwise a plain error result.
     */
    public function build(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
    ): AnalyseCommandSetupResult {
        $launchDirectory = getcwd();

        // The shell cannot name the current directory, which happens when it was deleted mid-session; stop rather than
        // scan an unknown location.
        if ($launchDirectory === false) {
            return AnalyseCommandSetupResult::plainError(
                '<error>Unable to determine current working directory.</error>',
                Command::FAILURE,
            );
        }

        /** @var list<string> $requestedPaths - Path operands exactly as typed, before any root resolution. */
        $requestedPaths = (array) $input->getArgument('paths');
        $projectRoot = self::projectRootFromTargets($launchDirectory, $requestedPaths);

        // The caller named targets in unrelated projects, so there is no single root to report paths against.
        if ($projectRoot === null) {
            return AnalyseCommandSetupResult::plainError(
                '<error>scan targets do not share a filesystem root</error>',
                Command::INVALID,
            );
        }

        return $this->buildSetup($input, $output, $symfonyApplication, $projectRoot);
    }

    /**
     * Pick the directory that every reported path is written relative to.
     *
     * Run `gruff-php analyse .` inside a project and the answer is that directory. Run `gruff-php analyse /srv/checkout`
     * from a home directory, as CI and scripted scans do, and the answer is /srv/checkout, so findings still read
     * `src/Controller/Api.php` rather than an absolute path.
     *
     * @param string       $launchDirectory - Working directory the command was started from.
     * @param list<string> $paths           - Scan targets as typed on the command line; empty means no target was named, so the
     *                                        launch directory is the project.
     *
     * @return string|null - Directory to treat as the project root; null when targets sit under different filesystem roots, such
     *                       as `analyse /srv/api /opt/tools`, leaving no single project to report against.
     */
    private static function projectRootFromTargets(string $launchDirectory, array $paths): ?string
    {
        // No target was named, so the directory the command ran from is the project.
        if ($paths === []) {
            return $launchDirectory;
        }

        $common = null;
        // Each target narrows the answer: the root must be a directory that contains all of them.
        foreach ($paths as $path) {
            $absolute = self::isAbsolutePath($path) ? $path : $launchDirectory . DIRECTORY_SEPARATOR . $path;
            $resolved = realpath($absolute);
            // The caller named a path that does not exist, such as a typo; discovery reports it as missing instead.
            if ($resolved === false) {
                continue;
            }

            // Naming one file means the project is the folder holding it, not the file itself.
            $directory = is_dir($resolved) ? $resolved : dirname($resolved);

            // The first target sets the starting answer; later ones can only widen it.
            if ($common === null) {
                $common = $directory;
                continue;
            }

            while (!self::isSameOrDescendant($directory, $common)) {
                $parent = dirname($common);
                // Walking up hit the filesystem root, so these targets live in unrelated projects.
                if ($parent === $common) {
                    return null;
                }
                $common = $parent;
            }
        }

        // Targets sit inside the launch directory, so it stays the root. Moving the root down to a target's own folder
        // would re-anchor config discovery, ignore patterns, and baseline paths.
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
     * Report whether one directory is another or sits inside it.
     *
     * Comparison is by whole path segment, so a sibling folder such as /work/apidocs is never mistaken for something
     * inside /work/api.
     *
     * @param string $candidate - Directory being tested.
     * @param string $ancestor  - Directory that may contain it.
     *
     * @return bool - True when candidate is the ancestor or sits inside it.
     */
    private static function isSameOrDescendant(string $candidate, string $ancestor): bool
    {
        // Identical paths need no segment work, and this is the common case for a single scan target.
        if ($candidate === $ancestor) {
            return true;
        }

        return str_starts_with($candidate, rtrim($ancestor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /**
     * The validation gauntlet each run walks in the order the user feels it: check every flag, offer a
     * first-run config, load the project config, then fold in any CLI rule overrides. Stops at the
     * first problem with a message the user can act on rather than a half-finished report.
     *
     * @param InputInterface          $input              - Symfony console input for the analyse command.
     * @param OutputInterface         $output             - Console output used for the optional first-run init prompt.
     * @param SymfonyApplication|null $symfonyApplication - Console application used to dispatch `init`; null skips the first-run config offer.
     * @param string                  $projectRoot        - Working directory the run is anchored to, already known to be readable.
     *
     * @return AnalyseCommandSetupResult - Ready setup when every check passes, otherwise the first usage or config error hit.
     */
    private function buildSetup(
        InputInterface $input,
        OutputInterface $output,
        ?SymfonyApplication $symfonyApplication,
        string $projectRoot,
    ): AnalyseCommandSetupResult {
        $options = AnalyseCommandOptions::fromInput($input);
        // The user passed `--no-config` and `--config` together; obeying one would silently ignore the
        // other, so reject the contradictory pair before anything else.
        if ($options->usageError() === '--no-config cannot be combined with --config.') {
            return AnalyseCommandSetupResult::plainError(
                '<error>--no-config cannot be combined with --config.</error>',
                Command::INVALID,
            );
        }

        $formatResult = $this->format($input->getOption('format'));
        // `--format` named something we can't render (e.g. `--format=xml`), so `$formatResult` is the
        // usage-error string; show it instead of starting a scan that can't be printed.
        if (!$formatResult instanceof OutputFormat) {
            return AnalyseCommandSetupResult::plainError($formatResult, Command::INVALID);
        }

        $failThreshold = $this->failThreshold($input->getOption('fail-on'));
        // `--fail-on` wasn't one of the accepted severities, so echo the bad value back rather than
        // guessing which gate the user meant.
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
        // `--mutation-budget` was set to something other than a whole number, so we can't tell how many
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

        $options    = $options->withMutationBudget($mutationBudget);
        $usageError = $options->usageError();
        // Some other flag combination still doesn't hang together (for instance `--changed-only` without
        // the `--diff-vs` it needs, or two mutually exclusive diff selectors); surface the specific reason so the user can fix that one flag.
        if ($usageError !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $usageError),
                $formatResult,
            );
        }

        $registry        = RuleRegistry::defaults();
        $ruleFilterError = $this->ruleFilterError($registry, $options->includeRules, $options->excludeRules);
        // `--include-rule` or `--exclude-rule` named an id no rule owns, which would quietly filter to
        // nothing; name the typo instead of running an empty scan the user would misread as clean.
        if ($ruleFilterError !== null) {
            return AnalyseCommandSetupResult::reportError(
                $this->usageReport($options, $formatResult, $failThreshold->value, $ruleFilterError),
                $formatResult,
            );
        }

        $profileIncludeError = $this->profileIncludeRuleError($registry, $options);
        // The user paired `--profile` with an `--include-rule` from a pillar that profile never scores,
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
        // First run in a project with no config: the init offer already ran to a verdict, so stop and
        // pass its exit code straight back to the shell.
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
        // The project's config couldn't be loaded (missing, malformed, or naming an unknown rule); the
        // config step already turned that failure into an error report, so hand it straight back.
        if ($configResult instanceof AnalysisReport) {
            return AnalyseCommandSetupResult::reportError($configResult, $formatResult);
        }
        $failThreshold  = $this->resolveFailThresholdWithConfig($input, $configResult, $failThreshold);
        $failThresholds = $this->resolveFailThresholds($input, $configResult, $failThreshold);
        $referenceError = $this->newFindingsReferenceError($options, $failThresholds);
        // A new-findings gate is armed but nothing defines "new" yet; tell the user to add a baseline or
        // `--diff-vs <ref>` before the gate can mean anything.
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
        // A profile such as `--profile security` narrows execution to its own pillars; swap that
        // selection in over whatever the config chose.
        if ($profileRuleSelection !== null) {
            $configResult = $configResult->withRuleSelection($profileRuleSelection);
        }
        // The user hand-picked rules with `--include-rule`/`--exclude-rule`; apply those choices on top of
        // the configured selection (include-only narrows to them; exclude drops just those).
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
     * Works out the final rule set once `--include-rule`/`--exclude-rule` are in play, so the run
     * honours exactly what the user chose to focus on or drop.
     *
     * Mirrors the hook command's selection refinement, and the two flags behave differently on purpose:
     *
     * - `--include-rule` means "run only these ids", so it narrows to exactly those rules. `RuleSelection::allows()` ORs
     *   tier, pillar, and rule includes, so inheriting the config's tiers and pillars would widen the focused run.
     * - A bare `--exclude-rule` keeps the configured selection and only drops the named rules, so a config that already
     *   narrowed `selection.rules` is not widened back to the whole rule set.
     *
     * @param RuleSelection $existing     - Selection already resolved from config and profile.
     * @param list<string>  $includeRules - CLI --include-rule ids; when non-empty they focus the run.
     * @param list<string>  $excludeRules - CLI --exclude-rule ids dropped on top of the existing selection.
     *
     * @return RuleSelection - Focused selection for --include-rule, or the existing selection plus excludes.
     */
    private function refinedSelection(RuleSelection $existing, array $includeRules, array $excludeRules): RuleSelection
    {
        // `--include-rule` means "run only these ids", so focus on exactly them and drop the inherited
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
     * Rejects an `--include-rule` whose pillar the active profile never scores, before any file is read.
     *
     * A command like `gruff-php analyse --profile security --include-rule docs.missing-public-phpdoc`
     * fails fast here: without this gate it would print a docs error while the user's grade stayed a
     * security-only 100, which reads as a contradiction. A bare `--exclude-rule` stays a plain narrowing.
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
     * Builds the usage error shown when `--include-rule` ids fall outside a profile's scored pillars.
     *
     * Shared with `ReportCommand` so both commands reject the same incoherent combinations with
     * identical wording, and report can do it before its own init prompt runs.
     *
     * @param RuleRegistry                           $registry            - Resolves rule ids to definitions; ids must already be validated.
     * @param string                                 $profile             - Requested profile name, echoed into the error message.
     * @param list<\GruffPhp\Results\Finding\Pillar> $profileScorePillars - Pillars the profile's composite counts.
     * @param list<string>                           $includeRuleIds      - Requested --include-rule ids.
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
        $profilePillarNames = [];
        // Build the profile wording the user sees in the usage error.
        foreach ($profileScorePillars as $profileScorePillar) {
            $profilePillarNames[] = $profileScorePillar->value;
        }
        $profilePillarsLabel = self::formatPillarList($profilePillarNames);
        $profilePillarIds    = implode('/', $profilePillarNames);

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
                    $profilePillarsLabel,
                    $profile,
                    $profilePillarIds,
                );
            }
        }

        return null;
    }

    /**
     * Joins pillar names into the readable list the usage error reads aloud, so the message names them
     * as "a", "a and b", or "a, b and c" instead of a raw array.
     *
     * @param list<string> $pillarNames - Pillar names in display order; an empty list reads as "no".
     *
     * @return string - Names joined as "a", "a and b", or "a, b and c".
     */
    private static function formatPillarList(array $pillarNames): string
    {
        // Empty profile wording should read plainly if a future profile scores no pillars.
        if ($pillarNames === []) {
            return 'no';
        }

        // A single pillar needs no joining words in the CLI message.
        if (count($pillarNames) === 1) {
            return $pillarNames[0];
        }

        $lastPillarName = array_pop($pillarNames);

        return implode(', ', $pillarNames) . ' and ' . $lastPillarName;
    }

    /**
     * Catches a mistyped `--include-rule`/`--exclude-rule` id before it silently narrows the run to no
     * rules at all, so the user hears about the typo instead of trusting an empty scan.
     *
     * @param RuleRegistry $registry     - Registry whose ids define valid CLI rule filters.
     * @param list<string> $includeRules - Rule ids from `--include-rule`; empty when the user focused nothing.
     * @param list<string> $excludeRules - Rule ids from `--exclude-rule`; empty when the user dropped nothing.
     *
     * @return string|null - Usage error for the first unknown id, or null when every id is registered.
     */
    private function ruleFilterError(RuleRegistry $registry, array $includeRules, array $excludeRules): ?string
    {
        // Check the include and exclude lists the same way, so a typo in either is caught and blamed on
        // the flag that carried it.
        foreach (['--include-rule' => $includeRules, '--exclude-rule' => $excludeRules] as $option => $ruleIds) {
            $unknownRuleIds = $registry->unknownRuleIds($ruleIds);
            // At least one id matches no registered rule; name the first so the user can correct that flag.
            if ($unknownRuleIds !== []) {
                return sprintf('Unknown rule id "%s" for %s.', $unknownRuleIds[0], $option);
            }
        }

        return null;
    }

    /**
     * Turns the raw `--format` value into a renderer choice, or a ready-to-print usage error when it
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

        // Null coalesce turns an unrecognised format into the tagged usage-error string the caller checks for.
        return $format ?? sprintf(
            '<error>USAGE-ERROR Unsupported output format "%s". Use text, json, html, markdown, github, hotspot, or sarif.</error>',
            $rawValue,
        );
    }

    /**
     * Settles which severity fails the run by the documented precedence: an explicit `--fail-on` the
     * user typed wins, otherwise a per-command config threshold, otherwise the built-in default.
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
        // The user typed an explicit `--fail-on`, which outranks any config setting, so keep the parsed
        // threshold exactly as given.
        if ($input->hasParameterOption('--fail-on', true)) {
            return $explicitOrDefault;
        }

        // No explicit flag: a per-command config threshold wins, falling back to the binary default.
        return $config->failThresholdFor('analyse') ?? $explicitOrDefault;
    }

    /**
     * Resolves the richer count-gate that decides the exit code, in precedence order the user controls:
     * an explicit CLI flag, then a `failureConditions:` config block, then the single resolved threshold.
     *
     * Precedence, highest first:
     *
     * - an explicit `--fail-on` on the command line always wins, for backward compatibility;
     * - otherwise an explicit `failureConditions:` block in the project config;
     * - otherwise the already-resolved single threshold, from config `minimumSeverity` or the binary default, desugared
     *   so the gate stays byte-identical to today.
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

        // The user gave an explicit `--fail-on`, which wins outright; if `--fail-on-new` rides along, arm
        // the new-findings sub-gate on top of it.
        if ($hasExplicitFailOn) {
            return FailThresholds::fromFailOn($failThreshold)->withNewFindingsGate(
                $hasExplicitFailOnNew ? FailThresholds::fromFailOn(FailThreshold::Error) : null,
            );
        }

        // Only `--fail-on-new` was given, so let the overall gate pass but still fail when new findings appear.
        if ($hasExplicitFailOnNew) {
            return FailThresholds::fromFailOn(FailThreshold::None)
                ->withNewFindingsGate(FailThresholds::fromFailOn(FailThreshold::Error));
        }

        // No gate flags on the command line, but the project's `failureConditions:` block spells one out,
        // so honour it verbatim.
        if ($configFailureConditions instanceof FailThresholds) {
            return $configFailureConditions;
        }

        // Nothing overrode the gate, so desugar the single resolved threshold into the count-gate form.
        return FailThresholds::fromFailOn($failThreshold);
    }

    /**
     * Guards against a new-findings gate that has nothing to compare against: returns the remediation
     * message when no baseline or `--diff-vs` defines what counts as "new", and null once one does.
     *
     * @param AnalyseCommandOptions $options        - Validated options carrying baseline and diff-vs selections.
     * @param FailThresholds        $failThresholds - Resolved gate, whose new-findings sub-gate may be set.
     *
     * @return string|null - Remediation message, or null when a reference point exists so the gate is well-formed.
     */
    private function newFindingsReferenceError(AnalyseCommandOptions $options, FailThresholds $failThresholds): ?string
    {
        // No new-findings sub-gate is armed, so there's no "new" to measure and nothing to complain about.
        if ($failThresholds->newFindingsGate === null) {
            return null;
        }

        $baselineWillApply = $options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null;
        // A baseline will apply, or `--diff-vs` names a ref to compare against, so "new" has a concrete
        // meaning and the gate is well-formed.
        if ($baselineWillApply || $options->changeScope->diffVs !== null) {
            return null;
        }

        return 'The new-findings gate needs a reference point. Configure --baseline or --diff-vs <ref> before enabling --fail-on-new or failureConditions.newFindings.';
    }

    /**
     * Parses the requested `--fail-on` severity, defaulting to advisory when the flag is absent and
     * handing the raw text straight back when it isn't a severity so the caller can quote it in an error.
     *
     * @param mixed $optionValue - Raw --fail-on console option; a non-string (option absent) defaults to advisory.
     *
     * @return FailThreshold|string - Parsed threshold, or the unsupported raw value the caller reports back.
     */
    private function failThreshold(mixed $optionValue): FailThreshold|string
    {
        $rawValue = is_string($optionValue) ? $optionValue : FailThreshold::Advisory->value;

        // Hand back the matched threshold, or the raw text unchanged so the caller can name the bad value.
        return FailThreshold::fromInput($rawValue) ?? $rawValue;
    }

    /**
     * Parses the optional `--mutation-budget`: how many surviving mutants the user will tolerate before
     * the run is treated as having failed its testing bar.
     *
     * @param mixed $optionValue - Raw --mutation-budget console option; null means the flag was not supplied.
     *
     * @return int|false|null - Non-negative budget, false when the value wasn't a whole number, or null when the flag was omitted.
     */
    private function mutationBudget(mixed $optionValue): int|false|null
    {
        // The `--mutation-budget` flag was never passed, so report "not set" rather than a budget of zero.
        if ($optionValue === null) {
            return null;
        }

        // Accept only unsigned decimal digits for the optional mutation budget flag.
        return is_string($optionValue) && preg_match('/^\d+$/', $optionValue) === 1 ? (int) $optionValue : false;
    }

    /**
     * Loads the project's analysis configuration, or turns a broken config into a printable error report
     * so a bad `.gruff-php.yaml` fails the run cleanly instead of throwing at the user.
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
            // `--no-config` skips the YAML entirely and runs the registry defaults; otherwise load and
            // validate the project file the user relies on.
            $config = $options->noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($options->configPath, $registry);

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
            // A broken or unreadable config is the user's to fix, so surface the reason as a report
            // rather than a stack trace.
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
     * Works out which config path the report should name, so the user can see exactly which file shaped
     * the run - the one they passed, the one auto-discovery found, or none.
     *
     * @param AnalyseCommandOptions $options      - Validated options; noConfig and an explicit configPath drive the result.
     * @param ConfigLoader          $configLoader - Loader used to auto-discover the path when none was given explicitly.
     *
     * @return string|null - Explicit or auto-discovered path, or null when `--no-config` ran with no file at all.
     */
    private function effectiveConfigPath(AnalyseCommandOptions $options, ConfigLoader $configLoader): ?string
    {
        // `--no-config` ran without reading any file, so there's no config path to show in the report.
        if ($options->noConfig) {
            return null;
        }

        // Otherwise report the path the user named, or the one auto-discovery settles on when they named none.
        return $options->configPath ?? $configLoader->resolveConfigPath(null);
    }

    /**
     * Packages a usage or config error as a zero-finding report, so a bad invocation flows through the
     * same renderer and exit code as a real scan and reads consistently in every `--format`.
     *
     * @param AnalyseCommandOptions $options - Validated options supplying the requested paths and config path for context.
     * @param OutputFormat          $format  - Format the caller will render this error report in.
     * @param string                $failOn  - Fail-on value to record on the report so its threshold field stays accurate.
     * @param string                $message - Human-readable remediation text shown to the user as the diagnostic.
     * @param string                $type    - Diagnostic category, either 'usage-error' (default) or 'config-error'.
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
