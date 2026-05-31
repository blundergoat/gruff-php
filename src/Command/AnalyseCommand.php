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
     * @param InputInterface  $input  Parsed CLI arguments and options for this analyse run.
     * @param OutputInterface $output Destination for the rendered report; stderr is used for runtime payloads.
     * @return int Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtimeStart          = hrtime(true);
        $printRuntime          = (bool) $input->getOption('print-runtime');
        $runtimeModeOpt        = $input->getOption('runtime-mode');
        $runtimeDetailed       = $printRuntime && $runtimeModeOpt === 'detailed';
        $runtimeTimingObserver = $runtimeDetailed ? new RuntimeTimingObserver() : null;
        $shouldListAbsentBaseline = (bool) $input->getOption('baseline-include-absent');
        $findingSupport           = new AnalysisFindingSupport();
        $branchReviewBuilder      = new BranchReviewBuilder();

        $setupResult = (new AnalyseCommandSetupBuilder())->build($input, $output, $this->getApplication());

        if (!$setupResult->setup instanceof AnalyseCommandSetup) {
            // Bail before any analysis when config/setup failed; the failure carries its own exit code and report.
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

        $ruleContext      = new RuleContext($projectRoot, $config);
        $analysisPipeline = new AnalysisPipeline($registry, $branchReviewBuilder->projectContextUnits(...));
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

        if ($options->diffVs !== null && $options->isChangedOnly && $reviewDiff instanceof DiffResult) {
            $findings = $findingSupport->filterFindingsToChangedFiles($findings, $reviewDiff->changedFiles);
        }

        $suppressedCount = null;
        if ($diff instanceof DiffResult && $diff->active) {
            $diffFilterResult = (new DiffFindingFilter())->apply($findings, $diff, $sources->analysisUnits, $options->changedScope);
            $findings         = $diffFilterResult->findings;
            $suppressedCount  = $diffFilterResult->suppressedCount;
        }

        $findings       = $findingSupport->filterAllowedSecretPreviews($findings, $config);
        $baselineReport = (new BaselineApplication())->apply(
            projectRoot: $projectRoot,
            options:     $options->baseline,
            findings:    $findings,
            diff:        $diff,
            diagnostics: $diagnostics,
        );
        $findings = $findingSupport->normalizeFindingPaths($findings, $options->pathsRelativeTo);

        $scoreStart     = hrtime(true);
        $score          = (new ScoreCalculator())->calculate($findings, $mutationAnalysis, $diff, scorePillars: $options->profileScorePillars(), analysisConfig: $config);
        $scoreNs        = hrtime(true) - $scoreStart;
        $reviewFindings = $options->diffVs === null ? $findings : array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->pillar !== Pillar::Mutation,
        ));
        $reviewScore = $options->diffVs === null
            ? $score->composite->score
            : (new ScoreCalculator())->calculate($reviewFindings, null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config)->composite->score;
        $review = $branchReviewBuilder->build(
            projectRoot:     $projectRoot,
            options:         $options,
            config:          $config,
            registry:        $registry,
            currentFindings: $findings,
            currentScore:    $reviewScore,
            reviewDiff:      $reviewDiff,
            diagnostics:     $diagnostics,
        );
        $trend = $this->recordTrend(
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
            ignoredPathDetails: $sources->discovery->ignoredPathDetails,
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
            suppressedCount: $suppressedCount,
            shouldListAbsentBaseline: $shouldListAbsentBaseline,
            failureReason:   $failureReason,
            newFindingsCount: $newFindingsCount,
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
            shouldEmit:       $printRuntime,
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

        // Propagate the gate verdict so CI can fail the run on the configured severity or new-findings threshold.
        return $exitCode;
    }

    /**
     * Write the performance instrumentation payload as a single JSON line on stderr.
     *
     * @param bool $shouldEmit Whether --print-runtime requested the payload; a no-op when false.
     * @param OutputInterface $output Run output; the payload goes to its stderr stream, falling back to STDERR.
     * @param int $runtimeStart hrtime(true) nanosecond marker captured at command start, used to derive wall time.
     * @param array{discoverParseNs: int, analyseNs: int, scoreNs: int, reportNs: int} $phaseDurationsNs Timed analyse phase durations in nanoseconds.
     * @param int $filesParsed Count of source files actually parsed this run.
     * @param int $rulesExecuted Count of rules enabled for this run.
     * @param RuntimeTimingObserver|null $runtimeTimingObserver Per-rule timings source; null unless detailed mode collected them.
     * @param bool $isDetailed Whether to attach per-rule totals; requires a non-null observer to take effect.
     * @return void
     */
    private function emitRuntimePayload(
        bool $shouldEmit,
        OutputInterface $output,
        int $runtimeStart,
        array $phaseDurationsNs,
        int $filesParsed,
        int $rulesExecuted,
        ?RuntimeTimingObserver $runtimeTimingObserver,
        bool $isDetailed,
    ): void {
        if (!$shouldEmit) {
            // No --print-runtime, so emit nothing and leave the report stream untouched.
            return;
        }

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

            // Console error stream handled the payload; skip the raw STDERR fallback to avoid a duplicate line.
            return;
        }

        fwrite(STDERR, $line);
    }

    /**
     * Render setup errors using either plain console text or the requested report format.
     *
     * @param AnalyseCommandSetupResult $result Failed setup outcome carrying the error, exit code, and any partial report.
     * @param OutputInterface $output Destination the error text or formatted report is written to.
     * @return int Setup failure exit code.
     */
    private function renderSetupFailure(AnalyseCommandSetupResult $result, OutputInterface $output): int
    {
        if ($result->plainError !== null) {
            $output->writeln($result->plainError);

            // A plain console message means config never resolved a format, so stop here with its exit code.
            return $result->exitCode;
        }

        if ($result->report instanceof AnalysisReport && $result->format instanceof OutputFormat) {
            $this->renderReport($result->report, $result->format, $output);
        }

        // Hand back the setup exit code after emitting whatever structured report the failure carried.
        return $result->exitCode;
    }

    /**
     * Resolve the Git diff for --diff-vs or --since against a single base ref.
     *
     * @param string $projectRoot Project root the Git diff is computed within.
     * @param string|null $diffMode Git ref or diff selector to compare against; null means no diff was requested.
     * @param list<RunDiagnostic> $diagnostics Run diagnostics; a diff-mode error is appended in place on failure.
     * @return DiffResult|null Diff result, inactive result, or null when diff lookup fails.
     */
    private function buildDiffResult(string $projectRoot, ?string $diffMode, array &$diagnostics): ?DiffResult
    {
        if ($diffMode === null) {
            // No ref requested, so report an inactive diff rather than a lookup failure.
            return DiffResult::inactive();
        }

        try {
            // Success path: compute changed lines for the requested ref.
            return (new GitDiffProvider())->changedLines($projectRoot, $diffMode);
        } catch (DiffException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'diff-mode-error',
                message: $exception->getMessage(),
            );

            // Null signals a hard diff failure (recorded as a diagnostic) versus an intentionally inactive diff.
            return null;
        }
    }

    /**
     * Build the changed-region diff result requested by --diff, --since, or --changed-ranges.
     *
     * @param string $projectRoot Project root the diff and requested paths resolve against.
     * @param AnalyseCommandOptions $options Effective CLI options selecting the changed-region source (ranges, since, or diff).
     * @param list<RunDiagnostic> $diagnostics Run diagnostics; a diff-mode error is appended in place on failure.
     * @return DiffResult|null Diff result, inactive result, or null when diff lookup fails.
     */
    private function buildChangedDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        if ($options->changedRanges !== null) {
            // Explicit ranges take precedence over Git-derived diffs.
            return $this->buildExplicitRangesDiffResult($projectRoot, $options, $diagnostics);
        }

        if ($options->since !== null) {
            // --since names a single base ref, so reuse the single-ref diff path.
            return $this->buildDiffResult($projectRoot, $options->since, $diagnostics);
        }

        if ($options->diffMode === '-') {
            $patch = stream_get_contents(STDIN);
            if ($patch === false) {
                $diagnostics[] = new RunDiagnostic(
                    type:    'diff-mode-error',
                    message: 'Unable to read unified diff from stdin.',
                );

                // Unreadable stdin is a hard failure; record it and signal no usable diff.
                return null;
            }

            $parsed = (new UnifiedDiffParser())->parse($patch);

            // Wrap the parsed stdin patch as an active diff so findings filter to its changed regions.
            return new DiffResult(
                active:       true,
                mode:         'stdin',
                base:         null,
                changedLines: $parsed['lines'],
                changedFiles: $parsed['files'],
                message:      'Diff mode filters findings to changed regions from unified diff stdin.',
            );
        }

        // Remaining case: --diff with a ref (or bare), handled by the single-ref Git diff path.
        return $this->buildDiffResult($projectRoot, $options->diffMode, $diagnostics);
    }

    /**
     * Build the changed-region diff result from explicit --changed-ranges line ranges.
     *
     * @param string                $projectRoot Project root the requested paths resolve against.
     * @param AnalyseCommandOptions $options     Effective CLI options carrying paths and the changed ranges.
     * @param list<RunDiagnostic>   $diagnostics Run diagnostics; diff-mode errors are appended in place.
     * @return DiffResult|null Diff result, or null when the ranges or requested paths are invalid.
     */
    private function buildExplicitRangesDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        $changedFiles = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        if ($changedFiles === []) {
            $diagnostics[] = new RunDiagnostic(
                type:    'diff-mode-error',
                message: '--changed-ranges requires at least one file path.',
            );

            // Explicit ranges are meaningless without a target file, so abort with the diagnostic above.
            return null;
        }

        try {
            $ranges = $this->parseChangedRanges($options->changedRanges ?? '');
        } catch (DiffException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'diff-mode-error',
                message: $exception->getMessage(),
            );

            // Malformed range syntax is recorded as a diagnostic; no usable diff to return.
            return null;
        }

        $changedLines = [];
        foreach ($changedFiles as $changedFile) {
            $changedLines[$changedFile] = $ranges;
        }

        // Apply the same parsed ranges to every requested file as an active explicit-ranges diff.
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
     * @param string $ranges Comma-separated 1-based line ranges.
     * @throws DiffException When a range token is malformed or the value yields no ranges.
     * @return list<ChangedLineRange> Parsed line ranges in input order.
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

            $startLine = (int) $matches[1];
            $endLine   = isset($matches[2]) ? (int) $matches[2] : $startLine;

            if ($startLine < 1 || $endLine < $startLine) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $parsed[] = new ChangedLineRange($startLine, $endLine);
        }

        if ($parsed === []) {
            throw new DiffException('--changed-ranges requires at least one range like "3-3,8-10".');
        }

        // Hand back the parsed ranges in input order; emptiness was already rejected above.
        return $parsed;
    }

    /**
     * Resolve which paths discovery should scan, narrowing to changed files when a diff-driven mode is active.
     *
     * @param string $projectRoot Project root the requested and changed paths resolve against.
     * @param AnalyseCommandOptions $options Effective CLI options, including changed-only and requested-path flags.
     * @param DiffResult|null $reviewDiff --diff-vs review diff; null when it failed or carries no changed files.
     * @param DiffResult|null $changedRegionDiff Changed-region diff from --diff/--since/--changed-ranges, when active.
     * @return list<string>|null Null means an empty changed-only review diff has no files to scan.
     */
    private function currentAnalysisPaths(
        string $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult $reviewDiff,
        ?DiffResult $changedRegionDiff,
    ): ?array
    {
        if ($options->isChangedOnly && $options->paths === [] && $reviewDiff === null) {
            // Changed-only with no paths and a failed review diff: nothing to scan, distinct from "scan everything".
            return null;
        }

        $findingSupport = new AnalysisFindingSupport();

        if ($options->usesChangedFilesForDiscovery() && $changedRegionDiff instanceof DiffResult && $changedRegionDiff->active) {
            $changedFiles = $findingSupport->existingChangedFiles($projectRoot, $changedRegionDiff->changedFiles);
            if ($changedFiles === []) {
                // Diff named only deleted/absent files, so there is nothing on disk left to scan.
                return null;
            }

            if ($options->paths === []) {
                // No path filter given, so scan every changed file that still exists.
                return $changedFiles;
            }

            $requestedPaths = $findingSupport->normaliseRequestedPaths($projectRoot, $options->paths);
            $analysisPaths  = array_values(array_filter(
                $changedFiles,
                fn (string $changedFile): bool => $findingSupport->matchesRequestedPath($changedFile, $requestedPaths),
            ));
            sort($analysisPaths, SORT_STRING);

            // Scan the changed files that also match the requested paths; null when the intersection is empty.
            return $analysisPaths === [] ? null : $analysisPaths;
        }

        if (!$options->isChangedOnly || $options->paths !== [] || !$reviewDiff instanceof DiffResult) {
            // Not a changed-only review, so let discovery handle the requested paths verbatim.
            return $options->paths;
        }

        // Changed-only review fallback: scan the review's changed files, or nothing when it changed none.
        return $reviewDiff->changedFiles === [] ? null : $reviewDiff->changedFiles;
    }

    /**
     * Filter source diagnostics for the current analysis scope.
     *
     * @param list<RunDiagnostic> $diagnostics Source-discovery diagnostics gathered before scope narrowing.
     * @param string $projectRoot Project root each diagnostic path is normalised against.
     * @param AnalyseCommandOptions $options Effective CLI options; only changed-only review runs trigger filtering.
     * @param DiffResult|null $reviewDiff --diff-vs review diff supplying the changed-file allowlist, when present.
     * @return list<RunDiagnostic>
     */
    private function filterSourceDiagnostics(
        array $diagnostics,
        string $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult $reviewDiff,
    ): array {
        if (!$options->isChangedOnly || !$reviewDiff instanceof DiffResult || $reviewDiff->changedFiles === []) {
            // Outside a changed-only review there is no scope to narrow, so keep every diagnostic.
            return $diagnostics;
        }

        $findingSupport = new AnalysisFindingSupport();

        // Drop missing-path noise for files the review didn't touch; keep everything else.
        return array_values(array_filter(
            $diagnostics,
            function (RunDiagnostic $diagnostic) use ($projectRoot, $reviewDiff, $findingSupport): bool {
                if ($diagnostic->type !== 'missing-path' || $diagnostic->path === null) {
                    // Only missing-path diagnostics are scope-sensitive; keep all other types.
                    return true;
                }

                $requestedPaths = $findingSupport->normaliseRequestedPaths($projectRoot, [$diagnostic->path]);
                if ($requestedPaths === []) {
                    // Path could not be normalised, so keep the diagnostic rather than silently hide it.
                    return true;
                }

                foreach ($reviewDiff->changedFiles as $changedFile) {
                    if ($findingSupport->matchesRequestedPath($changedFile, $requestedPaths)) {
                        // The missing path is in the review scope, so suppress this out-of-scope noise.
                        return false;
                    }
                }

                // Path lies outside every changed file, so the diagnostic is relevant and stays.
                return true;
            },
        ));
    }

    /**
     * Decide the command exit code from run diagnostics and whether any fail threshold tripped.
     *
     * @param list<RunDiagnostic>             $diagnostics Run diagnostics; any present force an INVALID exit ahead of findings.
     * @param list<\GruffPhp\Finding\Finding> $findings    Full post-baseline finding set the all-findings gate inspects.
     * @param list<\GruffPhp\Finding\Finding> $newFindings Change-introduced subset the new-findings gate inspects.
     * @param FailThresholds                  $failThresholds Configured gate that decides which findings cause failure.
     *
     * @return array{exitCode: int, trip: ThresholdTrip|null} Exit code, with the breached gate threshold when one tripped.
     */
    private function resolveExitCode(array $diagnostics, array $findings, array $newFindings, FailThresholds $failThresholds): array
    {
        if ($diagnostics !== []) {
            // A run diagnostic means the analysis itself was unsound, so report INVALID regardless of findings.
            return ['exitCode' => Command::INVALID, 'trip' => null];
        }

        $trip = $failThresholds->tripsOnScope($findings, $newFindings);

        // FAILURE only when a threshold tripped; carry the trip so the report can name the breached gate.
        return [
            'exitCode' => $trip instanceof ThresholdTrip ? Command::FAILURE : Command::SUCCESS,
            'trip' => $trip,
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
     * @param list<\GruffPhp\Finding\Finding> $findings Post-baseline findings for the run.
     * @param BranchReviewResult|null         $review   Branch-review result when --diff-vs is active.
     * @param BaselineReport|null             $baseline Baseline application result, when a baseline ran.
     * @return list<\GruffPhp\Finding\Finding> Findings the new-findings gate evaluates.
     */
    private function newFindingsForGate(array $findings, ?BranchReviewResult $review, ?BaselineReport $baseline): array
    {
        if ($review instanceof BranchReviewResult) {
            // Under --diff-vs the branch-introduced set is what "new" means for the gate.
            return $review->introduced;
        }

        if ($baseline instanceof BaselineReport && !$baseline->generated) {
            // An applied (not generated) baseline already removed known findings, so the survivors are the new set.
            return $findings;
        }

        // No reference point applies, so the gate has no new findings to evaluate.
        return [];
    }

    /**
     * Render the report with the reporter selected by output format.
     *
     * @param AnalysisReport $report Completed analysis result the chosen reporter serialises.
     * @param OutputFormat $format Output format that selects which reporter renders the result.
     * @param OutputInterface $output Stream the rendered report is written to, raw and unformatted.
     * @param string|null $projectRoot Project root for HTML file:line links; defaults to empty when not supplied.
     * @param string $reportEditorLink HTML editor-link style (vscode, phpstorm, or none); ignored by other formats.
     * @param bool $isReportInteractive Whether HTML output renders the opt-in interactive finding filters.
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
     * Append a score-trend entry to the history file when one is configured.
     *
     * @param string                $projectRoot  Project root the history file resolves against.
     * @param AnalyseCommandOptions $options      Effective CLI options carrying the history-file path.
     * @param ScoreReport           $score        Composite score recorded for this run.
     * @param int                   $findingCount Number of findings recorded alongside the score.
     * @param list<RunDiagnostic>   $diagnostics  Run diagnostics; a history error is appended in place.
     * @return TrendReport|null Recorded trend entry, or null when no history file is set or recording failed.
     */
    private function recordTrend(
        string $projectRoot,
        AnalyseCommandOptions $options,
        ScoreReport $score,
        int $findingCount,
        array &$diagnostics,
    ): ?TrendReport {
        if ($options->historyFile === null) {
            // No --history-file configured, so trend recording is simply skipped (null, not an error).
            return null;
        }

        try {
            // Success path: persist this run's score and finding count, returning the appended entry.
            return (new TrendRecorder())->record($projectRoot, $options->historyFile, $score, $findingCount);
        } catch (JsonException | RuntimeException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'history-error',
                message: $exception->getMessage(),
                path:    $options->historyFile,
            );

            // Recording failed; record a diagnostic and degrade to null rather than aborting the whole run.
            return null;
        }
    }
}
