<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Baseline\BaselineApplication;
use GruffPhp\Baseline\BaselineReport;
use GruffPhp\Baseline\BaselineStore;
use GruffPhp\Console\Application;
use GruffPhp\Diff\DiffException;
use GruffPhp\Diff\DiffFindingFilter;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Diff\GitDiffProvider;
use GruffPhp\Diff\ChangedLineRange;
use GruffPhp\Diff\UnifiedDiffParser;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Command\Runtime\RuntimeTimingObserver;
use GruffPhp\Mutation\MutationAnalysisBuilder;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Mutation\MutationFindingFactory;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\FailThresholds;
use GruffPhp\Reporting\GithubAnnotationsReporter;
use GruffPhp\Reporting\HotspotReporter;
use GruffPhp\Reporting\HtmlReporter;
use GruffPhp\Reporting\JsonReporter;
use GruffPhp\Reporting\MarkdownReporter;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Reporting\SarifReporter;
use GruffPhp\Reporting\TextReporter;
use GruffPhp\Reporting\ThresholdTrip;
use GruffPhp\Review\BranchReviewResult;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Scoring\ScoreCalculator;
use GruffPhp\Scoring\ScoreReport;
use GruffPhp\Trend\TrendRecorder;
use GruffPhp\Trend\TrendReport;
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
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the run: advisory, warning, error, or none.', default: FailThreshold::Advisory->value)
            ->addOption('fail-on-new', null, InputOption::VALUE_NONE, 'Fail only on findings introduced by the change (requires --baseline or --diff-vs). Shorthand for failureConditions.newFindings.severityThresholds.error: 0.')
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
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Filter findings to changed regions. Bare uses working tree vs HEAD; use working-tree, staged, unstaged, a base ref, or "-" for unified diff on stdin.', default: null)
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Filter findings to files and regions changed since this Git base ref.')
            ->addOption('changed-ranges', null, InputOption::VALUE_REQUIRED, 'Filter findings to explicit line ranges, for example "3-3,8-10".')
            ->addOption('changed-scope', null, InputOption::VALUE_REQUIRED, 'Changed-region scope: symbol or hunk.', default: DiffFindingFilter::SCOPE_SYMBOL)
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
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable the on-disk result cache for this run (analyse every file fresh).')
            ->addOption('baseline-include-absent', null, InputOption::VALUE_NONE, 'With a baseline applied, list resolved (absent) baseline entries in text, markdown, and HTML output.')
            ->addOption('print-runtime', null, InputOption::VALUE_NONE, 'Emit performance instrumentation (wall, peak memory, phase, optional per-rule) as JSON on stderr.')
            ->addOption('runtime-mode', null, InputOption::VALUE_REQUIRED, 'Runtime payload detail: summary (default) or detailed (adds per-rule totals).', default: 'summary');
    }

    /**
     * Run source discovery, rule analysis, optional mutation ingestion, and reporting.
     *
     * @param InputInterface  $input - Parsed CLI arguments and options for this analyse run.
     * @param OutputInterface $output - Destination for the rendered report; stderr is used for runtime payloads.
     *
     * @return int - Symfony command exit code: SUCCESS, FAILURE on a tripped gate, or INVALID on a run diagnostic.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtimeStart             = hrtime(true);
        $printRuntime             = (bool)$input->getOption('print-runtime');
        $runtimeModeOpt           = $input->getOption('runtime-mode');
        $runtimeDetailed          = $printRuntime && $runtimeModeOpt === 'detailed';
        $runtimeTimingObserver    = $runtimeDetailed ? new RuntimeTimingObserver() : null;
        $shouldListAbsentBaseline = (bool)$input->getOption('baseline-include-absent');
        $findingSupport           = new AnalysisFindingSupport();
        $branchReviewBuilder      = new BranchReviewBuilder();

        $setupResult = (new AnalyseCommandSetupBuilder())->build($input, $output, $this->getApplication());

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
        $diff          = $this->buildChangedDiffResult($projectRoot, $options, $diagnostics);
        $analysisPaths = $this->currentAnalysisPaths($projectRoot, $options, $reviewDiff, $diff);
        $discoverStart = hrtime(true);

        $ruleContext         = new RuleContext($projectRoot, $config);
        $analysisPipeline    = new AnalysisPipeline($registry, $branchReviewBuilder->projectContextUnits(...));
        $analysisRun         = $analysisPipeline->runAnalysis(
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
        $mutationAnalysis    = (new MutationAnalysisBuilder())->build(
            $projectRoot,
            $options->mutation,
            $diagnostics,
        );

        if ($mutationAnalysis instanceof MutationAnalysisResult) {
            $findings = array_merge($findings, (new MutationFindingFactory())->findingsFor($mutationAnalysis));
        }

        if ($options->diffVs !== null && $options->isChangedOnly && $reviewDiff instanceof DiffResult) {
            $findings = $findingSupport->filterFindingsToChangedFiles($findings, $reviewDiff->changedFiles);
        }

        $suppressedCount = null;
        if ($diff instanceof DiffResult && $diff->active) {
            $diffFilterResult = (new DiffFindingFilter())->apply($findings, $diff, $sources->analysisUnits, $options->changedScope);
            $findings         = $diffFilterResult->findings;
            $suppressedCount  = $diffFilterResult->suppressedCount;
            $diff             = $diff->withSuppressedCount($suppressedCount);
        }

        $findings       = $findingSupport->filterAllowedSecretPreviews($findings, $config);
        $baselineReport = (new BaselineApplication())->apply(
            projectRoot:     $projectRoot,
            options:         $options->baseline,
            findings:        $findings,
            diff:            $diff,
            diagnostics:     $diagnostics,
            hasPartialScope: $options->diffVs !== null && $options->isChangedOnly,
        );
        $findings       = $findingSupport->normalizeFindingPaths($findings, $options->pathsRelativeTo);

        $scoreStart     = hrtime(true);
        $score          = (new ScoreCalculator())->calculate($findings, $mutationAnalysis, $diff, scorePillars: $options->profileScorePillars(), analysisConfig: $config);
        $scoreNs        = hrtime(true) - $scoreStart;
        $reviewFindings = $options->diffVs === null ? $findings : array_values(array_filter(
                                                                                   $findings,
                                                                                   static fn(Finding $finding): bool => $finding->pillar !== Pillar::Mutation,
                                                                               ));
        $reviewScore    = $options->diffVs === null
            ? $score->composite->score
            : (new ScoreCalculator())->calculate($reviewFindings, null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config)->composite->score;
        $review         = $branchReviewBuilder->build(
            projectRoot:     $projectRoot,
            options:         $options,
            config:          $config,
            registry:        $registry,
            currentFindings: $reviewFindings,
            currentScore:    $reviewScore,
            reviewDiff:      $reviewDiff,
            diagnostics:     $diagnostics,
        );
        $trend          = $this->recordTrend(
            projectRoot:  $projectRoot,
            options:      $options,
            score:        $score,
            findingCount: count($findings),
            diagnostics:  $diagnostics,
        );

        $newFindings      = $this->newFindingsForGate($findings, $review, $baselineReport);
        $gate             = $this->resolveExitCode($diagnostics, $findings, $newFindings, $setup->failThresholds);
        $exitCode         = $gate['exitCode'];
        $failureReason    = $gate['trip'];
        $newFindingsCount = $setup->failThresholds->newFindingsGate instanceof FailThresholds ? count($newFindings) : null;
        $displayFilter    = $options->displayFilter();
        $displayFindings  = $displayFilter->apply($findings);
        $displayReview    = $review?->filtered(fn(array $reviewFindings): array => $displayFilter->apply($reviewFindings));
        $analysisReport   = new AnalysisReport(
            toolVersion:              Application::VERSION,
            requestedPaths:           $options->paths,
            format:                   $format->value,
            failOn:                   $failThreshold->value,
            filesDiscovered:          count($sources->discovery->files),
            filesParsed:              $sources->parsedFileCount(),
            ignoredPaths:             $sources->discovery->ignoredPaths,
            ignoredPathDetails:       $sources->discovery->ignoredPathDetails,
            missingPaths:             $sources->discovery->missingPaths,
            diagnostics:              $diagnostics,
            findings:                 $displayFindings,
            exitCode:                 $exitCode,
            configPath:               $setup->configPath,
            mutation:                 $mutationAnalysis,
            score:                    $score,
            diff:                     $diff,
            trend:                    $trend,
            baseline:                 $baselineReport,
            review:                   $displayReview,
            filters:                  $displayFilter,
            suppressedCount:          $suppressedCount,
            shouldListAbsentBaseline: $shouldListAbsentBaseline,
            failureReason:            $failureReason,
            newFindingsCount:         $newFindingsCount,
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

        $this->emitRuntimePayload(
            shouldEmit:            $printRuntime,
            output:                $output,
            runtimeStart:          $runtimeStart,
            phaseDurationsNs:      [
                                       'discoverParseNs' => $discoverParseNs,
                                       'analyseNs'       => $analyseNs,
                                       'scoreNs'         => $scoreNs,
                                       'reportNs'        => $reportNs,
                                   ],
            filesParsed:           $sources->parsedFileCount(),
            rulesExecuted:         count($registry->enabledRules($config)),
            runtimeTimingObserver: $runtimeTimingObserver,
            isDetailed:            $runtimeDetailed,
        );

        return $exitCode;
    }

    /**
     * Write the performance instrumentation payload as a single JSON line on stderr.
     *
     * @param bool                                                                     $shouldEmit - Whether --print-runtime requested the
     *                                                                                                        payload; a no-op when false.
     * @param OutputInterface                                                          $output - Run output; the payload goes to its
     *                                                                                                        stderr stream, falling back to STDERR.
     * @param int                                                                      $runtimeStart - hrtime(true) nanosecond marker captured
     *                                                                                                        at command start, used to derive wall
     *                                                                                                        time.
     * @param array{discoverParseNs: int, analyseNs: int, scoreNs: int, reportNs: int} $phaseDurationsNs - Timed analyse phase durations in
     *                                                                                                        nanoseconds.
     * @param int                                                                      $filesParsed - Count of source files actually parsed
     *                                                                                                        this run.
     * @param int                                                                      $rulesExecuted - Count of rules enabled for this run.
     * @param RuntimeTimingObserver|null                                               $runtimeTimingObserver - Per-rule timings source; null unless
     *                                                                                                        detailed mode ran.
     * @param bool                                                                     $isDetailed - Whether to attach per-rule totals;
     *                                                                                                        requires a non-null observer to take
     *                                                                                                        effect.
     *
     * @return void
     */
    private function emitRuntimePayload(
        bool                   $shouldEmit,
        OutputInterface        $output,
        int                    $runtimeStart,
        array                  $phaseDurationsNs,
        int                    $filesParsed,
        int                    $rulesExecuted,
        ?RuntimeTimingObserver $runtimeTimingObserver,
        bool                   $isDetailed,
    ): void {
        if (!$shouldEmit) {
            return;
        }

        $totalNs = hrtime(true) - $runtimeStart;
        $payload = [
            'wallMs'        => (int)round($totalNs / 1_000_000),
            'peakBytes'     => memory_get_peak_usage(true),
            'filesParsed'   => $filesParsed,
            'rulesExecuted' => $rulesExecuted,
            'phases'        => $phaseDurationsNs,
            'mode'          => $isDetailed ? 'detailed' : 'summary',
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
     * @param AnalyseCommandSetupResult $result - Failed setup outcome with the error, exit code, and any partial report.
     * @param OutputInterface           $output - Destination the error text or formatted report is written to.
     *
     * @return int - the setup result's own exit code, returned after emitting its error text or partial report.
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
     * Resolve the Git diff for --diff-vs or --since against a single base ref.
     *
     * @param string              $projectRoot - Project root the Git diff is computed within.
     * @param string|null         $diffMode - Git ref or diff selector to compare against; null means no diff was requested.
     * @param list<RunDiagnostic> $diagnostics - Run diagnostics; a diff-mode error is appended in place on failure.
     *
     * @return DiffResult|null - changed lines for the ref, an inactive result when no ref was requested, or null when the Git lookup failed.
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
     * Build the changed-region diff result requested by --diff, --since, or --changed-ranges.
     *
     * @param string                $projectRoot - Project root the diff and requested paths resolve against.
     * @param AnalyseCommandOptions $options - CLI options selecting the changed-region source (ranges, since, or diff).
     * @param list<RunDiagnostic>   $diagnostics - Run diagnostics; a diff-mode error is appended in place on failure.
     *
     * @return DiffResult|null - the active changed-region diff for the selected source, an inactive result, or null when the lookup failed.
     */
    private function buildChangedDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        if ($options->changedRanges !== null) {
            return $this->buildExplicitRangesDiffResult($projectRoot, $options, $diagnostics);
        }

        if ($options->since !== null) {
            return $this->buildDiffResult($projectRoot, $options->since, $diagnostics);
        }

        if ($options->diffMode === '-') {
            $patch = stream_get_contents(STDIN);
            if ($patch === false) {
                $diagnostics[] = new RunDiagnostic(
                    type:    'diff-mode-error',
                    message: 'Unable to read unified diff from stdin.',
                );

                return null;
            }

            $parsed = (new UnifiedDiffParser())->parse($patch);

            return new DiffResult(
                active:       true,
                mode:         'stdin',
                base:         null,
                changedLines: $parsed['lines'],
                changedFiles: $parsed['files'],
                message:      'Diff mode filters findings to changed regions from unified diff stdin.',
            );
        }

        return $this->buildDiffResult($projectRoot, $options->diffMode, $diagnostics);
    }

    /**
     * Build the changed-region diff result from explicit --changed-ranges line ranges.
     *
     * @param string                $projectRoot - Project root the requested paths resolve against.
     * @param AnalyseCommandOptions $options - Effective CLI options carrying paths and the changed ranges.
     * @param list<RunDiagnostic>   $diagnostics - Run diagnostics; diff-mode errors are appended in place.
     *
     * @return DiffResult|null - an active explicit-ranges diff over the requested files, or null when the ranges or paths are invalid.
     */
    private function buildExplicitRangesDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        $changedFiles = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        if ($changedFiles === []) {
            $diagnostics[] = new RunDiagnostic(
                type:    'diff-mode-error',
                message: '--changed-ranges requires at least one file path.',
            );

            return null;
        }

        try {
            $ranges = $this->parseChangedRanges($options->changedRanges ?? '');
        } catch (DiffException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'diff-mode-error',
                message: $exception->getMessage(),
            );

            return null;
        }

        $changedLines = [];
        foreach ($changedFiles as $changedFile) {
            $changedLines[$changedFile] = $ranges;
        }

        return new DiffResult(
            active:       true,
            mode:         'explicit-ranges',
            base:         null,
            changedLines: $changedLines,
            changedFiles: $changedFiles,
            message:      'Diff mode filters findings to explicit changed line ranges.',
        );
    }

    /**
     * Parse a --changed-ranges value like "3-3,8-10" into line ranges.
     *
     * @param string $ranges - Comma-separated 1-based line ranges.
     *
     * @return list<ChangedLineRange> - the parsed 1-based line ranges, preserving the order they appeared in the input.
     * @throws DiffException When a range token is malformed or the value yields no ranges.
     */
    private function parseChangedRanges(string $ranges): array
    {
        $parsed = [];

        foreach (explode(',', $ranges) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Match a single line number or a "start-end" range, both 1-based.
            if (!preg_match('/^(\d+)(?:-(\d+))?$/', $part, $matches)) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $startLine = (int)$matches[1];
            $endLine   = isset($matches[2]) ? (int)$matches[2] : $startLine;

            if ($startLine < 1 || $endLine < $startLine) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $parsed[] = new ChangedLineRange($startLine, $endLine);
        }

        if ($parsed === []) {
            throw new DiffException('--changed-ranges requires at least one range like "3-3,8-10".');
        }

        return $parsed;
    }

    /**
     * Resolve which paths discovery should scan, narrowing to changed files when a diff-driven mode is active.
     *
     * @param string                $projectRoot - Project root the requested and changed paths resolve against.
     * @param AnalyseCommandOptions $options - Effective CLI options, including changed-only and requested-path flags.
     * @param DiffResult|null       $reviewDiff - --diff-vs review diff; null when it failed or carries no changed files.
     * @param DiffResult|null       $changedRegionDiff - Changed-region diff from --diff/--since/--changed-ranges, when active.
     *
     * @return list<string>|null - the paths discovery should scan; null means a changed-only review with no files to scan (distinct from scanning
     *                           everything).
     */
    private function currentAnalysisPaths(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult           $reviewDiff,
        ?DiffResult           $changedRegionDiff,
    ): ?array {
        if ($options->isChangedOnly && $options->paths === [] && $reviewDiff === null) {
            return null;
        }

        $findingSupport = new AnalysisFindingSupport();

        if ($options->usesChangedFilesForDiscovery() && $changedRegionDiff instanceof DiffResult && $changedRegionDiff->active) {
            $changedFiles = $findingSupport->existingChangedFiles($projectRoot, $changedRegionDiff->changedFiles);
            if ($changedFiles === []) {
                return null;
            }

            if ($options->paths === []) {
                return $changedFiles;
            }

            $requestedPaths = $findingSupport->normaliseRequestedPaths($projectRoot, $options->paths);
            $analysisPaths  = array_values(array_filter(
                                               $changedFiles,
                                               fn(string $changedFile): bool => $findingSupport->matchesRequestedPath($changedFile, $requestedPaths),
                                           ));
            sort($analysisPaths, SORT_STRING);

            return $analysisPaths === [] ? null : $analysisPaths;
        }

        if (!$options->isChangedOnly || $options->paths !== [] || !$reviewDiff instanceof DiffResult) {
            return $options->paths;
        }

        return $reviewDiff->changedFiles === [] ? null : $reviewDiff->changedFiles;
    }

    /**
     * Filter source diagnostics for the current analysis scope.
     *
     * @param list<RunDiagnostic>   $diagnostics - Source-discovery diagnostics gathered before scope narrowing.
     * @param string                $projectRoot - Project root each diagnostic path is normalised against.
     * @param AnalyseCommandOptions $options - Effective CLI options; only changed-only review runs trigger filtering.
     * @param DiffResult|null       $reviewDiff - --diff-vs review diff supplying the changed-file allowlist, when present.
     *
     * @return list<RunDiagnostic> - diagnostics in scope: identical input outside a changed-only review, else with out-of-scope missing-path entries
     *                             dropped
     */
    private function filterSourceDiagnostics(
        array                 $diagnostics,
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult           $reviewDiff,
    ): array {
        if (!$options->isChangedOnly || !$reviewDiff instanceof DiffResult || $reviewDiff->changedFiles === []) {
            return $diagnostics;
        }

        $findingSupport = new AnalysisFindingSupport();

        return array_values(array_filter(
                                $diagnostics,
                                function (RunDiagnostic $diagnostic) use ($projectRoot, $reviewDiff, $findingSupport): bool {
                                    if ($diagnostic->type !== 'missing-path' || $diagnostic->path === null) {
                                        return true;
                                    }

                                    $requestedPaths = $findingSupport->normaliseRequestedPaths($projectRoot, [$diagnostic->path]);
                                    if ($requestedPaths === []) {
                                        return true;
                                    }

                                    foreach ($reviewDiff->changedFiles as $changedFile) {
                                        if ($findingSupport->matchesRequestedPath($changedFile, $requestedPaths)) {
                                            return false;
                                        }
                                    }

                                    return true;
                                },
                            ));
    }

    /**
     * Decide the command exit code from run diagnostics and whether any fail threshold tripped.
     *
     * @param list<RunDiagnostic>             $diagnostics - Run diagnostics; any present force INVALID ahead of findings.
     * @param list<\GruffPhp\Finding\Finding> $findings - Post-baseline finding set the all-findings gate inspects.
     * @param list<\GruffPhp\Finding\Finding> $newFindings - Change-introduced subset the new-findings gate inspects.
     * @param FailThresholds                  $failThresholds - Configured gate that decides which findings cause failure.
     *
     * @return array{exitCode: int, trip: ThresholdTrip|null} - the resolved exit code plus the breached gate threshold (null unless one tripped).
     */
    private function resolveExitCode(array $diagnostics, array $findings, array $newFindings, FailThresholds $failThresholds): array
    {
        if ($diagnostics !== []) {
            return ['exitCode' => Command::INVALID, 'trip' => null];
        }

        $trip = $failThresholds->tripsOnScope($findings, $newFindings);

        return [
            'exitCode' => $trip instanceof ThresholdTrip ? Command::FAILURE : Command::SUCCESS,
            'trip'     => $trip,
        ];
    }

    /**
     * Resolve the new-findings set the gate evaluates.
     *
     * When --diff-vs is active the branch-introduced set is used (already
     * post-baseline ∩ branch-introduced, since the comparison runs on post-baseline
     * findings); otherwise the post-baseline finding set is the baseline-new set.
     * The setup builder guarantees a reference point exists before this runs.
     *
     * @param list<\GruffPhp\Finding\Finding> $findings - Post-baseline findings for the run.
     * @param BranchReviewResult|null         $review - Branch-review result when --diff-vs is active.
     * @param BaselineReport|null             $baseline - Baseline application result, when a baseline ran.
     *
     * @return list<\GruffPhp\Finding\Finding> - the change-introduced findings the new-findings gate scores; empty when no reference point applies.
     */
    private function newFindingsForGate(array $findings, ?BranchReviewResult $review, ?BaselineReport $baseline): array
    {
        if ($review instanceof BranchReviewResult) {
            return $review->introduced;
        }

        if ($baseline instanceof BaselineReport && !$baseline->generated) {
            return $findings;
        }

        return [];
    }

    /**
     * Render the report with the reporter selected by output format.
     *
     * @param AnalysisReport  $report - Completed analysis result the chosen reporter serialises.
     * @param OutputFormat    $format - Output format that selects which reporter renders the result.
     * @param OutputInterface $output - Stream the rendered report is written to, raw and unformatted.
     * @param string|null     $projectRoot - Project root for HTML file:line links; defaults to empty when not supplied.
     * @param string          $reportEditorLink - HTML editor-link style (vscode, phpstorm, or none); ignored by other formats.
     * @param bool            $isReportInteractive - Whether HTML output renders the opt-in interactive finding filters.
     *
     * @return void
     */
    private function renderReport(
        AnalysisReport  $report,
        OutputFormat    $format,
        OutputInterface $output,
        ?string         $projectRoot = null,
        string          $reportEditorLink = 'none',
        bool            $isReportInteractive = false,
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
     * Append a score-trend entry to the history file when one is configured.
     *
     * @param string                $projectRoot - Project root the history file resolves against.
     * @param AnalyseCommandOptions $options - Effective CLI options carrying the history-file path.
     * @param ScoreReport           $score - Composite score recorded for this run.
     * @param int                   $findingCount - Number of findings recorded alongside the score.
     * @param list<RunDiagnostic>   $diagnostics - Run diagnostics; a history error is appended in place.
     *
     * @return TrendReport|null - the appended trend entry, or null when no --history-file is set or recording failed (a diagnostic is added on
     *                          failure).
     */
    private function recordTrend(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ScoreReport           $score,
        int                   $findingCount,
        array                 &$diagnostics,
    ): ?TrendReport {
        if ($options->historyFile === null) {
            return null;
        }

        try {
            return (new TrendRecorder())->record($projectRoot, $options->historyFile, $score, $findingCount);
        } catch (JsonException|RuntimeException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'history-error',
                message: $exception->getMessage(),
                path:    $options->historyFile,
            );

            return null;
        }
    }
}
