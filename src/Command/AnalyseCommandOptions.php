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

final readonly class AnalyseCommandOptions
{
    /**
     * @param list<string> $paths
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

    public static function fromInput(InputInterface $input): self
    {
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $configPath = $input->getOption('config');
        $baselineFlagPresent = $input->hasParameterOption('--baseline', true);
        $generateFlagPresent = $input->hasParameterOption('--generate-baseline', true);
        $reportEditorLink = self::optionalStringOption($input, 'report-editor-link') ?? 'none';
        $reportInteractive = self::reportInteractive($input);
        $optionError = null;

        if (!in_array($reportEditorLink, ['none', 'vscode', 'phpstorm'], true)) {
            $optionError = '--report-editor-link must be one of: vscode, phpstorm, none.';
        }

        if (is_string($reportInteractive)) {
            $optionError = $reportInteractive;
            $reportInteractive = false;
        }

        return new self(
            paths: $paths,
            includeIgnored: (bool) $input->getOption('include-ignored'),
            configPath: is_string($configPath) ? $configPath : null,
            noConfig: (bool) $input->getOption('no-config'),
            mutation: new MutationAnalysisOptions(
                infectionReportPath: self::optionalStringOption($input, 'infection-report'),
                infectionRun: (bool) $input->getOption('infection-run'),
                infectionBin: self::optionalStringOption($input, 'infection-bin') ?? 'infection',
                infectionConfigPath: self::optionalStringOption($input, 'infection-config'),
                infectionTestFrameworkOptions: self::optionalStringOption($input, 'infection-test-framework-options'),
                mutationBaselinePath: self::optionalStringOption($input, 'mutation-baseline'),
                mutationBudget: null,
            ),
            diffMode: self::diffMode($input),
            diffVs: self::optionalStringOption($input, 'diff-vs'),
            changedOnly: (bool) $input->getOption('changed-only'),
            historyFile: self::optionalStringOption($input, 'history-file'),
            noBaseline: (bool) $input->getOption('no-baseline'),
            baseline: new BaselineApplicationOptions(
                baselinePath: $baselineFlagPresent
                    ? (self::optionalStringOption($input, 'baseline') ?? BaselineStore::DEFAULT_FILENAME)
                    : null,
                baselineExplicit: $baselineFlagPresent,
                generateBaselinePath: $generateFlagPresent
                    ? (self::optionalStringOption($input, 'generate-baseline') ?? BaselineStore::DEFAULT_FILENAME)
                    : null,
            ),
            reportEditorLink: $reportEditorLink,
            reportInteractive: $reportInteractive,
            pathsRelativeTo: self::optionalStringOption($input, 'paths-relative-to'),
            minSeverity: self::optionalStringOption($input, 'min-severity'),
            includePillars: self::stringListOption($input, 'include-pillar'),
            excludePillars: self::stringListOption($input, 'exclude-pillar'),
            includeRules: self::stringListOption($input, 'include-rule'),
            excludeRules: self::stringListOption($input, 'exclude-rule'),
            optionError: $optionError,
        );
    }

    public function withMutationBudget(?int $mutationBudget): self
    {
        return new self(
            paths: $this->paths,
            includeIgnored: $this->includeIgnored,
            configPath: $this->configPath,
            noConfig: $this->noConfig,
            mutation: new MutationAnalysisOptions(
                infectionReportPath: $this->mutation->infectionReportPath,
                infectionRun: $this->mutation->infectionRun,
                infectionBin: $this->mutation->infectionBin,
                infectionConfigPath: $this->mutation->infectionConfigPath,
                infectionTestFrameworkOptions: $this->mutation->infectionTestFrameworkOptions,
                mutationBaselinePath: $this->mutation->mutationBaselinePath,
                mutationBudget: $mutationBudget,
            ),
            diffMode: $this->diffMode,
            diffVs: $this->diffVs,
            changedOnly: $this->changedOnly,
            historyFile: $this->historyFile,
            noBaseline: $this->noBaseline,
            baseline: $this->baseline,
            reportEditorLink: $this->reportEditorLink,
            reportInteractive: $this->reportInteractive,
            pathsRelativeTo: $this->pathsRelativeTo,
            minSeverity: $this->minSeverity,
            includePillars: $this->includePillars,
            excludePillars: $this->excludePillars,
            includeRules: $this->includeRules,
            excludeRules: $this->excludeRules,
            optionError: $this->optionError,
        );
    }

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
            paths: $this->paths,
            includeIgnored: $this->includeIgnored,
            configPath: $this->configPath,
            noConfig: $this->noConfig,
            mutation: $this->mutation,
            diffMode: $this->diffMode,
            diffVs: $this->diffVs,
            changedOnly: $this->changedOnly,
            historyFile: $this->historyFile,
            noBaseline: $this->noBaseline,
            baseline: new BaselineApplicationOptions(
                baselinePath: BaselineStore::DEFAULT_FILENAME,
                baselineExplicit: false,
                generateBaselinePath: null,
            ),
            reportEditorLink: $this->reportEditorLink,
            reportInteractive: $this->reportInteractive,
            pathsRelativeTo: $this->pathsRelativeTo,
            minSeverity: $this->minSeverity,
            includePillars: $this->includePillars,
            excludePillars: $this->excludePillars,
            includeRules: $this->includeRules,
            excludeRules: $this->excludeRules,
            optionError: $this->optionError,
        );
    }

    public function usageError(): ?string
    {
        if ($this->optionError !== null) {
            return $this->optionError;
        }

        if ($this->noConfig && $this->configPath !== null) {
            return '--no-config cannot be combined with --config.';
        }

        if ($this->baseline->baselinePath !== null && $this->baseline->generateBaselinePath !== null) {
            return '--baseline and --generate-baseline are mutually exclusive.';
        }

        if ($this->diffMode !== null && $this->diffVs !== null) {
            return '--diff and --diff-vs are mutually exclusive.';
        }

        if ($this->changedOnly && $this->diffVs === null) {
            return '--changed-only requires --diff-vs.';
        }

        if ($this->noBaseline && $this->baseline->baselinePath !== null) {
            return '--no-baseline cannot be combined with --baseline.';
        }

        if ($this->displayFilterError() !== null) {
            return $this->displayFilterError();
        }

        return null;
    }

    public function displayFilter(): FindingDisplayFilter
    {
        return new FindingDisplayFilter(
            minSeverity: $this->minSeverity === null ? null : Severity::from($this->minSeverity),
            includePillars: array_map(static fn (string $value): Pillar => Pillar::from($value), $this->includePillars),
            excludePillars: array_map(static fn (string $value): Pillar => Pillar::from($value), $this->excludePillars),
            includeRules: $this->includeRules,
            excludeRules: $this->excludeRules,
        );
    }

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

    private static function diffMode(InputInterface $input): ?string
    {
        if (!$input->hasParameterOption('--diff')) {
            return null;
        }

        $value = $input->getOption('diff');

        return is_string($value) && $value !== '' ? $value : 'working-tree';
    }

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
