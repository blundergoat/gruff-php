<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Baseline\BaselineApplication;
use GruffPhp\Results\Baseline\BaselineReport;
use GruffPhp\Results\Baseline\BaselineStore;
use GruffPhp\Cli\Application;
use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Results\Diff\DiffFindingFilter;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Diff\GitDiffProvider;
use GruffPhp\Results\Diff\ChangedLineRange;
use GruffPhp\Results\Diff\UnifiedDiffParser;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Cli\Command\Runtime\RuntimeTimingObserver;
use GruffPhp\Results\Mutation\MutationAnalysisBuilder;
use GruffPhp\Results\Mutation\MutationAnalysisResult;
use GruffPhp\Results\Mutation\MutationFindingFactory;
use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Output\Reporter\GithubAnnotationsReporter;
use GruffPhp\Output\Reporter\HotspotReporter;
use GruffPhp\Output\Reporter\HtmlReporter;
use GruffPhp\Output\Reporter\JsonReporter;
use GruffPhp\Output\Reporter\MarkdownReporter;
use GruffPhp\Output\Reporter\OutputFormat;
use GruffPhp\Output\Reporter\SarifReporter;
use GruffPhp\Output\Reporter\TextReporter;
use GruffPhp\Output\Reporter\ThresholdTrip;
use GruffPhp\Results\Review\BranchReviewResult;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Results\Scoring\ScoreCalculator;
use GruffPhp\Results\Scoring\ScoreReport;
use GruffPhp\Results\Trend\TrendRecorder;
use GruffPhp\Results\Trend\TrendReport;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backs the `gruff-php analyse` command - the full per-finding audit a user runs to see every
 * rule violation in their code, file by file, rather than the one-screen grade from `summary`.
 *
 * This runs when someone types `gruff-php analyse` (optionally with paths, a `--diff` scope, a
 * baseline, or a `--format`). `execute()` is the spine: discover sources, run the rules, fold in
 * optional mutation results, score, apply any baseline, build the branch review, record a trend
 * point, then hand the assembled report to the reporter for the chosen format. Each private
 * helper owns one slice of that pipeline; replaying a saved report instead lives in `report`.
 */
