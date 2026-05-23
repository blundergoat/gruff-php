<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Baseline\BaselineApplication;
use GruffPhp\Baseline\BaselineException;
use GruffPhp\Baseline\BaselineStore;
use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Console\Application;
use GruffPhp\Diff\DiffException;
use GruffPhp\Diff\DiffFindingFilter;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Diff\GitDiffProvider;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Command\Runtime\RuntimeTimingObserver;
use GruffPhp\Mutation\MutationAnalysisBuilder;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Mutation\MutationFindingFactory;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\GithubAnnotationsReporter;
use GruffPhp\Reporting\HotspotReporter;
use GruffPhp\Reporting\HtmlReporter;
use GruffPhp\Reporting\JsonReporter;
use GruffPhp\Reporting\MarkdownReporter;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Reporting\SarifReporter;
use GruffPhp\Reporting\TextReporter;
use GruffPhp\Review\BranchReviewComparator;
use GruffPhp\Review\BranchReviewResult;
use GruffPhp\Review\GitArchiveSnapshot;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Scoring\CompositeFindingFactory;
use GruffPhp\Scoring\ScoreCalculator;
use GruffPhp\Support\PathHelper;
use GruffPhp\Trend\TrendRecorder;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Implements the primary gruff-php analyse CLI command.
 */
