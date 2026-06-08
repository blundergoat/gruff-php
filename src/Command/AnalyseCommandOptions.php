<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Baseline\BaselineApplicationOptions;
use GruffPhp\Baseline\BaselineStore;
use GruffPhp\Config\RuleSelection;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Mutation\MutationAnalysisOptions;
use GruffPhp\Reporting\FindingDisplayFilter;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Captures validated CLI options for an analyse run.
 */
final readonly class AnalyseCommandOptions
{
    /**
     * Normal profile that leaves configured rule selection unchanged.
     */
    private const PROFILE_DEFAULT = 'default';

    /**
     * Security profile that executes only security and sensitive-data rules.
     */
    private const PROFILE_SECURITY = 'security';

    /**
     * @param list<string>               $paths - Paths requested for analysis.
     * @param bool                       $shouldIncludeIgnored - Whether ignored files should be included.
     * @param string|null                $configPath - Explicit config path supplied by the CLI.
     * @param bool                       $noConfig - Whether config loading is disabled.
     * @param bool                       $noCache - Whether the on-disk result cache is disabled for the run.
     * @param string                     $profile - Rule execution profile requested for the run.
     * @param MutationAnalysisOptions    $mutation - Parsed mutation-analysis options.
     * @param string|null                $diffMode - Requested diff mode, when diff analysis is enabled.
     * @param string|null                $since - Git base ref used for changed-region analysis.
     * @param string|null                $changedRanges - Explicit changed ranges used for changed-region analysis.
     * @param string                     $changedScope - Changed-region scope: symbol, hunk, or file.
     * @param string|null                $diffVs - Comparison ref used for diff and changed-only analysis.
     * @param bool                       $isChangedOnly - Whether analysis should be restricted to changed files.
     * @param string|null                $historyFile - Trend history file path, when configured.
     * @param bool                       $noBaseline - Whether baseline loading and application are disabled.
     * @param BaselineApplicationOptions $baseline - Parsed baseline application options.
     * @param string                     $reportEditorLink - Editor-link style requested for reports.
     * @param bool                       $isReportInteractive - Whether interactive report behavior is enabled.
     * @param string|null                $pathsRelativeTo - Base path used to normalize reported paths.
     * @param string|null                $minSeverity - Minimum severity filter requested for output.
     * @param list<string>               $includePillars - Pillars explicitly included in report output.
     * @param list<string>               $excludePillars - Pillars explicitly excluded from report output.
     * @param list<string>               $includeRules - Rule IDs explicitly included in report output.
     * @param list<string>               $excludeRules - Rule IDs explicitly excluded from report output.
     * @param string|null                $optionError - First usage error discovered while parsing options.
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
        private ?string                   $optionError = null,
    ) {
    }

    /**
     * Build an options object from the Symfony Console InputInterface, recording any usage errors found.
     *
     * @param InputInterface $input - Console input to normalize into analyse options.
     *
     * @return self - fully populated options bag whose optionError carries the first usage error, if any.
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
        $optionError         = null;

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
        if ($diffMode === '-') {
            $paths = array_values(array_filter(
                                      $paths,
                                      static fn(string $path): bool => $path !== '-',
                                  ));
        }

        return new self(
            paths:                $paths,
            shouldIncludeIgnored: (bool)$input->getOption('include-ignored'),
            configPath:           is_string($configPath) && $configPath !== '' ? $configPath : null,
            noConfig:             (bool)$input->getOption('no-config'),
            noCache:              (bool)$input->getOption('no-cache'),
            profile:              self::optionalStringOption($input, 'profile') ?? self::PROFILE_DEFAULT,
            mutation:             new MutationAnalysisOptions(
                                      infectionReportPath:           self::optionalStringOption($input, 'infection-report'),
                                      shouldRunInfection:            (bool)$input->getOption('infection-run'),
                                      infectionBin:                  self::optionalStringOption($input, 'infection-bin') ?? 'infection',
                                      infectionConfigPath:           self::optionalStringOption($input, 'infection-config'),
                                      infectionTestFrameworkOptions: self::optionalStringOption($input, 'infection-test-framework-options'),
                                      mutationBaselinePath:          self::optionalStringOption($input, 'mutation-baseline'),
                                      mutationBudget:                null,
                                  ),
            diffMode:             $diffMode,
            since:                self::optionalStringOption($input, 'since'),
            changedRanges:        self::optionalStringOption($input, 'changed-ranges'),
            changedScope:         self::optionalStringOption($input, 'changed-scope') ?? 'symbol',
            diffVs:               self::optionalStringOption($input, 'diff-vs'),
            isChangedOnly:        (bool)$input->getOption('changed-only'),
            historyFile:          self::optionalStringOption($input, 'history-file'),
            noBaseline:           (bool)$input->getOption('no-baseline'),
            baseline:             new BaselineApplicationOptions(
                                      baselinePath:         $baselineFlagPresent
                                                                ? (self::optionalStringOption($input, 'baseline') ?? BaselineStore::DEFAULT_FILENAME)
                                                                : null,
                                      isBaselineExplicit:   $baselineFlagPresent,
                                      generateBaselinePath: $generateFlagPresent
                                                                ? (self::optionalStringOption($input, 'generate-baseline') ?? BaselineStore::DEFAULT_FILENAME)
                                                                : null,
                                  ),
            reportEditorLink:     $reportEditorLink,
            isReportInteractive:  $isReportInteractive,
            pathsRelativeTo:      self::optionalStringOption($input, 'paths-relative-to'),
            minSeverity:          self::optionalStringOption($input, 'min-severity'),
            includePillars:       self::stringListOption($input, 'include-pillar'),
            excludePillars:       self::stringListOption($input, 'exclude-pillar'),
            includeRules:         self::stringListOption($input, 'include-rule'),
            excludeRules:         self::stringListOption($input, 'exclude-rule'),
            optionError:          $optionError,
        );
    }

    /**
     * Return a copy with the mutation budget set (used after parsing the `--mutation-budget` value).
     *
     * @param int|null $mutationBudget - Mutation score budget, or null when unset.
     *
     * @return self - a new options bag identical to this one but with the mutation budget swapped in.
     */
    public function withMutationBudget(?int $mutationBudget): self
    {
        return new self(
            paths:                $this->paths,
            shouldIncludeIgnored: $this->shouldIncludeIgnored,
            configPath:           $this->configPath,
            noConfig:             $this->noConfig,
            noCache:              $this->noCache,
            profile:              $this->profile,
            mutation:             new MutationAnalysisOptions(
                                      infectionReportPath:           $this->mutation->infectionReportPath,
                                      shouldRunInfection:            $this->mutation->shouldRunInfection,
                                      infectionBin:                  $this->mutation->infectionBin,
                                      infectionConfigPath:           $this->mutation->infectionConfigPath,
                                      infectionTestFrameworkOptions: $this->mutation->infectionTestFrameworkOptions,
                                      mutationBaselinePath:          $this->mutation->mutationBaselinePath,
                                      mutationBudget:                $mutationBudget,
                                  ),
            diffMode:             $this->diffMode,
            since:                $this->since,
            changedRanges:        $this->changedRanges,
            changedScope:         $this->changedScope,
            diffVs:               $this->diffVs,
            isChangedOnly:        $this->isChangedOnly,
            historyFile:          $this->historyFile,
            noBaseline:           $this->noBaseline,
            baseline:             $this->baseline,
            reportEditorLink:     $this->reportEditorLink,
            isReportInteractive:  $this->isReportInteractive,
            pathsRelativeTo:      $this->pathsRelativeTo,
            minSeverity:          $this->minSeverity,
            includePillars:       $this->includePillars,
            excludePillars:       $this->excludePillars,
            includeRules:         $this->includeRules,
            excludeRules:         $this->excludeRules,
            optionError:          $this->optionError,
        );
    }

    /**
     * Return a copy that auto-applies the project's default baseline when one exists and no other baseline flag is set.
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
            reportEditorLink:     $this->reportEditorLink,
            isReportInteractive:  $this->isReportInteractive,
            pathsRelativeTo:      $this->pathsRelativeTo,
            minSeverity:          $this->minSeverity,
            includePillars:       $this->includePillars,
            excludePillars:       $this->excludePillars,
            includeRules:         $this->includeRules,
            excludeRules:         $this->excludeRules,
            optionError:          $this->optionError,
        );
    }

    /**
     * Return the first usage-error message the input produced, or null when the combination is valid.
     *
     * @return string|null - the first failing check's message, or null when every flag combination validated and the run may proceed.
     */
    public function usageError(): ?string
    {
        return $this->optionError
               ?? $this->configUsageError()
                  ?? $this->profileUsageError()
                     ?? $this->baselineUsageError()
                        ?? $this->diffUsageError()
                           ?? $this->changedOnlyUsageError()
                              ?? $this->noBaselineUsageError()
                                 ?? $this->displayFilterError();
    }

    /**
     * Build the FindingDisplayFilter from the parsed display options (min-severity, include/exclude pillars and rules).
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
     * Build the execution selection implied by the requested profile, or null for normal configured selection.
     *
     * @return RuleSelection|null - selection narrowing execution to security pillars under the security profile, or null to keep the configured rule
     *                            set.
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
     * Return the pillar set that should contribute to composite scoring for the requested profile.
     *
     * @return list<Pillar>|null - the pillars that count toward the composite grade under the security profile, or null to score every pillar.
     */
    public function profileScorePillars(): ?array
    {
        if ($this->profile !== self::PROFILE_SECURITY) {
            return null;
        }

        return [Pillar::Security, Pillar::SensitiveData];
    }

    /**
     * Report whether an opt-in changed-region analysis mode is active.
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
     * Report whether changed file paths can be used to scope discovery.
     *
     * @return bool - true only for --diff and --since, which yield a concrete file list; --changed-ranges names paths the caller already gave.
     */
    public function usesChangedFilesForDiscovery(): bool
    {
        return $this->diffMode !== null || $this->since !== null;
    }

    /**
     * Parse the `--report-interactive` option; returns true/false or a usage-error message string.
     *
     * @param InputInterface $input - Console input whose `--report-interactive` flag and value are inspected.
     *
     * @return bool|string - the resolved interactive flag when well-formed, or a usage-error message string when the value is unrecognised.
     */
    private static function reportInteractive(InputInterface $input): bool|string
    {
        if (!$input->hasParameterOption('--report-interactive', true)) {
            return false;
        }

        $optionValue = $input->getOption('report-interactive');

        if ($optionValue === null || $optionValue === true || $optionValue === '') {
            return true;
        }

        if (is_bool($optionValue)) {
            return $optionValue;
        }

        if (!is_string($optionValue)) {
            return '--report-interactive must be true or false.';
        }

        // Accept the common truthy/falsy spellings; an unrecognised string is a usage error, not a silent default.
        return match (strtolower($optionValue)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => '--report-interactive must be true or false.',
        };
    }

    /**
     * Read a string option and return it only when non-empty; otherwise null.
     *
     * @param InputInterface $input - Console input the option is read from.
     * @param string         $name - Option name without the leading dashes.
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
     * Read a repeatable string option without comma expansion.
     *
     * @param InputInterface $input - Console input the repeated option is read from.
     * @param string         $name - Option name without the leading dashes; named in the error on a blank entry.
     *
     * @return list<string>|string - the verbatim occurrence values (no comma splitting), or a usage-error message string when any occurrence is
     *                             blank.
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
                // A blank occurrence is rejected outright rather than silently dropped, surfacing the mistake.
                return sprintf('--%s requires a non-empty value.', $name);
            }

            $items[] = $optionValue;
        }

        return $items;
    }

    /**
     * Read a repeatable CLI option as a list of strings.
     *
     * @param InputInterface $input - Console input the repeated option is read from.
     * @param string         $name - Option name without the leading dashes; each occurrence is comma-split and trimmed.
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
     * Parse the `--diff` option: null when absent, "working-tree" when bare, or the explicit value.
     *
     * @param InputInterface $input - Console input whose `--diff` flag and value are inspected.
     * @param list<string>   $paths - Parsed positional and --file paths; a bare "-" entry selects stdin diff mode.
     *
     * @return string|null - "-" for stdin, "working-tree" for a bare --diff, the explicit ref otherwise, or null when --diff was not supplied.
     */
    private static function diffMode(InputInterface $input, array $paths): ?string
    {
        if (!$input->hasParameterOption('--diff', true)) {
            return null;
        }

        $optionValue = $input->getOption('diff');
        if (in_array('-', $paths, true)) {
            return '-';
        }

        return is_string($optionValue) && $optionValue !== '' ? $optionValue : 'working-tree';
    }

    /**
     * Return the usage error for mutually exclusive config flags.
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
     * Return the usage error for unsupported rule execution profiles.
     *
     * @return string|null - the message naming the unrecognised profile, or null when the profile is "default" or "security".
     */
    private function profileUsageError(): ?string
    {
        if (in_array($this->profile, [self::PROFILE_DEFAULT, self::PROFILE_SECURITY], true)) {
            return null;
        }

        // Reject unknown profiles loudly rather than silently falling back to default behaviour.
        return sprintf('Unsupported profile "%s". Use default or security.', $this->profile);
    }

    /**
     * Return the usage error for mutually exclusive baseline modes.
     *
     * @return string|null - the message when --baseline and --generate-baseline both target the same file, or null when at most one is set.
     */
    private function baselineUsageError(): ?string
    {
        if ($this->baseline->baselinePath === null || $this->baseline->generateBaselinePath === null) {
            return null;
        }

        // Reading and (re)writing a baseline in the same run would race on the same file, so forbid it.
        return '--baseline and --generate-baseline are mutually exclusive.';
    }

    /**
     * Return the usage error for mutually exclusive diff modes.
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

        if (count($changedModes) > 1) {
            // Each mode derives the changed region differently; combining them has no coherent meaning.
            return '--diff, --since, and --changed-ranges are mutually exclusive.';
        }

        if ($this->diffVs !== null && $changedModes !== []) {
            // --diff-vs is its own comparison mode and cannot stack on top of a changed-region mode.
            return '--diff, --since, --changed-ranges, and --diff-vs are mutually exclusive.';
        }

        if (!in_array($this->changedScope, ['symbol', 'hunk', 'file'], true)) {
            // Scope drives how ranges map to findings; an unknown value would silently mis-scope, so reject it.
            return '--changed-scope must be one of: symbol, hunk, file.';
        }

        if ($this->changedRanges !== null && $this->paths === []) {
            // Ranges are line spans within files, so they are meaningless without at least one target path.
            return '--changed-ranges requires at least one file path.';
        }

        return null;
    }

    /**
     * Return the usage error for changed-only analysis without a comparison ref.
     *
     * @return string|null - the message when --changed-only is set without the --diff-vs comparison ref it needs, or null otherwise.
     */
    private function changedOnlyUsageError(): ?string
    {
        if (!$this->isChangedOnly || $this->diffVs !== null) {
            return null;
        }

        // Changed-only needs a comparison ref to decide which files changed; --diff-vs supplies it.
        return '--changed-only requires --diff-vs.';
    }

    /**
     * Return the usage error for disabling and applying a baseline together.
     *
     * @return string|null - the message when --no-baseline is combined with an applied --baseline, or null when the two do not conflict.
     */
    private function noBaselineUsageError(): ?string
    {
        if (!$this->noBaseline || $this->baseline->baselinePath === null) {
            return null;
        }

        // Disabling the baseline while also pointing at one to apply is contradictory, so reject the run.
        return '--no-baseline cannot be combined with --baseline.';
    }

    /**
     * Validate the display-filter inputs (min-severity, include/exclude pillars); return the first error or null.
     *
     * @return string|null - message for the first invalid severity or pillar, or null when all display inputs resolve.
     */
    private function displayFilterError(): ?string
    {
        if ($this->minSeverity !== null && Severity::tryFrom($this->minSeverity) === null) {
            // Catch the bad value here, before displayFilter() would hard-fail on Severity::from().
            return sprintf('Unsupported min severity "%s". Use advisory, warning, or error.', $this->minSeverity);
        }

        foreach (['--include-pillar' => $this->includePillars, '--exclude-pillar' => $this->excludePillars] as $option => $values) {
            foreach ($values as $optionValue) {
                if (Pillar::tryFrom($optionValue) === null) {
                    // First unrecognised pillar wins; reporting which option it came from aids the fix.
                    return sprintf('Unsupported pillar "%s" for %s.', $optionValue, $option);
                }
            }
        }

        return null;
    }
}