final class AnalyseCommand extends Command
{
    /**
     * Declares every argument, flag, and `--help` line the user can type after `gruff-php analyse` -
     * the paths to scan plus the long list of scope, baseline, mutation, diff, and output options.
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
            ->addOption('changed-scope', null, InputOption::VALUE_REQUIRED, 'Changed-region scope: symbol, hunk, or file. Use file to keep file-level aggregates and class aggregate span hits in changed-file review workflows.', default: DiffFindingFilter::SCOPE_SYMBOL)
            ->addOption('diff-vs', null, InputOption::VALUE_REQUIRED, 'Compare current findings against a base Git ref and report introduced/removed/unchanged findings.')
            ->addOption('changed-only', null, InputOption::VALUE_NONE, 'With --diff-vs, compare only files changed from the base ref.')
            ->addOption('paths-relative-to', null, InputOption::VALUE_REQUIRED, 'Normalize absolute finding paths relative to this directory for reports.')
            ->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'Display only findings at or above advisory, warning, or error.')
            ->addOption('include-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated pillars or repeated values.')
            ->addOption('exclude-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated pillars or repeated values.')
            ->addOption('include-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these comma-separated rule IDs or repeated values.')
            ->addOption('exclude-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip running these comma-separated rule IDs or repeated values.')
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
     * Orchestrates one full `gruff-php analyse` run end to end: discover sources, run the rules, fold
     * in optional mutation results, score, apply baselines, build the review, then render the report
     * in the requested format. Its one early return (a setup failure) still leaves the user with an actionable message.
     *
     * @param InputInterface  $input  - Parsed CLI arguments and options for this analyse run.
     * @param OutputInterface $output - Destination for the rendered report; its stderr stream carries any runtime payload.
     *
     * @return int - Symfony exit code: SUCCESS when clean, FAILURE when a fail-on gate trips, INVALID on a run diagnostic.
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

        // Setup validates the flags and loads config first; when it failed, surface that error and stop rather than scan with a half-built run.
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

        // The user asked to fold in mutation testing (`--infection-report` or `--infection-run`), so add its findings - surviving mutants plus any mutation-budget or MSI-regression flags - to the set.
        if ($mutationAnalysis instanceof MutationAnalysisResult) {
            $findings = array_merge($findings, (new MutationFindingFactory())->findingsFor($mutationAnalysis));
        }

        // A `--diff-vs` review run narrowed with `--changed-only`: drop findings in files that did not change against the base ref.
        if ($options->diffVs !== null && $options->isChangedOnly && $reviewDiff instanceof DiffResult) {
            $findings = $findingSupport->filterFindingsToChangedFiles($findings, $reviewDiff->changedFiles);
        }

        $suppressedCount = null;
        // A changed-region scope (`--diff`, `--since`, or `--changed-ranges`) is active: keep only findings inside the changed lines and count how many were hidden.
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

        $scoreStart = hrtime(true);
        $score      = (new ScoreCalculator())->calculate($findings, $mutationAnalysis, $diff, scorePillars: $options->profileScorePillars(), analysisConfig: $config);
        $scoreNs    = hrtime(true) - $scoreStart;
        // Without `--diff-vs` the review covers the whole run; with it, drop mutation-pillar findings so the branch verdict reflects only the rules under review.
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
     * Emits the `--print-runtime` performance payload as one JSON line on stderr, so a user profiling
     * a slow scan sees wall time, peak memory, per-phase timings, and (in detailed mode) per-rule totals.
     *
     * @param bool                                                                     $shouldEmit            - Whether --print-runtime requested the
     *                                                                                                        payload; a no-op when false.
     * @param OutputInterface                                                          $output                - Run output; the payload goes to its
     *                                                                                                        stderr stream, falling back to STDERR.
     * @param int                                                                      $runtimeStart          - hrtime(true) nanosecond marker
     *                                                                                                        captured at command start, used to
     *                                                                                                        derive wall time.
     * @param array{discoverParseNs: int, analyseNs: int, scoreNs: int, reportNs: int} $phaseDurationsNs      - Timed analyse phase durations in
     *                                                                                                        nanoseconds.
     * @param int                                                                      $filesParsed           - Count of source files actually parsed
     *                                                                                                        this run.
     * @param int                                                                      $rulesExecuted         - Count of rules enabled for this run.
     * @param RuntimeTimingObserver|null                                               $runtimeTimingObserver - Per-rule timings source; null unless
     *                                                                                                        detailed mode ran.
     * @param bool                                                                     $isDetailed            - Whether to attach per-rule totals;
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
        // Nobody passed `--print-runtime`, so emit nothing and keep the run's normal output clean.
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

        // Detailed mode with a live observer is the only case that carries per-rule timings, so attach them only then.
        if ($isDetailed && $runtimeTimingObserver !== null) {
            $payload['rules'] = $runtimeTimingObserver->snapshot();
        }

        $line   = json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL;
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : null;

        // A console output exposes its own stderr stream; write the payload there so it never mixes into the report on stdout.
        if ($stderr !== null) {
            $stderr->write($line);

            return;
        }

        // Non-console output (piped or captured) has no error stream, so fall back to the process-wide STDERR.
        fwrite(STDERR, $line);
    }

    /**
     * Renders a setup failure (a bad flag, an unreadable config) either as a plain console error or,
     * when setup got far enough to build a partial report, in the user's requested `--format`.
     *
     * @param AnalyseCommandSetupResult $result - Failed setup outcome with the error, exit code, and any partial report.
     * @param OutputInterface           $output - Destination the error text or formatted report is written to.
     *
     * @return int - The setup result's own exit code, returned after emitting its error text or partial report.
     */
    private function renderSetupFailure(AnalyseCommandSetupResult $result, OutputInterface $output): int
    {
        // A plain-text setup error (for example an unparseable flag) is shown as-is, with no report scaffolding around it.
        if ($result->plainError !== null) {
            $output->writeln($result->plainError);

            return $result->exitCode;
        }

        // Setup produced a partial report, so render the failure through the same `--format` the user chose (JSON, SARIF, and so on).
        if ($result->report instanceof AnalysisReport && $result->format instanceof OutputFormat) {
            $this->renderReport($result->report, $result->format, $output);
        }

        return $result->exitCode;
    }