final class AnalyseCommand extends Command
{
    /**
     * Configure the analyse command arguments and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('analyse')
            ->setDescription('Run gruff-php analysis.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'File to analyse. Can be repeated.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff-php.yaml file for this run.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Rule execution profile: default or security.', default: 'default')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, json, html, markdown, github, hotspot, or sarif.', default: OutputFormat::Text->value)
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the run: advisory, warning, error, or none.', default: FailThreshold::Error->value)
            ->addOption('report-editor-link', null, InputOption::VALUE_REQUIRED, 'Editor link style for HTML file:line references: vscode, phpstorm, or none.', default: 'none')
            ->addOption('report-interactive', null, InputOption::VALUE_OPTIONAL, 'Render opt-in interactive HTML finding filters. Accepts true or false.', default: null)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.')
            ->addOption('infection-report', null, InputOption::VALUE_REQUIRED, 'Path to a full Infection JSON report to ingest.')
            ->addOption('infection-run', null, InputOption::VALUE_NONE, 'Run Infection before ingesting the report path supplied by --infection-report.')
            ->addOption('infection-bin', null, InputOption::VALUE_REQUIRED, 'Infection executable for --infection-run.', default: 'infection')
            ->addOption('infection-config', null, InputOption::VALUE_REQUIRED, 'Path to infection.json5 for --infection-run.')
            ->addOption('infection-test-framework-options', null, InputOption::VALUE_REQUIRED, 'Options passed to Infection/PHPUnit for --infection-run.')
            ->addOption('mutation-baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline Infection JSON report for MSI diff mode.')
            ->addOption('mutation-budget', null, InputOption::VALUE_REQUIRED, 'Maximum escaped/timed-out mutants allowed.')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Filter findings to changed lines. Use working-tree, staged, unstaged, or a base ref.', default: null)
            ->addOption('diff-vs', null, InputOption::VALUE_REQUIRED, 'Compare current findings against a base Git ref and report introduced/removed/unchanged findings.')
            ->addOption('changed-only', null, InputOption::VALUE_NONE, 'With --diff-vs, compare only files changed from the base ref.')
            ->addOption('paths-relative-to', null, InputOption::VALUE_REQUIRED, 'Normalize absolute finding paths relative to this directory for reports.')
            ->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'Display only findings at or above advisory, warning, or error.')
            ->addOption('include-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated pillars or repeated values.')
            ->addOption('exclude-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated pillars or repeated values.')
            ->addOption('include-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated rule IDs or repeated values.')
            ->addOption('exclude-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated rule IDs or repeated values.')
            ->addOption('history-file', null, InputOption::VALUE_REQUIRED, 'Append score trend history to this JSON file.')
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Suppress findings that match a gruff baseline JSON file. Defaults to "%s" at the project root when present.',
                    BaselineStore::DEFAULT_FILENAME,
                ),
            )
            ->addOption(
                'generate-baseline',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Write current findings to a gruff baseline JSON file. Defaults to "%s" when no path is given; overwrites silently.',
                    BaselineStore::DEFAULT_FILENAME,
                ),
            )
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Skip auto-applying the default baseline file for this run.')
            ->addOption('print-runtime', null, InputOption::VALUE_NONE, 'Emit performance instrumentation (wall, peak memory, phase, optional per-rule) as JSON on stderr.')
            ->addOption('runtime-mode', null, InputOption::VALUE_REQUIRED, 'Runtime payload detail: summary (default) or detailed (adds per-rule totals).', default: 'summary');
    }

    /**
     * Run source discovery, rule analysis, optional mutation ingestion, and reporting.
     *
     * @return int Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtimeStart          = hrtime(true);
        $printRuntime          = (bool) $input->getOption('print-runtime');
        $runtimeModeOpt        = $input->getOption('runtime-mode');
        $runtimeDetailed       = $printRuntime && $runtimeModeOpt === 'detailed';
        $runtimeTimingObserver = $runtimeDetailed ? new RuntimeTimingObserver() : null;

        $setupResult = (new AnalyseCommandSetupBuilder())->build($input);

        if (!$setupResult->setup instanceof AnalyseCommandSetup) {
            return $this->renderSetupFailure($setupResult, $output);
        }

        $setup         = $setupResult->setup;
        $projectRoot   = $setup->projectRoot;
        $options       = $setup->options;
        $format        = $setup->format;
        $failThreshold = $setup->failThreshold;
        $config        = $setup->config;
        $registry      = $setup->registry;
        $diagnostics   = [];
        $reviewDiff    = $this->buildDiffResult($projectRoot, $options->diffVs, $diagnostics);
        $analysisPaths = $this->currentAnalysisPaths($options, $reviewDiff);
        $discoverStart = hrtime(true);

        $ruleContext      = new RuleContext($projectRoot, $config);
        $analysisPipeline = new AnalysisPipeline($registry, $this->projectContextUnits(...));
        $analysisRun      = $analysisPipeline->runAnalysis(
            projectRoot:        $projectRoot,
            options:            $options,
            config:             $config,
            ruleContext:        $ruleContext,
            reviewDiff:         $reviewDiff,
            analysisPaths:      $analysisPaths,
            discoverStart:      $discoverStart,
            ruleRunnerObserver: $runtimeTimingObserver,
        );
        $sources             = $analysisRun['sources'];
        $findings            = $analysisRun['findings'];
        $discoverParseNs     = $analysisRun['discoverParseNs'];
        $analyseNs           = $analysisRun['analyseNs'];
        $projectContextUnits = $analysisRun['projectContextUnits'];
        $diagnostics         = array_merge(
            $diagnostics,
            $this->filterSourceDiagnostics($sources->diagnostics, $projectRoot, $options, $reviewDiff),
        );
        $mutationAnalysis = (new MutationAnalysisBuilder())->build(
            $projectRoot,
            $options->mutation,
            $diagnostics,
        );

        if ($mutationAnalysis instanceof MutationAnalysisResult) {
            $findings = array_merge($findings, (new MutationFindingFactory())->findingsFor($mutationAnalysis));
        }

        $findings = array_merge($findings, (new CompositeFindingFactory())->build($findings));
        if ($options->diffVs !== null && $options->isChangedOnly && $reviewDiff instanceof DiffResult) {
            $findings = $this->filterFindingsToChangedFiles($findings, $reviewDiff->changedFiles);
        }

        $diff = $this->buildDiffResult($projectRoot, $options->diffMode, $diagnostics);
        if ($diff instanceof DiffResult && $diff->active) {
            $findings = (new DiffFindingFilter())->filter($findings, $diff);
        }

        $findings       = $this->filterAllowedSecretPreviews($findings, $config);
        $baselineReport = (new BaselineApplication())->apply(
            projectRoot: $projectRoot,
            options:     $options->baseline,
            findings:    $findings,
            diff:        $diff,
            diagnostics: $diagnostics,
        );
        $findings = $this->normalizeFindingPaths($findings, $options->pathsRelativeTo);

        $scoreStart  = hrtime(true);
        $score       = (new ScoreCalculator())->calculate($findings, $mutationAnalysis, $diff, scorePillars: $options->profileScorePillars());
        $scoreNs     = hrtime(true) - $scoreStart;
        $reviewScore = $options->diffVs === null
            ? $score->composite->score
            : $this->branchReviewScore($findings, $options);
        $review = $this->buildBranchReview(
            projectRoot:     $projectRoot,
            options:         $options,
            config:          $config,
            registry:        $registry,
            currentFindings: $findings,
            currentScore:    $reviewScore,
            reviewDiff:      $reviewDiff,
            diagnostics:     $diagnostics,
        );
        $trend = null;

        if ($options->historyFile !== null) {
            try {
                $trend = (new TrendRecorder())->record($projectRoot, $options->historyFile, $score, count($findings));
            } catch (JsonException | RuntimeException $exception) {
                $diagnostics[] = new RunDiagnostic(
                    type:    'history-error',
                    message: $exception->getMessage(),
                    path:    $options->historyFile,
                );
            }
        }

        $exitCode        = $this->resolveExitCode($diagnostics, $findings, $failThreshold);
        $displayFilter   = $options->displayFilter();
        $displayFindings = $displayFilter->apply($findings);
        $displayReview   = $review?->filtered(fn (array $reviewFindings): array => $displayFilter->apply($reviewFindings));
        $analysisReport  = new AnalysisReport(
            toolVersion:     Application::VERSION,
            requestedPaths:  $options->paths,
            format:          $format->value,
            failOn:          $failThreshold->value,
            filesDiscovered: count($sources->discovery->files),
            filesParsed:     $sources->parsedFileCount(),
            ignoredPaths:    $sources->discovery->ignoredPaths,
            missingPaths:    $sources->discovery->missingPaths,
            diagnostics:     $diagnostics,
            findings:        $displayFindings,
            exitCode:        $exitCode,
            configPath:      $setup->configPath,
            mutation:        $mutationAnalysis,
            score:           $score,
            diff:            $diff,
            trend:           $trend,
            baseline:        $baselineReport,
            review:          $displayReview,
            filters:         $displayFilter,
        );

        $reportStart = hrtime(true);
        $this->renderReport(
            report:              $analysisReport,
            format:              $format,
            output:              $output,
            projectRoot:         $projectRoot,
            reportEditorLink:    $options->reportEditorLink,
                isReportInteractive: $options->isReportInteractive,
        );
        $reportNs = hrtime(true) - $reportStart;

        if ($printRuntime) {
            $this->emitRuntimePayload(
                output:           $output,
                runtimeStart:     $runtimeStart,
                phaseDurationsNs: [
                    'discoverParseNs' => $discoverParseNs,
                    'analyseNs' => $analyseNs,
                    'scoreNs' => $scoreNs,
                    'reportNs' => $reportNs,
                ],
                filesParsed:           $sources->parsedFileCount(),
                rulesExecuted:         count($registry->enabledRules($config)),
                runtimeTimingObserver: $runtimeTimingObserver,
                isDetailed:            $runtimeDetailed,
            );
        }

        return $exitCode;
    }

    /**
     * Write the performance instrumentation payload as a single JSON line on stderr.
     *
     * @param array{discoverParseNs: int, analyseNs: int, scoreNs: int, reportNs: int} $phaseDurationsNs Timed analyse phase durations in nanoseconds.
     * @return void
     */
    private function emitRuntimePayload(
        OutputInterface $output,
        int $runtimeStart,
        array $phaseDurationsNs,
        int $filesParsed,
        int $rulesExecuted,
        ?RuntimeTimingObserver $runtimeTimingObserver,
        bool $isDetailed,
    ): void {
        $totalNs = hrtime(true) - $runtimeStart;
        $payload = [
            'wallMs' => (int) round($totalNs / 1_000_000),
            'peakBytes' => memory_get_peak_usage(true),
            'filesParsed' => $filesParsed,
            'rulesExecuted' => $rulesExecuted,
            'phases' => $phaseDurationsNs,
            'mode' => $isDetailed ? 'detailed' : 'summary',
        ];

        if ($isDetailed && $runtimeTimingObserver !== null) {
            $payload['rules'] = $runtimeTimingObserver->snapshot();
        }

        $line   = json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL;
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : null;

        if ($stderr !== null) {
            $stderr->write($line);

            return;
        }

        fwrite(STDERR, $line);
    }

