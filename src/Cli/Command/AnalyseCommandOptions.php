<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Results\Diff\ChangeScopeOptions;

use GruffPhp\Results\Baseline\BaselineApplicationOptions;
use GruffPhp\Results\Baseline\BaselineStore;
use GruffPhp\Engine\Config\RuleSelection;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Results\Mutation\MutationAnalysisOptions;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Output\Reporter\FindingDisplayFilter;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Immutable analyse options, defaults, and any captured parsing error.
 * fromInput() collects console values; usageError() checks supported combinations before a scan.
 * Copy methods resolve later inputs, while profile and display helpers expose choices to command stages.
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
     * Stores parsed values and defaults; construction does not check flag combinations.
     * Use fromInput() to parse console input and usageError() to check supported combinations.
     *
     * @param list<string> $paths - Requested scan paths; empty means no explicit paths remain after parsing.
     * @param bool $shouldIncludeIgnored - The parsed --include-ignored switch.
     * @param string|null $configPath - Explicit --config path; null means no non-empty path was supplied.
     * @param bool $noConfig - Requests a run without YAML configuration through --no-config.
     * @param bool $noCache - Requests an uncached scan through --no-cache.
     * @param string $profile - Requested profile; usageError() accepts default or security.
     * @param MutationAnalysisOptions $mutation - Infection and mutation inputs, with the budget resolved separately.
     * @param ChangeScopeOptions $changeScope - Changed-region and comparison choices, including the --diff-base alias.
     * @param string|null $historyFile - File requested by --history-file; null means no CLI history file was supplied.
     * @param bool $noBaseline - Whether --no-baseline was requested; suppresses default baseline adoption.
     * @param BaselineApplicationOptions $baseline - Baseline application, generation, migration, and overwrite choices.
     * @param string $reportEditorLink - Editor-link style from --report-editor-link: none, vscode, or phpstorm.
     * @param bool $isReportInteractive - Whether interactive HTML finding filters were requested by --report-interactive.
     * @param string|null $pathsRelativeTo - Display base from --paths-relative-to; null means no CLI override.
     * @param string|null $minSeverity - CLI severity floor; null adds no CLI severity restriction.
     * @param list<string> $includePillars - Pillars from --include-pillar; empty adds no restriction from this list.
     * @param list<string> $excludePillars - Pillars from --exclude-pillar; empty adds no exclusions from this list.
     * @param list<string> $includeRules - Canonical rule IDs requested by --include-rule; empty leaves this selector unset.
     * @param list<string> $excludeRules - Canonical rule IDs requested by --exclude-rule; empty leaves this selector unset.
     * @param PresentationSelectors $presentation - --show-* and --hide-* choices applied to report display.
     * @param string|null $minConfidence - Confidence floor from --min-confidence; null means no CLI override.
     * @param array{enabled: bool, maxLines: int, maxBytes: int}|null $deepScanBudgetOverride - CLI budget override; null defers to config/defaults.
     * @param string|null $optionError - Last error captured by fromInput(); null when its parsing checks found none.
     */
    public function __construct(
        public array                      $paths,
        public bool                       $shouldIncludeIgnored,
        public ?string                    $configPath,
        public bool                       $noConfig,
        public bool                       $noCache,
        public string                     $profile,
        public MutationAnalysisOptions    $mutation,
        public ChangeScopeOptions         $changeScope,
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
        public PresentationSelectors      $presentation,
        public ?string                    $minConfidence,
        /** @var array{enabled: bool, maxLines: int, maxBytes: int}|null */
        public ?array                     $deepScanBudgetOverride = null,
        private ?string                   $optionError = null,
    ) {
    }

    /**
     * Collects console options and records recoverable parsing errors without rejecting the object.
     * Call usageError() on the result before using flags that require validation.
     *
     * @param InputInterface $input - Console input to normalize into analyse options.
     *
     * @return self - Parsed options; optionError holds the last error from the checks performed here.
     */
    public static function fromInput(InputInterface $input): self
    {
        /** @var list<string> $paths - The command definition declares a variadic paths argument. */
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

        // Retain the --file parsing error without carrying an invalid path list into the options.
        if (is_string($filePaths)) {
            $optionError = $filePaths;
            $filePaths   = [];
        }


        if (!in_array($reportEditorLink, ['none', 'vscode', 'phpstorm'], true)) {
            $optionError = '--report-editor-link must be one of: vscode, phpstorm, none.';
        }


        if (is_string($isReportInteractive)) {
            $optionError         = $isReportInteractive;
            $isReportInteractive = false;
        }

        $paths    = array_merge($paths, $filePaths);
        $diffMode = self::diffMode($input, $paths);
        // A bare dash selected stdin diff mode, so strip that placeholder out of the real file paths.
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
            changeScope:   new ChangeScopeOptions(
                                      diffMode:      $diffMode,
                                      since:         self::optionalStringOption($input, 'since'),
                                      changedRanges: self::providedStringOption($input, 'changed-ranges'),
                                      changedScope:  self::optionalStringOption($input, 'changed-scope') ?? 'symbol',
                                      diffVs:        self::optionalStringOption($input, 'diff-base') ?? self::optionalStringOption($input, 'diff-vs'),
                                      isChangedOnly: (bool)$input->getOption('changed-only'),
                                  ),
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
                                      migrateBaselinePath:  self::optionalStringOption($input, 'migrate-baseline'),
                                      shouldForceBaselineOverwrite: (bool)$input->getOption('force'),
                                  ),
            reportEditorLink:       $reportEditorLink,
            isReportInteractive:    $isReportInteractive,
            pathsRelativeTo:        self::optionalStringOption($input, 'paths-relative-to'),
            minSeverity:            self::optionalStringOption($input, 'min-severity'),
            includePillars:         self::stringListOption($input, 'include-pillar'),
            excludePillars:         self::stringListOption($input, 'exclude-pillar'),
            // Rule flags accept retired IDs such as --exclude-rule docs.missing-public-phpdoc and retain their canonical replacements.
            includeRules:           array_map(RuleRegistry::canonicalRuleId(...), self::stringListOption($input, 'include-rule')),
            excludeRules:           array_map(RuleRegistry::canonicalRuleId(...), self::stringListOption($input, 'exclude-rule')),
            presentation:           new PresentationSelectors(
                                        showRules:   array_map(RuleRegistry::canonicalRuleId(...), self::stringListOption($input, 'show-rule')),
                                        hideRules:   array_map(RuleRegistry::canonicalRuleId(...), self::stringListOption($input, 'hide-rule')),
                                        showPillars: self::stringListOption($input, 'show-pillar'),
                                        hidePillars: self::stringListOption($input, 'hide-pillar'),
                                    ),
            minConfidence:          self::optionalStringOption($input, 'min-confidence'),
            deepScanBudgetOverride: $deepScanBudget,
            optionError:            $optionError,
        );
    }

    /**
     * Returns a copy with the parsed --mutation-budget value so the rest of the run can gate on it.
     *
     * @param int|null $mutationBudget - Budget to place on the copied mutation options; null clears it.
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
            changeScope:            $this->changeScope,
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
            presentation:           $this->presentation,
            minConfidence:          $this->minConfidence,
            deepScanBudgetOverride: $this->deepScanBudgetOverride,
            optionError:            $this->optionError,
        );
    }

    /**
     * Adopts the project's existing default baseline when no applied or generated baseline was selected.
     * An explicit baseline, generation request, or --no-baseline keeps this instance unchanged.
     *
     * @param string $projectRoot - Project root used to look for the default baseline file.
     *
     * @return self - a copy with the implicit default baseline applied, or this same instance unchanged when it cannot apply.
     */
    public function withDefaultBaseline(string $projectRoot): self
    {

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
            changeScope:          $this->changeScope,
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
            presentation:           $this->presentation,
            minConfidence:          $this->minConfidence,
            deepScanBudgetOverride: $this->deepScanBudgetOverride,
            optionError:            $this->optionError,
        );
    }

    /**
     * Parses the family CLI budget: off disables the guard; otherwise lines:bytes replaces both bounds.
     *
     * @param string|null $budgetOverride - Raw --deep-scan-budget text; null when the flag was absent.
     *
     * @return array{enabled: bool, maxLines: int, maxBytes: int}|string|null - Parsed override, usage error, or null when absent.
     */
    public static function parseDeepScanBudgetOverride(?string $budgetOverride): array|string|null
    {
        if ($budgetOverride === null) {
            return null;
        }

        if ($budgetOverride === 'off') {
            return [
                'enabled' => false,
                'maxLines' => 1,
                'maxBytes' => 1,
            ];
        }

        // Both bounds must be positive integers joined by a colon; a partial or zero override is a usage error.
        if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $budgetOverride, $matches) !== 1) {
            return '--deep-scan-budget must be <positive-lines>:<positive-bytes> or off.';
        }

        return [
            'enabled' => true,
            'maxLines' => (int)$matches[1],
            'maxBytes' => (int)$matches[2],
        ];
    }

    /**
     * Returns a captured parsing error before checking supported option combinations.
     * Captured errors take priority; otherwise the combination checks stop at their first failure.
     *
     * @return string|null - Captured or combination error; null when these checks find none.
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
     * Builds a display filter from the CLI severity and combined include/exclude and show/hide selectors.
     *
     * @return FindingDisplayFilter - Filter the command applies before it builds the report.
     */
    public function displayFilter(): FindingDisplayFilter
    {

        $shownPillars  = [...$this->includePillars, ...$this->presentation->showPillars];
        $hiddenPillars = [...$this->excludePillars, ...$this->presentation->hidePillars];

        return new FindingDisplayFilter(
            minSeverity:    $this->minSeverity === null ? null : Severity::from($this->minSeverity),
            includePillars: array_map(static fn(string $optionValue): Pillar => Pillar::from($optionValue), $shownPillars),
            excludePillars: array_map(static fn(string $optionValue): Pillar => Pillar::from($optionValue), $hiddenPillars),
            includeRules:   [...$this->includeRules, ...$this->presentation->showRules],
            excludeRules:   [...$this->excludeRules, ...$this->presentation->hideRules],
        );
    }

    /**
     * Translates --profile=security into the narrowed rule set the run should execute, so a security-only pass skips every unrelated check.
     * The default profile changes nothing.
     *
     * @return RuleSelection|null - Security and sensitive-data rules, or null to retain the configured selection.
     */
    public function profileRuleSelection(): ?RuleSelection
    {

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
     * Returns scoring pillars for a profile; null leaves scoring unrestricted by the profile.
     * Shared with report so forwarded profile/include choices use the same pillar set.
     *
     * @param string $profile - Profile to inspect; values other than security return null.
     *
     * @return list<Pillar>|null - Security and sensitive-data pillars for security; null for every other value.
     */
    public static function scorePillarsForProfile(string $profile): ?array
    {

        if ($profile !== self::PROFILE_SECURITY) {
            return null;
        }

        return [Pillar::Security, Pillar::SensitiveData];
    }

    /**
     * Rejects profile names other than default and security with a usage-error message.
     * Shared with report so invalid forwarded profiles are rejected before report-only side effects.
     *
     * @param string $profile - Requested profile name.
     *
     * @return string|null - the message naming the unrecognised profile, or null when the profile is supported
     */
    public static function profileUsageErrorFor(string $profile): ?string
    {

        if (in_array($profile, [self::PROFILE_DEFAULT, self::PROFILE_SECURITY], true)) {
            return null;
        }


        return sprintf('Unsupported profile "%s". Use default or security.', $profile);
    }

    /**
     * Reports whether --diff, --since, or --changed-ranges selected changed-region mode.
     *
     * @return bool - true when any changed-region opt-in (--diff, --since, or --changed-ranges) is active, so discovery should be scoped.
     */
    public function hasChangedRegionMode(): bool
    {
        return $this->changeScope->diffMode !== null
               || $this->changeScope->since !== null
               || $this->changeScope->changedRanges !== null;
    }

    /**
     * Reports whether changed-region mode can supply the files to scan. This is true for --diff and --since,
     * but not --changed-ranges, where the caller already supplied paths.
     *
     * @return bool - True for --diff and --since when a resolved diff can supply files; explicit ranges rely on caller-supplied paths.
     */
    public function usesChangedFilesForDiscovery(): bool
    {
        return $this->changeScope->diffMode !== null || $this->changeScope->since !== null;
    }

    /**
     * Resolves whether --report-interactive enables HTML finding filters, accepting common yes/no values.
     * Returns a usage error for anything unrecognised.
     *
     * @param InputInterface $input - Console input whose --report-interactive flag and value are inspected.
     *
     * @return bool|string - the resolved interactive flag when well-formed, or a usage-error message string when the value is unrecognised.
     */
    private static function reportInteractive(InputInterface $input): bool|string
    {

        if (!$input->hasParameterOption('--report-interactive', true)) {
            return false;
        }

        $optionValue = $input->getOption('report-interactive');

        // A bare --report-interactive reads as an explicit request to enable interactive filters.
        if ($optionValue === null || $optionValue === true || $optionValue === '') {
            return true;
        }


        if (is_bool($optionValue)) {
            return $optionValue;
        }


        if (!is_string($optionValue)) {
            return '--report-interactive must be true or false.';
        }


        return match (strtolower($optionValue)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => '--report-interactive must be true or false.',
        };
    }

    /**
     * Reads a non-empty string option, returning null for empty strings and non-string values.
     *
     * @param InputInterface $input - Console input the option is read from.
     * @param string         $name  - Option name without the leading dashes.
     *
     * @return string|null - Non-empty option value, or null so the caller can apply its default.
     */
    private static function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $optionValue = $input->getOption($name);

        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
    }

    /**
     * Preserves empty strings so --changed-ranges= remains distinguishable from an omitted value.
     * Unlike optionalStringOption(), this leaves an empty value available for range validation.
     *
     * @param InputInterface $input - Console input to read the option from.
     * @param string         $name  - Option name to look up.
     *
     * @return string|null - String value including an empty string, or null when getOption() returns a non-string value.
     */
    private static function providedStringOption(InputInterface $input, string $name): ?string
    {
        $optionValue = $input->getOption($name);

        return is_string($optionValue) ? $optionValue : null;
    }

    /**
     * Collects a flag the user may repeat (like --file) into one list, keeping each value exactly
     * as typed with no comma-splitting, and rejecting the whole run if any occurrence was left blank.
     *
     * @param InputInterface $input - Console input the repeated option is read from.
     * @param string         $name  - Option name without the leading dashes; named in the error on a blank entry.
     *
     * @return list<string>|string - Occurrence values without comma splitting, or an error for an empty or non-string entry.
     */
    private static function repeatedStringOption(InputInterface $input, string $name): array|string
    {
        $values = $input->getOption($name);


        if (!is_array($values)) {
            return [];
        }

        $items = [];


        foreach ($values as $optionValue) {

            if (!is_string($optionValue) || $optionValue === '') {
                return sprintf('--%s requires a non-empty value.', $name);
            }

            $items[] = $optionValue;
        }

        return $items;
    }

    /**
     * Reads a repeatable list flag (the pillar and rule filters) and flattens it into one clean set
     * of ids, so a user can spread values across repeats and commas - --include-rule=a,b
     * --include-rule=c - and still get back a de-duplicated, trimmed list.
     *
     * @param InputInterface $input - Console input the repeated option is read from.
     * @param string         $name  - Option name without the leading dashes; each occurrence is comma-split and trimmed.
     *
     * @return list<string> - the comma-expanded, trimmed values from every occurrence, de-duplicated and re-keyed into a clean list.
     */
    private static function stringListOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);


        if (!is_array($values)) {
            return [];
        }

        $items = [];


        foreach ($values as $optionValue) {
            // Quietly skip an empty or non-string occurrence; unlike the strict --file reader, here a blank just contributes nothing.
            if (!is_string($optionValue) || $optionValue === '') {
                continue;
            }


            foreach (explode(',', $optionValue) as $optionPart) {
                $trimmedOptionPart = trim($optionPart);

                if ($trimmedOptionPart !== '') {
                    $items[] = $trimmedOptionPart;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Reads the selected diff mode from --diff and the parsed paths.
     * Returns stdin mode for a "-" path, working tree for bare --diff, or the explicit ref.
     *
     * @param InputInterface $input - Console input whose --diff flag and value are inspected.
     * @param list<string>   $paths - Parsed positional and --file paths; with --diff present, "-" selects stdin mode.
     *
     * @return string|null - "-" for stdin, "working-tree" for a bare --diff, the explicit ref otherwise, or null when --diff was not supplied.
     */
    private static function diffMode(InputInterface $input, array $paths): ?string
    {

        if (!$input->hasParameterOption('--diff', true)) {
            return null;
        }

        $optionValue = $input->getOption('diff');
        // A "-" path selects stdin mode and takes precedence over any ref value.
        if (in_array('-', $paths, true)) {
            return '-';
        }

        return is_string($optionValue) && $optionValue !== '' ? $optionValue : 'working-tree';
    }

    /**
     * Catches the user asking to both skip config and load a specific one at the same time, since
     * obeying either --no-config or --config alone would quietly ignore what they also typed.
     *
     * @return string|null - the message when --no-config and --config are both set, or null when the config flags do not conflict.
     */
    private function configUsageError(): ?string
    {

        if (!$this->noConfig || $this->configPath === null) {
            return null;
        }

        return '--no-config cannot be combined with --config.';
    }

    /**
     * Checks baseline generation prerequisites and conflicts with applying an existing baseline.
     * Migration requires a generation destination; application and generation are mutually exclusive.
     *
     * @return string|null - Error for migration without a destination or simultaneous application and generation; null otherwise.
     */
    private function baselineUsageError(): ?string
    {

        if ($this->baseline->migrateBaselinePath !== null && $this->baseline->generateBaselinePath === null) {
            return '--migrate-baseline requires --generate-baseline <new path>; the migrated file is written there and the original is left untouched.';
        }


        if ($this->baseline->baselinePath === null || $this->baseline->generateBaselinePath === null) {
            return null;
        }


        return '--baseline and --generate-baseline are mutually exclusive.';
    }

    /**
     * Rejects incompatible change selectors, unsupported scope names, and explicit ranges without paths.
     * --diff-vs cannot be combined with --diff, --since, or --changed-ranges.
     *
     * @return string|null - First failing change-option check, or null when these checks find no conflict.
     */
    private function diffUsageError(): ?string
    {
        $changedModes = array_filter([
                                         $this->changeScope->diffMode,
                                         $this->changeScope->since,
                                         $this->changeScope->changedRanges,
                                     ], static fn(?string $mode): bool => $mode !== null);


        if (count($changedModes) > 1) {
            return '--diff, --since, and --changed-ranges are mutually exclusive.';
        }


        if ($this->changeScope->diffVs !== null && $changedModes !== []) {
            return '--diff, --since, --changed-ranges, and --diff-vs are mutually exclusive.';
        }


        if (!in_array($this->changeScope->changedScope, ['symbol', 'hunk', 'file'], true)) {
            return '--changed-scope must be one of: symbol, hunk, file.';
        }

        // --changed-ranges gives line spans, which mean nothing unless the user also names the file they belong to.
        if ($this->changeScope->changedRanges !== null && $this->paths === []) {
            return '--changed-ranges requires at least one file path.';
        }

        return null;
    }

    /**
     * Makes sure --changed-only has the --diff-vs companion it depends on, so a user asking to
     * report just the changed files can't accidentally leave out what those changes are measured against.
     *
     * @return string|null - the message when --changed-only is set without the --diff-vs comparison ref it needs, or null otherwise.
     */
    private function changedOnlyUsageError(): ?string
    {

        if (!$this->changeScope->isChangedOnly || $this->changeScope->diffVs !== null) {
            return null;
        }


        return '--changed-only requires --diff-vs.';
    }

    /**
     * Rejects the contradiction of switching the baseline off while also handing one in, so a user
     * who typed both --no-baseline and --baseline is told rather than having one flag ignored.
     *
     * @return string|null - the message when --no-baseline is combined with an applied --baseline, or null when the two do not conflict.
     */
    private function noBaselineUsageError(): ?string
    {

        if (!$this->noBaseline || $this->baseline->baselinePath === null) {
            return null;
        }


        return '--no-baseline cannot be combined with --baseline.';
    }

    /**
     * Validates display-filter severity and pillar names so invalid values become usage errors before the filter is built.
     *
     * @return string|null - message for the first invalid severity or pillar, or null when all display inputs resolve.
     */
    private function displayFilterError(): ?string
    {
        // Invalid severity text would make Severity::from() throw while the report filter is being built.
        if ($this->minSeverity !== null && Severity::tryFrom($this->minSeverity) === null) {
            return sprintf('Unsupported min severity "%s". Use advisory, warning, or error.', $this->minSeverity);
        }


        foreach (['--include-pillar' => $this->includePillars, '--exclude-pillar' => $this->excludePillars] as $option => $values) {

            foreach ($values as $optionValue) {

                if (Pillar::tryFrom($optionValue) === null) {
                    return sprintf('Unsupported pillar "%s" for %s.', $optionValue, $option);
                }
            }
        }

        return null;
    }
}
