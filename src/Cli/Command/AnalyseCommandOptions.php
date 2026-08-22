<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Results\Baseline\BaselineApplicationOptions;
use GruffPhp\Results\Baseline\BaselineStore;
use GruffPhp\Engine\Config\RuleSelection;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Mutation\MutationAnalysisOptions;
use GruffPhp\Output\Reporter\FindingDisplayFilter;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Immutable bag of every validated flag a user typed after `gruff-php analyse`.
 *
 * `fromInput()` normalises the raw console options into this object once, capturing a usage error
 * if any flag is rejected so the command can reject a contradictory combination (say `--no-config`
 * next to `--config`) with a single clear line instead of starting a scan that would mislead.
 * The `with*` methods hand back adjusted copies as later stages fill in the mutation budget or
 * the project's default baseline, and the accessor methods give the run its rule selection,
 * scored pillars, and the display filter the reporter uses. Reach for this whenever you need to
 * know what the user actually asked analyse to do; the `report` command reuses the static
 * profile checks so a forwarded `--profile` is rejected the same way before any subprocess runs.
 */
final readonly class AnalyseCommandOptions
{
    /**
     * The `--profile` value that leaves the user's configured rule selection untouched - a normal run.
     */
    private const PROFILE_DEFAULT = 'default';

    /**
     * The `--profile=security` value that narrows the run to only the security and sensitive-data rules.
     */
    private const PROFILE_SECURITY = 'security';

    /**
     * Holds the fully parsed, validated option set; callers build it through `fromInput()` rather
     * than by hand so the usage-error capture and flag normalisation all live in one place.
     *
     * @param list<string>                                            $paths                  - Files or directories the user named; empty means none were given, so the whole project is scanned.
     * @param bool                                                    $shouldIncludeIgnored   - Set by `--include-ignored`; when true, files the ignore rules would skip are scanned anyway.
     * @param string|null                                             $configPath             - Explicit `--config` path, or null when none was given so the default `.gruff-php.yaml` is discovered.
     * @param bool                                                    $noConfig               - Set by `--no-config`; when true, no YAML config is loaded and registry defaults run instead.
     * @param bool                                                    $noCache                - Set by `--no-cache`; when true, the run ignores the on-disk cache and re-analyses every file.
     * @param string                                                  $profile                - The `--profile` value ('default' or 'security') deciding which rules run.
     * @param MutationAnalysisOptions                                 $mutation               - Parsed `--infection-*` and `--mutation-*` options driving mutation analysis.
     * @param string|null                                             $diffMode               - Requested `--diff` mode, or null when `--diff` was not supplied so the full tree is analysed.
     * @param string|null                                             $since                  - Git base ref from `--since`, or null when the user did not scope the run to changes since a ref.
     * @param string|null                                             $changedRanges          - Explicit line ranges from `--changed-ranges`, or null when the user named none.
     * @param string                                                  $changedScope           - How ranges expand to findings via `--changed-scope`: symbol, hunk, or file.
     * @param string|null                                             $diffVs                 - Comparison ref from `--diff-vs`, or null when the user is not comparing against another ref.
     * @param bool                                                    $isChangedOnly          - Set by `--changed-only`; when true, only files that changed versus `--diff-vs` are reported.
     * @param string|null                                             $historyFile            - Trend-history file from `--history-file`, or null when the user is not recording a trend.
     * @param bool                                                    $noBaseline             - Set by `--no-baseline`; when true, no baseline loads so previously accepted debt surfaces again.
     * @param BaselineApplicationOptions                              $baseline               - Parsed `--baseline` and `--generate-baseline` options.
     * @param string                                                  $reportEditorLink       - Editor-link style from `--report-editor-link`: none, vscode, or phpstorm.
     * @param bool                                                    $isReportInteractive    - Set by `--report-interactive`; when true, the report offers interactive prompts.
     * @param string|null                                             $pathsRelativeTo        - Base path from `--paths-relative-to` for display, or null when paths are shown as discovered.
     * @param string|null                                             $minSeverity            - Severity floor from `--min-severity`, or null when every severity is shown.
     * @param list<string>                                            $includePillars         - Pillars from `--include-pillar`; empty means no include filter, so every pillar shows.
     * @param list<string>                                            $excludePillars         - Pillars from `--exclude-pillar`; empty means nothing is filtered out of the report.
     * @param list<string>                                            $includeRules           - Rule IDs from `--include-rule` the run executes exclusively; empty means every configured rule runs. Enforced at execution level, like the hook command.
     * @param list<string>                                            $excludeRules           - Rule IDs from `--exclude-rule` that do not run at all; empty means nothing is excluded.
     * @param array{enabled: bool, maxLines: int, maxBytes: int}|null $deepScanBudgetOverride - Parsed CLI budget override, or null when config/defaults govern.
     * @param string|null                                             $optionError            - A usage error found while parsing (the last failing check wins), or null when every flag was accepted.
     */
    public function __construct(
        public array                      $paths,
        public bool                       $shouldIncludeIgnored,
        public ?string                    $configPath,
        public bool                       $noConfig,
        public bool                       $noCache,
        public string                     $profile,
        public MutationAnalysisOptions    $mutation,
        public ?string                    $diffMode,
        public ?string                    $since,
        public ?string                    $changedRanges,
        public string                     $changedScope,
        public ?string                    $diffVs,
        public bool                       $isChangedOnly,
        public ?string                    $historyFile,
        public bool                       $noBaseline,
        public BaselineApplicationOptions $baseline,
        public string                     $reportEditorLink,
        public bool                       $isReportInteractive,
        public ?string                    $pathsRelativeTo,
        public ?string                    $minSeverity,
        /** @var list<string> */
        public array                      $includePillars,
        /** @var list<string> */
        public array                      $excludePillars,
        /** @var list<string> */
        public array                      $includeRules,
        /** @var list<string> */
        public array                      $excludeRules,
        /** @var array{enabled: bool, maxLines: int, maxBytes: int}|null */
        public ?array                     $deepScanBudgetOverride = null,
        private ?string                   $optionError = null,
    ) {
    }

    /**
     * Turns everything the user typed after `gruff-php analyse` into one validated options bag,
     * remembering a rejected flag so the command can stop before running a misleading scan.
     * This is the single entry point; call it once per invocation rather than reading raw input.
     *
     * @param InputInterface $input - Console input to normalize into analyse options.
     *
     * @return self - fully populated options bag whose optionError carries a usage error, if any.
     */
    public static function fromInput(InputInterface $input): self
    {
        /** @var list<string> $paths The command definition declares a variadic paths argument. */
        $paths               = $input->getArgument('paths');
        $filePaths           = self::repeatedStringOption($input, 'file');
        $configPath          = $input->getOption('config');
        $baselineFlagPresent = $input->hasParameterOption('--baseline', true);
        $generateFlagPresent = $input->hasParameterOption('--generate-baseline', true);
        $reportEditorLink    = self::optionalStringOption($input, 'report-editor-link') ?? 'none';
        $isReportInteractive = self::reportInteractive($input);
        $deepScanBudget      = self::parseDeepScanBudgetOverride(self::optionalStringOption($input, 'deep-scan-budget'));
        $optionError         = null;

        if (is_string($deepScanBudget)) {
            $optionError    = $deepScanBudget;
            $deepScanBudget = null;
        }

        // A string back from the `--file` parser means one occurrence was blank (`--file=`); hold that error and drop the unusable list.
        if (is_string($filePaths)) {
            $optionError = $filePaths;
            $filePaths   = [];
        }

        // The user asked for an editor link we can't build (anything but none/vscode/phpstorm), so record the usage error.
        if (!in_array($reportEditorLink, ['none', 'vscode', 'phpstorm'], true)) {
            $optionError = '--report-editor-link must be one of: vscode, phpstorm, none.';
        }

        // A string here means `--report-interactive` had an unrecognised value; keep the error and treat it as off.
        if (is_string($isReportInteractive)) {
            $optionError         = $isReportInteractive;
            $isReportInteractive = false;
        }

        $paths    = array_merge($paths, $filePaths);
        $diffMode = self::diffMode($input, $paths);
        // A bare `-` selected stdin diff mode, so strip that placeholder out of the real file paths.
        if ($diffMode === '-') {
            $paths = array_values(array_filter(
                                      $paths,
                                      static fn(string $path): bool => $path !== '-',
                                  ));
        }

        return new self(
            paths:                         $paths,
            shouldIncludeIgnored:          (bool)$input->getOption('include-ignored'),
            configPath:                    is_string($configPath) && $configPath !== '' ? $configPath : null,
            noConfig:                      (bool)$input->getOption('no-config'),
            noCache:                       (bool)$input->getOption('no-cache'),
            profile:                       self::optionalStringOption($input, 'profile') ?? self::PROFILE_DEFAULT,
            mutation:                      new MutationAnalysisOptions(
                                      infectionReportPath:           self::optionalStringOption($input, 'infection-report'),
                                      shouldRunInfection:            (bool)$input->getOption('infection-run'),
                                      infectionBin:                  self::optionalStringOption($input, 'infection-bin') ?? 'infection',
                                      infectionConfigPath:           self::optionalStringOption($input, 'infection-config'),
                                      infectionTestFrameworkOptions: self::optionalStringOption($input, 'infection-test-framework-options'),
                                      mutationBaselinePath:          self::optionalStringOption($input, 'mutation-baseline'),
                                      mutationBudget:                null,
                                  ),
            diffMode:      $diffMode,
            since:         self::optionalStringOption($input, 'since'),
            changedRanges: self::optionalStringOption($input, 'changed-ranges'),
            changedScope:  self::optionalStringOption($input, 'changed-scope') ?? 'symbol',
            diffVs:        self::optionalStringOption($input, 'diff-vs'),
            isChangedOnly: (bool)$input->getOption('changed-only'),
            historyFile:   self::optionalStringOption($input, 'history-file'),
            noBaseline:    (bool)$input->getOption('no-baseline'),
            baseline:      new BaselineApplicationOptions(
                                      baselinePath:  $baselineFlagPresent
                                                                ? (self::optionalStringOption($input, 'baseline') ?? BaselineStore::DEFAULT_FILENAME)
                                                                : null,
                                      isBaselineExplicit:   $baselineFlagPresent,
                                      generateBaselinePath: $generateFlagPresent
                                                                ? (self::optionalStringOption($input, 'generate-baseline') ?? BaselineStore::DEFAULT_FILENAME)
                                                                : null,
                                  ),
            reportEditorLink:       $reportEditorLink,
            isReportInteractive:    $isReportInteractive,
            pathsRelativeTo:        self::optionalStringOption($input, 'paths-relative-to'),
            minSeverity:            self::optionalStringOption($input, 'min-severity'),
            includePillars:         self::stringListOption($input, 'include-pillar'),
            excludePillars:         self::stringListOption($input, 'exclude-pillar'),
            includeRules:           self::stringListOption($input, 'include-rule'),
            excludeRules:           self::stringListOption($input, 'exclude-rule'),
            deepScanBudgetOverride: $deepScanBudget,
            optionError:            $optionError,
        );
    }

    /**
     * Returns a copy with the mutation budget filled in, called once the command has parsed the
     * `--mutation-budget` value the user passed so the rest of the run can gate on it.
     *
     * @param int|null $mutationBudget - Most surviving mutants the run will tolerate before it fails; null when the user gave no `--mutation-budget`, so survivors only report and never fail the run.
     *
     * @return self - a new options bag identical to this one but with the mutation budget swapped in.
     */
    public function withMutationBudget(?int $mutationBudget): self
    {
        return new self(
            paths:                         $this->paths,
            shouldIncludeIgnored:          $this->shouldIncludeIgnored,
            configPath:                    $this->configPath,
            noConfig:                      $this->noConfig,
            noCache:                       $this->noCache,
            profile:                       $this->profile,
            mutation:                      new MutationAnalysisOptions(
                                      infectionReportPath:           $this->mutation->infectionReportPath,
                                      shouldRunInfection:            $this->mutation->shouldRunInfection,
                                      infectionBin:                  $this->mutation->infectionBin,
                                      infectionConfigPath:           $this->mutation->infectionConfigPath,
                                      infectionTestFrameworkOptions: $this->mutation->infectionTestFrameworkOptions,
                                      mutationBaselinePath:          $this->mutation->mutationBaselinePath,
                                      mutationBudget:                $mutationBudget,
                                  ),
            diffMode:               $this->diffMode,
            since:                  $this->since,
            changedRanges:          $this->changedRanges,
            changedScope:           $this->changedScope,
            diffVs:                 $this->diffVs,
            isChangedOnly:          $this->isChangedOnly,
            historyFile:            $this->historyFile,
            noBaseline:             $this->noBaseline,
            baseline:               $this->baseline,
            reportEditorLink:       $this->reportEditorLink,
            isReportInteractive:    $this->isReportInteractive,
            pathsRelativeTo:        $this->pathsRelativeTo,
            minSeverity:            $this->minSeverity,
            includePillars:         $this->includePillars,
            excludePillars:         $this->excludePillars,
            includeRules:           $this->includeRules,
            excludeRules:           $this->excludeRules,
            deepScanBudgetOverride: $this->deepScanBudgetOverride,
            optionError:            $this->optionError,
        );
    }

    /**
     * Returns a copy that quietly adopts the project's checked-in baseline so a plain
     * `gruff-php analyse` hides already-accepted debt without the user naming `--baseline`. Applied
     * once at startup; it only kicks in when the user left every baseline flag alone.
     *
     * @param string $projectRoot - Project root used to look for the default baseline file.
     *
     * @return self - a copy with the implicit default baseline applied, or this same instance unchanged when it cannot apply.
     */
    public function withDefaultBaseline(string $projectRoot): self
    {
        // Leave the options as-is when the user already chose a baseline, is generating one, opted out with `--no-baseline`, or the project has no default baseline file to adopt.
        if (
            $this->baseline->baselinePath !== null
            || $this->baseline->generateBaselinePath !== null
            || $this->noBaseline
            || !is_file(rtrim($projectRoot, '/') . '/' . BaselineStore::DEFAULT_FILENAME)
        ) {
            return $this;
        }

        return new self(
            paths:                $this->paths,
            shouldIncludeIgnored: $this->shouldIncludeIgnored,
            configPath:           $this->configPath,
            noConfig:             $this->noConfig,
            noCache:              $this->noCache,
            profile:              $this->profile,
            mutation:             $this->mutation,
            diffMode:             $this->diffMode,
            since:                $this->since,
            changedRanges:        $this->changedRanges,
            changedScope:         $this->changedScope,
            diffVs:               $this->diffVs,
            isChangedOnly:        $this->isChangedOnly,
            historyFile:          $this->historyFile,
            noBaseline:           $this->noBaseline,
            baseline:             new BaselineApplicationOptions(
                                      baselinePath:         BaselineStore::DEFAULT_FILENAME,
                                      isBaselineExplicit:   false,
                                      generateBaselinePath: null,
                                  ),
            reportEditorLink:       $this->reportEditorLink,
            isReportInteractive:    $this->isReportInteractive,
            pathsRelativeTo:        $this->pathsRelativeTo,
            minSeverity:            $this->minSeverity,
            includePillars:         $this->includePillars,
            excludePillars:         $this->excludePillars,
            includeRules:           $this->includeRules,
            excludeRules:           $this->excludeRules,
            deepScanBudgetOverride: $this->deepScanBudgetOverride,
            optionError:            $this->optionError,
        );
    }

    /**
     * Parses the family CLI shape: `off` disables the guard, otherwise `<lines>:<bytes>` replaces
     * both bounds atomically.
     *
     * @return array{enabled: bool, maxLines: int, maxBytes: int}|string|null - Parsed override, usage error, or null when absent.
     */
    public static function parseDeepScanBudgetOverride(?string $value): array|string|null
    {
        if ($value === null) {
            return null;
        }

        if ($value === 'off') {
            return [
                'enabled' => false,
                'maxLines' => 1,
                'maxBytes' => 1,
            ];
        }

        if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $value, $matches) !== 1) {
            return '--deep-scan-budget must be <positive-lines>:<positive-bytes> or off.';
        }

        return [
            'enabled' => true,
            'maxLines' => (int)$matches[1],
            'maxBytes' => (int)$matches[2],
        ];
    }

    /**
     * The command's pre-run gate: reports the first thing wrong with the user's flag combination so
     * it can print one usage line and stop, rather than begin a scan the flags can't actually mean.
     *
     * @return string|null - the first failing check's message, or null when every flag combination validated and the run may proceed.
     */
    public function usageError(): ?string
    {
        return $this->optionError
               ?? $this->configUsageError()
                  ?? self::profileUsageErrorFor($this->profile)
                     ?? $this->baselineUsageError()
                        ?? $this->diffUsageError()
                           ?? $this->changedOnlyUsageError()
                              ?? $this->noBaselineUsageError()
                                 ?? $this->displayFilterError();
    }

    /**
     * Turns the user's display flags (min-severity, include/exclude pillars and rules) into the
     * filter the reporter consults to decide which findings actually reach the screen.
     *
     * The rule lists are already enforced at execution level via the run's RuleSelection;
     * they ride along here so report metadata (run.filters) names every active filter.
     *
     * @return FindingDisplayFilter - the enum-typed filter the reporter applies to decide which findings to display.
     */
    public function displayFilter(): FindingDisplayFilter
    {
        return new FindingDisplayFilter(
            minSeverity:    $this->minSeverity === null ? null : Severity::from($this->minSeverity),
            includePillars: array_map(static fn(string $optionValue): Pillar => Pillar::from($optionValue), $this->includePillars),
            excludePillars: array_map(static fn(string $optionValue): Pillar => Pillar::from($optionValue), $this->excludePillars),
            includeRules:   $this->includeRules,
            excludeRules:   $this->excludeRules,
        );
    }

    /**
     * Translates `--profile=security` into the narrowed rule set the run should execute, so a
     * security-only pass skips every unrelated check. The default profile changes nothing.
     *
     * @return RuleSelection|null - selection narrowing execution to security pillars under the security profile, or null to keep the configured rule
     *                            set.
     */
    public function profileRuleSelection(): ?RuleSelection
    {
        // Only `--profile=security` narrows execution; any other profile keeps the user's configured rules, signalled by null.
        if ($this->profile !== self::PROFILE_SECURITY) {
            return null;
        }

        return new RuleSelection(pillars: [
                                              Pillar::Security->value,
                                              Pillar::SensitiveData->value,
                                          ]);
    }

    /**
     * Names which pillars the composite grade is built from for the active profile, so a
     * security-only run is graded on security alone rather than diluted by untouched pillars.
     *
     * @return list<Pillar>|null - the pillars that count toward the composite grade under the security profile, or null to score every pillar.
     */
    public function profileScorePillars(): ?array
    {
        return self::scorePillarsForProfile($this->profile);
    }

    /**
     * The shared lookup behind `profileScorePillars()`: given a profile name, says which pillars
     * the grade counts.
     *
     * Static so commands that forward analyse options (report) can validate profile/include
     * coherence against the same pillar set before any prompt or subprocess runs.
     *
     * @param string $profile - Requested profile name.
     *
     * @return list<Pillar>|null - the pillars the security profile scores, or null when the profile scores every pillar
     */
    public static function scorePillarsForProfile(string $profile): ?array
    {
        // Only the security profile restricts scoring to its two pillars; every other profile scores everything, hence null.
        if ($profile !== self::PROFILE_SECURITY) {
            return null;
        }

        return [Pillar::Security, Pillar::SensitiveData];
    }

    /**
     * Checks a profile name the user asked for and returns the error message when it is one we
     * don't offer, so a typo like `--profile=paranoid` is caught rather than silently ignored.
     *
     * Shared with report so forwarded profile errors are rejected before report-only side effects.
     *
     * @param string $profile - Requested profile name.
     *
     * @return string|null - the message naming the unrecognised profile, or null when the profile is supported
     */
    public static function profileUsageErrorFor(string $profile): ?string
    {
        // A known profile (default or security) is exactly what we support, so there is nothing to complain about.
        if (in_array($profile, [self::PROFILE_DEFAULT, self::PROFILE_SECURITY], true)) {
            return null;
        }

        // Reject unknown profiles loudly rather than silently falling back to default behaviour.
        return sprintf('Unsupported profile "%s". Use default or security.', $profile);
    }

    /**
     * Tells discovery whether the user opted into scanning only a changed region rather than the
     * whole tree, which happens the moment they pass `--diff`, `--since`, or `--changed-ranges`.
     *
     * @return bool - true when any changed-region opt-in (--diff, --since, or --changed-ranges) is active, so discovery should be scoped.
     */
    public function hasChangedRegionMode(): bool
    {
        return $this->diffMode !== null
               || $this->since !== null
               || $this->changedRanges !== null;
    }

    /**
     * Says whether the changed-region mode can itself supply the list of files to scan, which is
     * true for `--diff` and `--since` but not `--changed-ranges` (there the user already named paths).
     *
     * @return bool - true only for --diff and --since, which yield a concrete file list; --changed-ranges names paths the caller already gave.
     */
    public function usesChangedFilesForDiscovery(): bool
    {
        return $this->diffMode !== null || $this->since !== null;
    }

    /**
     * Works out whether the user asked for interactive report prompts, accepting the usual yes/no
     * spellings of `--report-interactive` and turning anything unrecognised into a usage error.
     *
     * @param InputInterface $input - Console input whose `--report-interactive` flag and value are inspected.
     *
     * @return bool|string - the resolved interactive flag when well-formed, or a usage-error message string when the value is unrecognised.
     */
    private static function reportInteractive(InputInterface $input): bool|string
    {
        // The flag was never passed, so interactive mode simply stays off.
        if (!$input->hasParameterOption('--report-interactive', true)) {
            return false;
        }

        $optionValue = $input->getOption('report-interactive');

        // A bare `--report-interactive` with no attached value reads as an explicit "yes, turn it on".
        if ($optionValue === null || $optionValue === true || $optionValue === '') {
            return true;
        }

        // Symfony already handed us a real boolean, so honour it directly.
        if (is_bool($optionValue)) {
            return $optionValue;
        }

        // Whatever is left isn't even text we could read as yes/no, so it can only be a usage error.
        if (!is_string($optionValue)) {
            return '--report-interactive must be true or false.';
        }

        // Accept the common truthy/falsy spellings; an unrecognised string (say `--report-interactive=maybe`) is a usage error, not a silent default.
        return match (strtolower($optionValue)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => '--report-interactive must be true or false.',
        };
    }

    /**
     * The workhorse for optional text flags: reads one option and gives back its value only when
     * the user actually typed something, so an absent or empty flag collapses to a null the caller
     * can replace with its own default.
     *
     * @param InputInterface $input - Console input the option is read from.
     * @param string         $name  - Option name without the leading dashes.
     *
     * @return string|null - the option value when a non-empty string, or null so callers can apply a null-coalescing default for absent or empty
     *                     input.
     */
    private static function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $optionValue = $input->getOption($name);

        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
    }

    /**
     * Collects a flag the user may repeat (like `--file`) into one list, keeping each value exactly
     * as typed with no comma-splitting, and rejecting the whole run if any occurrence was left blank.
     *
     * @param InputInterface $input - Console input the repeated option is read from.
     * @param string         $name  - Option name without the leading dashes; named in the error on a blank entry.
     *
     * @return list<string>|string - the verbatim occurrence values (no comma splitting), or a usage-error message string when any occurrence is
     *                             blank.
     */
    private static function repeatedStringOption(InputInterface $input, string $name): array|string
    {
        $values = $input->getOption($name);

        // The user never used this flag, so there is nothing to collect.
        if (!is_array($values)) {
            return [];
        }

        $items = [];

        // Walk each occurrence in the order the user typed them, keeping every value verbatim.
        foreach ($values as $optionValue) {
            // A blank occurrence (e.g. `--file=` with nothing after it) is a mistake worth naming rather than silently dropping.
            if (!is_string($optionValue) || $optionValue === '') {
                return sprintf('--%s requires a non-empty value.', $name);
            }

            $items[] = $optionValue;
        }

        return $items;
    }

    /**
     * Reads a repeatable list flag (the pillar and rule filters) and flattens it into one clean set
     * of ids, so a user can spread values across repeats and commas - `--include-rule=a,b
     * --include-rule=c` - and still get back a de-duplicated, trimmed list.
     *
     * @param InputInterface $input - Console input the repeated option is read from.
     * @param string         $name  - Option name without the leading dashes; each occurrence is comma-split and trimmed.
     *
     * @return list<string> - the comma-expanded, trimmed values from every occurrence, de-duplicated and re-keyed into a clean list.
     */
    private static function stringListOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);

        // The user never passed this flag, so the resulting list is empty.
        if (!is_array($values)) {
            return [];
        }

        $items = [];

        // Visit each time the user gave the flag, since any one of them may carry a comma-separated list.
        foreach ($values as $optionValue) {
            // Quietly skip an empty or non-string occurrence; unlike the strict `--file` reader, here a blank just contributes nothing.
            if (!is_string($optionValue) || $optionValue === '') {
                continue;
            }

            // Split this occurrence on commas so a single `--include-rule=a,b` expands into separate ids.
            foreach (explode(',', $optionValue) as $optionPart) {
                $trimmedOptionPart = trim($optionPart);
                // Keep only the pieces that still hold a real id once the surrounding spaces are trimmed off.
                if ($trimmedOptionPart !== '') {
                    $items[] = $trimmedOptionPart;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Reads how the user asked to diff: nothing when `--diff` is absent, stdin when they piped one
     * in, a bare `--diff` for the working tree, or the ref they named so only that change is scanned.
     *
     * @param InputInterface $input - Console input whose `--diff` flag and value are inspected.
     * @param list<string>   $paths - Parsed positional and --file paths; a bare "-" entry selects stdin diff mode.
     *
     * @return string|null - "-" for stdin, "working-tree" for a bare --diff, the explicit ref otherwise, or null when --diff was not supplied.
     */
    private static function diffMode(InputInterface $input, array $paths): ?string
    {
        // Without `--diff` the user isn't in diff mode at all, so report none.
        if (!$input->hasParameterOption('--diff', true)) {
            return null;
        }

        $optionValue = $input->getOption('diff');
        // A `-` among the paths means the user piped a diff on stdin, which takes precedence over any ref value.
        if (in_array('-', $paths, true)) {
            return '-';
        }

        return is_string($optionValue) && $optionValue !== '' ? $optionValue : 'working-tree';
    }

    /**
     * Catches the user asking to both skip config and load a specific one at the same time, since
     * obeying either `--no-config` or `--config` alone would quietly ignore what they also typed.
     *
     * @return string|null - the message when --no-config and --config are both set, or null when the config flags do not conflict.
     */
    private function configUsageError(): ?string
    {
        // Only both flags together conflict; if either is absent there is nothing to reconcile.
        if (!$this->noConfig || $this->configPath === null) {
            return null;
        }

        return '--no-config cannot be combined with --config.';
    }

    /**
     * Stops the user from applying and regenerating a baseline in a single run - mixing a read of an
     * existing baseline with a fresh write - which would leave them with an unpredictable result.
     *
     * @return string|null - the message when both --baseline and --generate-baseline are set (they are mutually exclusive, whatever paths they name), or null when at most one is set.
     */
    private function baselineUsageError(): ?string
    {
        // With at most one of `--baseline` / `--generate-baseline` set there is no clash, so allow the run.
        if ($this->baseline->baselinePath === null || $this->baseline->generateBaselinePath === null) {
            return null;
        }

        // Both are set: applying and regenerating a baseline in one run mixes a read with a rewrite, so forbid the combination outright.
        return '--baseline and --generate-baseline are mutually exclusive.';
    }

    /**
     * Guards the family of "scan only what changed" flags, which each pick the changed region a
     * different way and so must be used one at a time, with a valid scope and a path to anchor to.
     *
     * @return string|null - the first message for stacked changed-region modes, a bad --changed-scope, or ranges without a path, or null when all
     *                     hold.
     */
    private function diffUsageError(): ?string
    {
        $changedModes = array_filter([
                                         $this->diffMode,
                                         $this->since,
                                         $this->changedRanges,
                                     ], static fn(?string $mode): bool => $mode !== null);

        // Stacking two changed-region flags (say `--diff` and `--since`) is ambiguous, since each derives the region differently.
        if (count($changedModes) > 1) {
            return '--diff, --since, and --changed-ranges are mutually exclusive.';
        }

        // `--diff-vs` is its own comparison mode and cannot sit on top of a changed-region flag.
        if ($this->diffVs !== null && $changedModes !== []) {
            return '--diff, --since, --changed-ranges, and --diff-vs are mutually exclusive.';
        }

        // An unknown `--changed-scope` (e.g. `--changed-scope=line`) would silently mis-map ranges to findings, so refuse it.
        if (!in_array($this->changedScope, ['symbol', 'hunk', 'file'], true)) {
            return '--changed-scope must be one of: symbol, hunk, file.';
        }

        // `--changed-ranges` gives line spans, which mean nothing unless the user also names the file they belong to.
        if ($this->changedRanges !== null && $this->paths === []) {
            return '--changed-ranges requires at least one file path.';
        }

        return null;
    }

    /**
     * Makes sure `--changed-only` has the `--diff-vs` companion it depends on, so a user asking to
     * report just the changed files can't accidentally leave out what those changes are measured against.
     *
     * @return string|null - the message when --changed-only is set without the --diff-vs comparison ref it needs, or null otherwise.
     */
    private function changedOnlyUsageError(): ?string
    {
        // Either the user didn't ask for changed-only, or they already supplied `--diff-vs`, so nothing is missing.
        if (!$this->isChangedOnly || $this->diffVs !== null) {
            return null;
        }

        // Changed-only needs a comparison ref to decide which files changed; --diff-vs supplies it.
        return '--changed-only requires --diff-vs.';
    }

    /**
     * Rejects the contradiction of switching the baseline off while also handing one in, so a user
     * who typed both `--no-baseline` and `--baseline` is told rather than having one flag ignored.
     *
     * @return string|null - the message when --no-baseline is combined with an applied --baseline, or null when the two do not conflict.
     */
    private function noBaselineUsageError(): ?string
    {
        // It only clashes when `--no-baseline` rides alongside an actual `--baseline` to apply; otherwise let it pass.
        if (!$this->noBaseline || $this->baseline->baselinePath === null) {
            return null;
        }

        // Disabling the baseline while also pointing at one to apply is contradictory, so reject the run.
        return '--no-baseline cannot be combined with --baseline.';
    }

    /**
     * Vets the severity and pillar names the user typed for the display filter and returns the first
     * unusable one, so a bad value is reported cleanly here instead of crashing the reporter later.
     *
     * @return string|null - message for the first invalid severity or pillar, or null when all display inputs resolve.
     */
    private function displayFilterError(): ?string
    {
        // A `--min-severity` the user gave that isn't a real severity (say `--min-severity=critical`) is caught here, before displayFilter() would hard-fail on Severity::from().
        if ($this->minSeverity !== null && Severity::tryFrom($this->minSeverity) === null) {
            return sprintf('Unsupported min severity "%s". Use advisory, warning, or error.', $this->minSeverity);
        }

        // Check both the include and exclude pillar lists the user supplied.
        foreach (['--include-pillar' => $this->includePillars, '--exclude-pillar' => $this->excludePillars] as $option => $values) {
            // Look at each pillar name the user put in this particular list.
            foreach ($values as $optionValue) {
                // The first name that isn't a real pillar wins the error; naming which flag it came from helps the user fix it.
                if (Pillar::tryFrom($optionValue) === null) {
                    return sprintf('Unsupported pillar "%s" for %s.', $optionValue, $option);
                }
            }
        }

        return null;
    }
}