    /**
     * Resolves a single Git base ref for `--diff-vs` or `--since` into the set of changed lines the
     * run compares its findings against.
     *
     * @param string              $projectRoot - Project root the Git diff is computed within.
     * @param string|null         $diffMode    - Git ref or diff selector to compare against; null means no diff was requested, so nothing is
     *                                         filtered.
     * @param list<RunDiagnostic> $diagnostics - Run diagnostics; a diff-mode error is appended in place on failure.
     *
     * @return DiffResult|null - Changed lines for the ref; an inactive result when no ref was requested; null when the Git lookup failed.
     */
    private function buildDiffResult(string $projectRoot, ?string $diffMode, array &$diagnostics): ?DiffResult
    {
        // No base ref was requested, so return an inactive diff that filters nothing and lets every finding through.
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
     * Picks where the "changed region" for `--diff`, `--since`, or `--changed-ranges` comes from and
     * builds the diff that later narrows findings to just the lines the user touched.
     *
     * @param string                $projectRoot - Project root the diff and requested paths resolve against.
     * @param AnalyseCommandOptions $options     - CLI options selecting the changed-region source (explicit ranges, a since-ref, or a diff).
     * @param list<RunDiagnostic>   $diagnostics - Run diagnostics; a diff-mode error is appended in place on failure.
     *
     * @return DiffResult|null - The active changed-region diff for the selected source; an inactive result when none applies; null when the lookup
     *                         failed.
     */
    private function buildChangedDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        // `--changed-ranges` takes precedence: the user spelled out exact line ranges, so trust those rather than asking Git.
        if ($options->changedRanges !== null) {
            return $this->buildExplicitRangesDiffResult($projectRoot, $options, $diagnostics);
        }

        // `--since=<ref>` scopes the run to whatever changed against that base ref, for example `--since=main`.
        if ($options->since !== null) {
            return $this->buildDiffResult($projectRoot, $options->since, $diagnostics);
        }

        // `--diff=-` reads a unified patch from stdin, as in `git diff | gruff-php analyse --diff=-`.
        if ($options->diffMode === '-') {
            $patch = stream_get_contents(STDIN);
            // Reading the piped patch genuinely failed (`false`), so there is no diff to scope by; an empty or closed pipe instead returns `''` and parses as an empty patch.
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

        // A bare or ref-valued `--diff` falls through to the Git provider, which resolves the right comparison for that selector (staged, unstaged, working-tree, or a named base ref).
        return $this->buildDiffResult($projectRoot, $options->diffMode, $diagnostics);
    }

    /**
     * Turns an explicit `--changed-ranges` value plus the requested file paths into a changed-region
     * diff, so a caller can review only the exact lines they name without involving Git.
     *
     * @param string                $projectRoot - Project root the requested paths resolve against.
     * @param AnalyseCommandOptions $options     - Effective CLI options carrying the paths and the changed ranges.
     * @param list<RunDiagnostic>   $diagnostics - Run diagnostics; diff-mode errors are appended in place.
     *
     * @return DiffResult|null - An active explicit-ranges diff over the requested files; null when the ranges or paths are invalid, ending the run
     *                         INVALID.
     */
    private function buildExplicitRangesDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        $changedFiles = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        // Line ranges mean nothing without a file to apply them to, so reject `--changed-ranges` when the user named no path.
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
        // Apply the one set of line ranges the user gave to each file they listed.
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
     * Parses a `--changed-ranges` string such as `3-3,8-10` into concrete line ranges, rejecting
     * anything malformed so a bad value fails fast instead of silently scoping the run to nothing.
     *
     * @param string $ranges - Comma-separated 1-based line ranges, for example "3-3,8-10".
     *
     * @return list<ChangedLineRange> - The parsed 1-based ranges in the order they appeared in the input.
     * @throws DiffException When a range token is malformed or the value yields no ranges.
     */
    private function parseChangedRanges(string $ranges): array
    {
        $parsed = [];

        // Walk each comma-separated token in turn, for example the `3-3` and `8-10` of `--changed-ranges=3-3,8-10`.
        foreach (explode(',', $ranges) as $part) {
            $part = trim($part);
            // A trailing or doubled comma leaves an empty token, which we quietly skip rather than treat as an error.
            if ($part === '') {
                continue;
            }

            // Accept a bare line number or a `start-end` pair, both 1-based; anything else (say `3-x`) is a usage error.
            if (!preg_match('/^(\d+)(?:-(\d+))?$/', $part, $matches)) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $startLine = (int)$matches[1];
            $endLine   = isset($matches[2]) ? (int)$matches[2] : $startLine;

            // Reject non-positive or back-to-front ranges (say `0-2` or `10-3`); they cannot point at real lines.
            if ($startLine < 1 || $endLine < $startLine) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $parsed[] = new ChangedLineRange($startLine, $endLine);
        }

        // The value held only separators and no real range (for example `--changed-ranges=,`), so there is nothing to scope by.
        if ($parsed === []) {
            throw new DiffException('--changed-ranges requires at least one range like "3-3,8-10".');
        }

        return $parsed;
    }

    /**
     * Works out which paths discovery should actually scan, narrowing the whole project down to just
     * the changed files whenever a diff-driven mode (`--diff`, `--since`, `--changed-ranges`, or a
     * changed-only `--diff-vs` review) is in play.
     *
     * The final changed-only branch reads the review diff directly: the guard at the top of this
     * method already returned when that diff was null on the changed-only path.
     *
     * @param string                $projectRoot       - Project root the requested and changed paths resolve against.
     * @param AnalyseCommandOptions $options           - Effective CLI options, including changed-only and requested-path flags.
     * @param DiffResult|null       $reviewDiff        - --diff-vs review diff; null when it failed or carries no changed files.
     * @param DiffResult|null       $changedRegionDiff - Changed-region diff from `--diff`/`--since`/`--changed-ranges`; null or inactive when none
     *                                                 of those was requested.
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
        // A changed-only review whose base diff never resolved has no file list; signal "scan nothing" instead of silently scanning the whole project.
        if ($options->isChangedOnly && $options->paths === [] && $reviewDiff === null) {
            return null;
        }

        $findingSupport = new AnalysisFindingSupport();

        // A changed-region mode is active, so limit discovery to the files that diff touched and that still exist on disk.
        if ($options->usesChangedFilesForDiscovery() && $changedRegionDiff instanceof DiffResult && $changedRegionDiff->active) {
            $changedFiles = $findingSupport->existingChangedFiles($projectRoot, $changedRegionDiff->changedFiles);
            // The diff named files but none still exist (all deleted or renamed away), so there is nothing left to scan.
            if ($changedFiles === []) {
                return null;
            }

            // No explicit paths were given, so review every changed file the diff produced.
            if ($options->paths === []) {
                return $changedFiles;
            }

            // The user gave both a changed scope and explicit paths, so keep only changed files that also sit under those paths.
            $requestedPaths = $findingSupport->normaliseRequestedPaths($projectRoot, $options->paths);
            $analysisPaths  = array_values(array_filter(
                                               $changedFiles,
                                               fn(string $changedFile): bool => $findingSupport->matchesRequestedPath($changedFile, $requestedPaths),
                                           ));
            sort($analysisPaths, SORT_STRING);

            // When that intersection is empty, none of the requested paths changed, so there is nothing to scan.
            return $analysisPaths === [] ? null : $analysisPaths;
        }

        // Not a `--changed-only` review (or explicit paths were named alongside it), so scan exactly what the user asked for; an empty list means the whole project.
        if (!$options->isChangedOnly || $options->paths !== []) {
            return $options->paths;
        }

        // A changed-only `--diff-vs` review whose base has no changed files leaves nothing to scan.
        if (!$reviewDiff instanceof DiffResult || $reviewDiff->changedFiles === []) {
            return null;
        }

        // Otherwise scope the scan to precisely the files the review diff says changed against the base ref.
        return $reviewDiff->changedFiles;
    }

    /**
     * Trims source-discovery diagnostics down to the current scope so a changed-only `--diff-vs`
     * review does not warn about missing paths that live outside the files under review.
     *
     * @param list<RunDiagnostic>   $diagnostics - Source-discovery diagnostics gathered before scope narrowing.
     * @param string                $projectRoot - Project root each diagnostic path is normalised against.
     * @param AnalyseCommandOptions $options     - Effective CLI options; only changed-only review runs trigger filtering.
     * @param DiffResult|null       $reviewDiff  - `--diff-vs` review diff supplying the changed-file allowlist; null or empty means no scope
     *                                           narrowing happens.
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
        // Outside a changed-only review there is no narrower scope to apply, so hand back every diagnostic untouched.
        if (!$options->isChangedOnly || !$reviewDiff instanceof DiffResult || $reviewDiff->changedFiles === []) {
            return $diagnostics;
        }

        $findingSupport = new AnalysisFindingSupport();

        return array_values(array_filter(
                                $diagnostics,
                                function (RunDiagnostic $diagnostic) use ($projectRoot, $reviewDiff, $findingSupport): bool {
                                    // Only missing-path warnings can fall outside the review; keep every other diagnostic as-is.
                                    if ($diagnostic->type !== 'missing-path' || $diagnostic->path === null) {
                                        return true;
                                    }

                                    $requestedPaths = $findingSupport->normaliseRequestedPaths($projectRoot, [$diagnostic->path]);
                                    // The path did not normalise to anything recognisable, so keep the warning rather than silently drop it.
                                    if ($requestedPaths === []) {
                                        return true;
                                    }

                                    // Compare the missing path against each file in the reviewed change set.
                                    foreach ($reviewDiff->changedFiles as $changedFile) {
                                        // The missing file is part of the reviewed change (for example deleted in this diff), so its absence is expected - drop the warning as noise.
                                        if ($findingSupport->matchesRequestedPath($changedFile, $requestedPaths)) {
                                            return false;
                                        }
                                    }

                                    // The missing path is not part of the reviewed change, so it is a genuine bad path worth surfacing.
                                    return true;
                                },
                            ));
    }

    /**
     * Decides the process exit code from the run's diagnostics and whether any configured fail-on
     * gate tripped - the number a CI job reads to pass or fail the build.
     *
     * @param list<RunDiagnostic>                     $diagnostics    - Run diagnostics; any present force INVALID ahead of findings.
     * @param list<\GruffPhp\Results\Finding\Finding> $findings       - Post-baseline finding set the all-findings gate inspects.
     * @param list<\GruffPhp\Results\Finding\Finding> $newFindings    - Change-introduced subset the new-findings gate inspects.
     * @param FailThresholds                          $failThresholds - Configured gate that decides which findings cause failure.
     *
     * @return array{exitCode: int, trip: ThresholdTrip|null} - The resolved exit code plus the breached gate threshold; trip is null unless a gate
     *                         fired.
     */
    private function resolveExitCode(array $diagnostics, array $findings, array $newFindings, FailThresholds $failThresholds): array
    {
        // A run diagnostic (a bad diff ref, an unreadable history file) means the scan itself is untrustworthy, so fail as INVALID before weighing findings.
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
     * Chooses which findings count as "new" for the `--fail-on-new` gate: the branch-introduced set
     * under `--diff-vs`, or the whole post-baseline set when a baseline is applied.
     *
     * Under `--diff-vs` the branch-introduced findings are already the post-baseline set intersected
     * with what the branch added; otherwise every post-baseline finding is treated as new. The setup
     * builder guarantees a reference point exists before this runs.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Post-baseline findings for the run.
     * @param BranchReviewResult|null                 $review   - Branch-review result when `--diff-vs` is active; null when no review ran.
     * @param BaselineReport|null                     $baseline - Baseline application result when a baseline ran; null when none applied.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - The change-introduced findings the new-findings gate scores; empty when no reference point
     *                                                 applies.
     */
    private function newFindingsForGate(array $findings, ?BranchReviewResult $review, ?BaselineReport $baseline): array
    {
        // A `--diff-vs` review already isolated exactly what the branch added, so those introduced findings are the ones the gate judges.
        if ($review instanceof BranchReviewResult) {
            return $review->introduced;
        }

        // With a real baseline applied (not one being freshly generated), everything that survived it is by definition the new debt to gate on.
        if ($baseline instanceof BaselineReport && !$baseline->generated) {
            return $findings;
        }

        // No `--diff-vs` and no applied baseline means there is no reference point, so nothing qualifies as newly introduced.
        return [];
    }

    /**
     * Serialises the finished analysis through the reporter that matches the user's `--format`, then
     * writes it raw so console styling never mangles JSON, HTML, SARIF, or Markdown output.
     *
     * @param AnalysisReport  $report              - Completed analysis result the chosen reporter serialises.
     * @param OutputFormat    $format              - Output format that selects which reporter renders the result.
     * @param OutputInterface $output              - Stream the rendered report is written to, raw and unformatted.
     * @param string|null     $projectRoot         - Project root for HTML file:line links; null falls back to empty, which disables those links.
     * @param string          $reportEditorLink    - HTML editor-link style (vscode, phpstorm, or none); ignored by every non-HTML format.
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
        // Each output format maps to exactly one reporter; the user's `--format` picks which one renders the run.
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
     * Appends this run's composite score to the `--history-file` trend log when one is configured, so
     * a team can watch code health rise or fall across successive scans.
     *
     * @param string                $projectRoot  - Project root the history file resolves against.
     * @param AnalyseCommandOptions $options      - Effective CLI options carrying the history-file path.
     * @param ScoreReport           $score        - Composite score recorded for this run.
     * @param int                   $findingCount - Number of findings recorded alongside the score.
     * @param list<RunDiagnostic>   $diagnostics  - Run diagnostics; a history error is appended in place.
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
        // No `--history-file` was given, so the user opted out of trend tracking and there is nothing to record.
        if ($options->historyFile === null) {
            return null;
        }

        try {
            return (new TrendRecorder())->record($projectRoot, $options->historyFile, $score, $findingCount);
        } catch (JsonException|RuntimeException $exception) {
            // The history file was unwritable or held malformed JSON; record it as a diagnostic so the run ends INVALID rather than losing the trend silently.
            $diagnostics[] = new RunDiagnostic(
                type:    'history-error',
                message: $exception->getMessage(),
                path:    $options->historyFile,
            );

            return null;
        }
    }
}
