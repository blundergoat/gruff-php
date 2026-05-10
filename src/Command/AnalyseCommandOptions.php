<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Baseline\BaselineApplicationOptions;
use GruffPhp\Baseline\BaselineStore;
use GruffPhp\Mutation\MutationAnalysisOptions;
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
        public ?string $historyFile,
        public bool $noBaseline,
        public BaselineApplicationOptions $baseline,
    ) {
    }

    public static function fromInput(InputInterface $input): self
    {
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $configPath = $input->getOption('config');
        $baselineFlagPresent = $input->hasParameterOption('--baseline', true);
        $generateFlagPresent = $input->hasParameterOption('--generate-baseline', true);

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
            historyFile: $this->historyFile,
            noBaseline: $this->noBaseline,
            baseline: $this->baseline,
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
            historyFile: $this->historyFile,
            noBaseline: $this->noBaseline,
            baseline: new BaselineApplicationOptions(
                baselinePath: BaselineStore::DEFAULT_FILENAME,
                baselineExplicit: false,
                generateBaselinePath: null,
            ),
        );
    }

    public function usageError(): ?string
    {
        if ($this->noConfig && $this->configPath !== null) {
            return '--no-config cannot be combined with --config.';
        }

        if ($this->baseline->baselinePath !== null && $this->baseline->generateBaselinePath !== null) {
            return '--baseline and --generate-baseline are mutually exclusive.';
        }

        if ($this->noBaseline && $this->baseline->baselinePath !== null) {
            return '--no-baseline cannot be combined with --baseline.';
        }

        return null;
    }

    private static function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function diffMode(InputInterface $input): ?string
    {
        if (!$input->hasParameterOption('--diff')) {
            return null;
        }

        $value = $input->getOption('diff');

        return is_string($value) && $value !== '' ? $value : 'working-tree';
    }
}
