<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Baseline\BaselineApplicationOptions;
use GruffPhp\Baseline\BaselineStore;
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
     * @param list<string>               $paths             Paths requested for analysis.
     * @param bool                       $includeIgnored    Whether ignored files should be included.
     * @param string|null                $configPath        Explicit config path supplied by the CLI.
     * @param bool                       $noConfig          Whether config loading is disabled.
     * @param MutationAnalysisOptions    $mutation          Parsed mutation-analysis options.
     * @param string|null                $diffMode          Requested diff mode, when diff analysis is enabled.
     * @param string|null                $diffVs            Comparison ref used for diff and changed-only analysis.
     * @param bool                       $changedOnly       Whether analysis should be restricted to changed files.
     * @param string|null                $historyFile       Trend history file path, when configured.
     * @param bool                       $noBaseline        Whether baseline loading and application are disabled.
     * @param BaselineApplicationOptions $baseline          Parsed baseline application options.
     * @param string                     $reportEditorLink  Editor-link style requested for reports.
     * @param bool                       $reportInteractive Whether interactive report behavior is enabled.
     * @param string|null                $pathsRelativeTo   Base path used to normalize reported paths.
     * @param string|null                $minSeverity       Minimum severity filter requested for output.
     * @param list<string>               $includePillars    Pillars explicitly included in report output.
     * @param list<string>               $excludePillars    Pillars explicitly excluded from report output.
     * @param list<string>               $includeRules      Rule IDs explicitly included in report output.
     * @param list<string>               $excludeRules      Rule IDs explicitly excluded from report output.
     * @param string|null                $optionError       First usage error discovered while parsing options.
     */
    public function __construct(
        public array $paths,
        public bool $includeIgnored,
        public ?string $configPath,
        public bool $noConfig,
        public MutationAnalysisOptions $mutation,
        public ?string $diffMode,
        public ?string $diffVs,
        public bool $changedOnly,
        public ?string $historyFile,
        public bool $noBaseline,
        public BaselineApplicationOptions $baseline,
        public string $reportEditorLink,
        public bool $reportInteractive,
        public ?string $pathsRelativeTo,
        public ?string $minSeverity,
        /** @var list<string> */
        public array $includePillars,
        /** @var list<string> */
        public array $excludePillars,
        /** @var list<string> */
        public array $includeRules,
        /** @var list<string> */
        public array $excludeRules,
        private ?string $optionError = null,
    ) {
    }

    /**
     * Build an options object from the Symfony Console InputInterface, recording any usage errors found.
     *
     * @param InputInterface $input Console input to normalize into analyse options.
     * @return self
     */
    public static function fromInput(InputInterface $input): self
    {
        /** @var list<string> $paths The command definition declares a variadic paths argument. */
        $paths               = $input->getArgument('paths');
        $configPath          = $input->getOption('config');
        $baselineFlagPresent = $input->hasParameterOption('--baseline', true);
        $generateFlagPresent = $input->hasParameterOption('--generate-baseline', true);
        $reportEditorLink    = self::optionalStringOption($input, 'report-editor-link') ?? 'none';
        $reportInteractive   = self::reportInteractive($input);
        $optionError         = null;

        if (!in_array($reportEditorLink, ['none', 'vscode', 'phpstorm'], true)) {
            $optionError = '--report-editor-link must be one of: vscode, phpstorm, none.';
        }

        if (is_string($reportInteractive)) {
            $optionError       = $reportInteractive;
            $reportInteractive = false;
        }

        return new self(
            paths:                         $paths,
            includeIgnored:                (bool) $input->getOption('include-ignored'),
            configPath:                    is_string($configPath) ? $configPath : null,
            noConfig:                      (bool) $input->getOption('no-config'),
            mutation:                      new MutationAnalysisOptions(
                infectionReportPath:           self::optionalStringOption($input, 'infection-report'),
                infectionRun:                  (bool) $input->getOption('infection-run'),
                infectionBin:                  self::optionalStringOption($input, 'infection-bin') ?? 'infection',
                infectionConfigPath:           self::optionalStringOption($input, 'infection-config'),
                infectionTestFrameworkOptions: self::optionalStringOption($input, 'infection-test-framework-options'),
                mutationBaselinePath:          self::optionalStringOption($input, 'mutation-baseline'),
                mutationBudget:                null,
            ),
            diffMode:     self::diffMode($input),
            diffVs:       self::optionalStringOption($input, 'diff-vs'),
            changedOnly:  (bool) $input->getOption('changed-only'),
            historyFile:  self::optionalStringOption($input, 'history-file'),
            noBaseline:   (bool) $input->getOption('no-baseline'),
            baseline:     new BaselineApplicationOptions(
                baselinePath: $baselineFlagPresent
                    ? (self::optionalStringOption($input, 'baseline') ?? BaselineStore::DEFAULT_FILENAME)
                    : null,
                baselineExplicit:     $baselineFlagPresent,
                generateBaselinePath: $generateFlagPresent
                    ? (self::optionalStringOption($input, 'generate-baseline') ?? BaselineStore::DEFAULT_FILENAME)
                    : null,
            ),
            reportEditorLink:  $reportEditorLink,
            reportInteractive: $reportInteractive,
            pathsRelativeTo:   self::optionalStringOption($input, 'paths-relative-to'),
            minSeverity:       self::optionalStringOption($input, 'min-severity'),
            includePillars:    self::stringListOption($input, 'include-pillar'),
            excludePillars:    self::stringListOption($input, 'exclude-pillar'),
            includeRules:      self::stringListOption($input, 'include-rule'),
            excludeRules:      self::stringListOption($input, 'exclude-rule'),
            optionError:       $optionError,
        );
    }

    /**
     * Return a copy with the mutation budget set (used after parsing the `--mutation-budget` value).
     *
     * @param int|null $mutationBudget Mutation score budget, or null when unset.
     * @return self
     */
    public function withMutationBudget(?int $mutationBudget): self
    {
        return new self(
            paths:                         $this->paths,
            includeIgnored:                $this->includeIgnored,
            configPath:                    $this->configPath,
            noConfig:                      $this->noConfig,
            mutation:                      new MutationAnalysisOptions(
                infectionReportPath:           $this->mutation->infectionReportPath,
                infectionRun:                  $this->mutation->infectionRun,
                infectionBin:                  $this->mutation->infectionBin,
                infectionConfigPath:           $this->mutation->infectionConfigPath,
                infectionTestFrameworkOptions: $this->mutation->infectionTestFrameworkOptions,
                mutationBaselinePath:          $this->mutation->mutationBaselinePath,
                mutationBudget:                $mutationBudget,
            ),
            diffMode:          $this->diffMode,
            diffVs:            $this->diffVs,
            changedOnly:       $this->changedOnly,
            historyFile:       $this->historyFile,
            noBaseline:        $this->noBaseline,
            baseline:          $this->baseline,
            reportEditorLink:  $this->reportEditorLink,
            reportInteractive: $this->reportInteractive,
            pathsRelativeTo:   $this->pathsRelativeTo,
            minSeverity:       $this->minSeverity,
            includePillars:    $this->includePillars,
            excludePillars:    $this->excludePillars,
            includeRules:      $this->includeRules,
            excludeRules:      $this->excludeRules,
            optionError:       $this->optionError,
        );
    }

    /**
     * Return a copy that auto-applies the project's default baseline when one exists and no other baseline flag is set.
     *
     * @param string $projectRoot Project root used to look for the default baseline file.
     * @return self
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
            includeIgnored:       $this->includeIgnored,
            configPath:           $this->configPath,
            noConfig:             $this->noConfig,
            mutation:             $this->mutation,
            diffMode:             $this->diffMode,
            diffVs:               $this->diffVs,
            changedOnly:          $this->changedOnly,
            historyFile:          $this->historyFile,
            noBaseline:           $this->noBaseline,
            baseline:             new BaselineApplicationOptions(
                baselinePath:         BaselineStore::DEFAULT_FILENAME,
                baselineExplicit:     false,
                generateBaselinePath: null,
            ),
            reportEditorLink:  $this->reportEditorLink,
            reportInteractive: $this->reportInteractive,
            pathsRelativeTo:   $this->pathsRelativeTo,
            minSeverity:       $this->minSeverity,
            includePillars:    $this->includePillars,
            excludePillars:    $this->excludePillars,
            includeRules:      $this->includeRules,
            excludeRules:      $this->excludeRules,
            optionError:       $this->optionError,
        );
    }

    /**
     * Return the first usage-error message the input produced, or null when the combination is valid.
     *
     * @return string|null
     */
    public function usageError(): ?string
    {
        return $this->optionError
            ?? $this->configUsageError()
            ?? $this->baselineUsageError()
            ?? $this->diffUsageError()
            ?? $this->changedOnlyUsageError()
            ?? $this->noBaselineUsageError()
            ?? $this->displayFilterError();
    }

    /**
     * Build the FindingDisplayFilter from the parsed display options (min-severity, include/exclude pillars and rules).
     *
     * @return FindingDisplayFilter
     */
    public function displayFilter(): FindingDisplayFilter
    {
        return new FindingDisplayFilter(
            minSeverity:    $this->minSeverity === null ? null : Severity::from($this->minSeverity),
            includePillars: array_map(static fn (string $value): Pillar => Pillar::from($value), $this->includePillars),
            excludePillars: array_map(static fn (string $value): Pillar => Pillar::from($value), $this->excludePillars),
            includeRules:   $this->includeRules,
            excludeRules:   $this->excludeRules,
        );
    }

    /**
     * Parse the `--report-interactive` option; returns true/false or a usage-error message string.
     *
     * @return bool|string True/false when the option is well-formed, or the error message string.
     */
    private static function reportInteractive(InputInterface $input): bool|string
    {
        if (!$input->hasParameterOption('--report-interactive', true)) {
            return false;
        }

        $value = $input->getOption('report-interactive');

        if ($value === null || $value === true || $value === '') {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return '--report-interactive must be true or false.';
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => '--report-interactive must be true or false.',
        };
    }

    /**
     * Read a string option and return it only when non-empty; otherwise null.
     *
     * @return string|null
     */
    private static function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringListOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);

        if (!is_array($values)) {
            return [];
        }

        $items = [];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            foreach (explode(',', $value) as $item) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Parse the `--diff` option; null when absent, "working-tree" when bare, or the explicit value.
     *
     * @return string|null
     */
    private static function diffMode(InputInterface $input): ?string
    {
        if (!$input->hasParameterOption('--diff')) {
            return null;
        }

        $value = $input->getOption('diff');

        return is_string($value) && $value !== '' ? $value : 'working-tree';
    }

    /**
     * Return the usage error for mutually exclusive config flags.
     *
     * @return string|null Error message, or null when config flags are valid.
     */
    private function configUsageError(): ?string
    {
        if (!$this->noConfig || $this->configPath === null) {
            return null;
        }

        return '--no-config cannot be combined with --config.';
    }

    /**
     * Return the usage error for mutually exclusive baseline modes.
     *
     * @return string|null Error message, or null when baseline flags are valid.
     */
    private function baselineUsageError(): ?string
    {
        if ($this->baseline->baselinePath === null || $this->baseline->generateBaselinePath === null) {
            return null;
        }

        return '--baseline and --generate-baseline are mutually exclusive.';
    }

    /**
     * Return the usage error for mutually exclusive diff modes.
     *
     * @return string|null Error message, or null when diff flags are valid.
     */
    private function diffUsageError(): ?string
    {
        if ($this->diffMode === null || $this->diffVs === null) {
            return null;
        }

        return '--diff and --diff-vs are mutually exclusive.';
    }

    /**
     * Return the usage error for changed-only analysis without a comparison ref.
     *
     * @return string|null Error message, or null when changed-only flags are valid.
     */
    private function changedOnlyUsageError(): ?string
    {
        if (!$this->changedOnly || $this->diffVs !== null) {
            return null;
        }

        return '--changed-only requires --diff-vs.';
    }

    /**
     * Return the usage error for disabling and applying a baseline together.
     *
     * @return string|null Error message, or null when baseline application flags are valid.
     */
    private function noBaselineUsageError(): ?string
    {
        if (!$this->noBaseline || $this->baseline->baselinePath === null) {
            return null;
        }

        return '--no-baseline cannot be combined with --baseline.';
    }

    /**
     * Validate the display-filter inputs (min-severity, include/exclude pillars); return the first error or null.
     *
     * @return string|null
     */
    private function displayFilterError(): ?string
    {
        if ($this->minSeverity !== null && Severity::tryFrom($this->minSeverity) === null) {
            return sprintf('Unsupported min severity "%s". Use advisory, warning, or error.', $this->minSeverity);
        }

        foreach (['--include-pillar' => $this->includePillars, '--exclude-pillar' => $this->excludePillars] as $option => $values) {
            foreach ($values as $value) {
                if (Pillar::tryFrom($value) === null) {
                    return sprintf('Unsupported pillar "%s" for %s.', $value, $option);
                }
            }
        }

        return null;
    }
}