    /**
     * Render setup errors using either plain console text or the requested report format.
     *
     * @return int Setup failure exit code.
     */
    private function renderSetupFailure(AnalyseCommandSetupResult $result, OutputInterface $output): int
    {
        if ($result->plainError !== null) {
            $output->writeln($result->plainError);

            return $result->exitCode;
        }

        if ($result->report instanceof AnalysisReport && $result->format instanceof OutputFormat) {
            $this->renderReport($result->report, $result->format, $output);
        }

        return $result->exitCode;
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function filterAllowedSecretPreviews(array $findings, AnalysisConfig $config): array
    {
        $allowedPreviews = $config->allowedSecretPreviews();
        if ($allowedPreviews === []) {
            return $findings;
        }

        return array_values(array_filter(
            $findings,
            static function (Finding $finding) use ($allowedPreviews): bool {
                $preview = $finding->metadata['preview'] ?? null;

                return $finding->pillar !== Pillar::SensitiveData
                    || !is_string($preview)
                    || !in_array($preview, $allowedPreviews, true);
            },
        ));
    }

    /**
     * @param list<RunDiagnostic> $diagnostics
     *
     * @return DiffResult|null Diff result, inactive result, or null when diff lookup fails.
     */
    private function buildDiffResult(string $projectRoot, ?string $diffMode, array &$diagnostics): ?DiffResult
    {
        if ($diffMode === null) {
            return DiffResult::inactive();
        }

        try {
            return (new GitDiffProvider())->changedLines($projectRoot, $diffMode);
        } catch (DiffException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'diff-mode-error',
                message: $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * @return list<string>|null Null means the changed-only review diff is empty, so no current files should be scanned.
     */
    private function currentAnalysisPaths(AnalyseCommandOptions $options, ?DiffResult $reviewDiff): ?array
    {
        if ($options->isChangedOnly && $options->paths === [] && $reviewDiff === null) {
            return null;
        }

        if (!$options->isChangedOnly || $options->paths !== [] || !$reviewDiff instanceof DiffResult) {
            return $options->paths;
        }

        return $reviewDiff->changedFiles === [] ? null : $reviewDiff->changedFiles;
    }

    /**
     * @param list<RunDiagnostic> $diagnostics
     * @return list<RunDiagnostic>
     */
    private function filterSourceDiagnostics(
        array $diagnostics,
        string $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult $reviewDiff,
    ): array {
        if (!$options->isChangedOnly || !$reviewDiff instanceof DiffResult || $reviewDiff->changedFiles === []) {
            return $diagnostics;
        }

        return array_values(array_filter(
            $diagnostics,
            function (RunDiagnostic $diagnostic) use ($projectRoot, $reviewDiff): bool {
                if ($diagnostic->type !== 'missing-path' || $diagnostic->path === null) {
                    return true;
                }

                $requestedPaths = $this->normaliseRequestedPaths($projectRoot, [$diagnostic->path]);
                if ($requestedPaths === []) {
                    return true;
                }

                foreach ($reviewDiff->changedFiles as $changedFile) {
                    if ($this->matchesRequestedPath($changedFile, $requestedPaths)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * @param list<RunDiagnostic>             $diagnostics
     * @param list<\GruffPhp\Finding\Finding> $findings
     *
     * @return int Symfony command exit code.
     */
    private function resolveExitCode(array $diagnostics, array $findings, FailThreshold $failThreshold): int
    {
        if ($diagnostics !== []) {
            return Command::INVALID;
        }

        foreach ($findings as $finding) {
            if ($failThreshold->isTriggeredBy($finding->severity)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Render the report with the reporter selected by output format.
     *
     * @return void
     */
    private function renderReport(
        AnalysisReport $report,
        OutputFormat $format,
        OutputInterface $output,
        ?string $projectRoot = null,
        string $reportEditorLink = 'none',
        bool $isReportInteractive = false,
    ): void {
        $renderer = match ($format) {
            OutputFormat::Json => new JsonReporter(),
            OutputFormat::Html => new HtmlReporter($projectRoot ?? '', $reportEditorLink, $isReportInteractive),
            OutputFormat::Markdown => new MarkdownReporter(),
            OutputFormat::Github => new GithubAnnotationsReporter(),
            OutputFormat::Hotspot => new HotspotReporter(),
            OutputFormat::Sarif => new SarifReporter(),
            OutputFormat::Text => new TextReporter(),
        };
        // OUTPUT_RAW skips Symfony's OutputFormatter tag scan, which would otherwise
        // parse every <...> in HTML/JSON/Markdown output as a console style tag.
        $output->write($renderer->render($report), false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param list<Finding> $findings
     * @param list<string>  $changedFiles
     * @return list<Finding>
     */
    private function filterFindingsToChangedFiles(array $findings, array $changedFiles): array
    {
        if ($changedFiles === []) {
            return [];
        }

        $changed = array_fill_keys($changedFiles, true);

        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => isset($changed[$finding->filePath]),
        ));
    }

    /**
     * @param list<Finding>       $currentFindings
     * @param list<RunDiagnostic> $diagnostics
     *
     * @return BranchReviewResult|null Review comparison, or null when disabled/unavailable.
     */
    private function buildBranchReview(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleRegistry $registry,
        array $currentFindings,
        float $currentScore,
        ?DiffResult $reviewDiff,
        array &$diagnostics,
    ): ?BranchReviewResult {
        if ($options->diffVs === null || $reviewDiff === null) {
            return null;
        }

        $gitArchiveSnapshot       = new GitArchiveSnapshot();
        $baseRoot                 = null;
        $shouldLoadProjectContext = $this->shouldLoadChangedOnlyProjectContext($options, $registry, $config, $reviewDiff);
        $baseSnapshotPaths        = $this->baseSnapshotPaths($projectRoot, $options, $reviewDiff, $shouldLoadProjectContext);
        $baseAnalysisPaths        = $this->baseAnalysisPaths($projectRoot, $options, $reviewDiff);

        if ($options->isChangedOnly && !$shouldLoadProjectContext && $baseSnapshotPaths === []) {
            $baseScore = (new ScoreCalculator())->calculate([], null, null, scorePillars: $options->profileScorePillars());

            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          [],
                baseRef:       $options->diffVs,
                isChangedOnly: true,
                deltaScore:    $currentScore - $baseScore->composite->score,
            );
        }

        try {
            $baseRoot     = $gitArchiveSnapshot->create($projectRoot, $options->diffVs, $baseSnapshotPaths);
            $basePaths    = $this->existingSnapshotPaths($baseRoot, $baseAnalysisPaths);
            $baseFindings = [];

            if ($basePaths !== []) {
                $baseSources = (new AnalysisSourceLoader())->load(
                    $baseRoot,
                    $basePaths,
                    $options->shouldIncludeIgnored,
                    $config->ignoredPathPatterns(),
                );
                $baseRegistry            = RuleRegistry::defaults();
                $baseProjectContextUnits = $shouldLoadProjectContext
                    ? $this->baseProjectContextUnits($baseRoot, $options, $config)
                    : $baseSources->analysisUnits;
                $baseFindings = $baseRegistry->analyse($baseSources->analysisUnits, new RuleContext($baseRoot, $config), $baseProjectContextUnits);
                $baseFindings = array_merge($baseFindings, (new CompositeFindingFactory())->build($baseFindings));
                $baseFindings = $this->filterAllowedSecretPreviews($baseFindings, $config);
            }

            if ($options->isChangedOnly) {
                $baseFindings = $this->filterFindingsToChangedFiles($baseFindings, $reviewDiff->changedFiles);
            }

            if ($options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null) {
                try {
                    $baseFindings = (new BaselineApplication())->filterExisting($projectRoot, $options->baseline->baselinePath, $baseFindings);
                } catch (BaselineException $exception) {
                    $diagnostics[] = new RunDiagnostic(
                        type:    'baseline-error',
                        message: $exception->getMessage(),
                        path:    $options->baseline->baselinePath,
                    );
                }
            }

            $baseFindings = $this->normalizeFindingPaths($baseFindings, $options->pathsRelativeTo);
            $baseScore    = (new ScoreCalculator())->calculate($baseFindings, null, null, scorePillars: $options->profileScorePillars());

            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          $baseFindings,
                baseRef:       $options->diffVs,
                isChangedOnly: $options->isChangedOnly,
                deltaScore:    $currentScore - $baseScore->composite->score,
            );
        } catch (DiffException | RuntimeException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'review-mode-error',
                message: $exception->getMessage(),
            );

            return null;
        } finally {
            if ($baseRoot !== null) {
                $gitArchiveSnapshot->remove($baseRoot);
            }
        }
    }

    /**
     * Calculate the branch-review score from static-analysis findings only.
     *
     * @param list<Finding> $findings Current findings after diff and baseline filtering.
     * @return float Composite score used for branch-review delta comparison.
     */
    private function branchReviewScore(array $findings, AnalyseCommandOptions $options): float
    {
        $reviewFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->pillar !== Pillar::Mutation,
        ));

        return (new ScoreCalculator())->calculate($reviewFindings, null, null, scorePillars: $options->profileScorePillars())->composite->score;
    }

    /**
     * @return list<string>
     */
    private function baseSnapshotPaths(
        string $projectRoot,
        AnalyseCommandOptions $options,
        DiffResult $reviewDiff,
        bool $shouldLoadProjectContext,
    ): array
    {
        if (!$options->isChangedOnly) {
            return $this->normaliseRequestedPaths($projectRoot, $options->paths);
        }

        if ($shouldLoadProjectContext) {
            return [];
        }

        if ($reviewDiff->changedFiles === []) {
            return [];
        }

        if ($options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        $requestedPaths = $this->normaliseRequestedPaths($projectRoot, $options->paths);
        if ($requestedPaths === []) {
            return [];
        }

        $paths = array_values(array_filter(
            $reviewDiff->changedFiles,
            fn (string $changedFile): bool => $this->matchesRequestedPath($changedFile, $requestedPaths),
        ));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function baseAnalysisPaths(string $projectRoot, AnalyseCommandOptions $options, DiffResult $reviewDiff): array
    {
        if ($options->isChangedOnly && $options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        if ($options->paths === []) {
            return [];
        }

        return $this->normaliseRequestedPaths($projectRoot, $options->paths);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function existingSnapshotPaths(string $baseRoot, array $paths): array
    {
        $requested = $paths === [] ? ['.'] : $paths;
        $existing  = [];

        foreach ($requested as $path) {
            $absolute = PathHelper::resolveAgainst($baseRoot, $path);
            if (file_exists($absolute)) {
                $existing[] = PathHelper::relativeToRoot($absolute, $baseRoot) ?? $path;
            }
        }

        return $existing === [] ? [] : $existing;
    }

    /**
     * @return list<\GruffPhp\Parser\AnalysisUnit>
     */
    private function projectContextUnits(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleRegistry $registry,
        ?DiffResult $reviewDiff,
        AnalysisSourceSet $analysisSourceSet,
    ): array {
        if (!$this->shouldLoadChangedOnlyProjectContext($options, $registry, $config, $reviewDiff)) {
            return $analysisSourceSet->analysisUnits;
        }

        return (new AnalysisSourceLoader())->load(
            $projectRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /**
     * @return list<\GruffPhp\Parser\AnalysisUnit>
     */
    private function baseProjectContextUnits(string $baseRoot, AnalyseCommandOptions $options, AnalysisConfig $config): array
    {
        return (new AnalysisSourceLoader())->load(
            $baseRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /**
     * @return bool True when changed-only mode still needs complete context for project-level rules.
     */
    private function shouldLoadChangedOnlyProjectContext(
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        AnalysisConfig $config,
        ?DiffResult $reviewDiff,
    ): bool {
        return $options->isChangedOnly
            && $reviewDiff instanceof DiffResult
            && $reviewDiff->changedFiles !== []
            && $registry->hasEnabledProjectRules($config);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normaliseRequestedPaths(string $projectRoot, array $paths): array
    {
        $root       = rtrim(PathHelper::canonical($projectRoot), '/');
        $normalised = [];

        foreach ($paths as $path) {
            $candidate = PathHelper::normalizeSeparators($path);
            if ($candidate === '') {
                continue;
            }

            if (PathHelper::isAbsolute($candidate)) {
                $candidate = rtrim(PathHelper::canonical($candidate), '/');
                if ($candidate === $root) {
                    $candidate = '.';
                } elseif (str_starts_with($candidate, $root . '/')) {
                    $candidate = substr($candidate, strlen($root) + 1);
                } else {
                    continue;
                }
            }

            while (str_starts_with($candidate, './')) {
                $candidate = substr($candidate, 2);
            }

            $candidate                                        = rtrim($candidate, '/');
            $normalised[$candidate === '' ? '.' : $candidate] = $candidate === '' ? '.' : $candidate;
        }

        $paths = array_values($normalised);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param list<string> $requestedPaths
     *
     * @return bool True when a changed file is inside the requested path set.
     */
    private function matchesRequestedPath(string $changedFile, array $requestedPaths): bool
    {
        $changedFile = PathHelper::normalizeSeparators($changedFile);

        foreach ($requestedPaths as $requestedPath) {
            if ($requestedPath === '.') {
                return true;
            }

            if ($changedFile === $requestedPath || str_starts_with($changedFile, $requestedPath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function normalizeFindingPaths(array $findings, ?string $pathsRelativeTo): array
    {
        if ($pathsRelativeTo === null) {
            return $findings;
        }

        $realRoot = realpath($pathsRelativeTo);
        if ($realRoot === false) {
            return $findings;
        }

        $root       = rtrim(PathHelper::normalizeSeparators($realRoot), '/');
        $normalized = [];

        foreach ($findings as $finding) {
            $path = PathHelper::normalizeSeparators($finding->filePath);
            if (!PathHelper::isAbsolute($path)) {
                $normalized[] = $finding;
                continue;
            }

            $filePath     = str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $finding->filePath;
            $normalized[] = new Finding(
                ruleId:           $finding->ruleId,
                message:          $finding->message,
                filePath:         $filePath,
                line:             $finding->line,
                severity:         $finding->severity,
                pillar:           $finding->pillar,
                tier:             $finding->tier,
                confidence:       $finding->confidence,
                endLine:          $finding->endLine,
                column:           $finding->column,
                symbol:           $finding->symbol,
                remediation:      $finding->remediation,
                secondaryPillars: $finding->secondaryPillars,
                metadata:         $finding->metadata,
            );
        }

        return $normalized;
    }
}
