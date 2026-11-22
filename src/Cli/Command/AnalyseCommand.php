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
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Cli\Command\Runtime\RuntimeTimingObserver;
use GruffPhp\Results\Mutation\MutationAnalysisBuilder;
use GruffPhp\Results\Mutation\MutationAnalysisResult;
use GruffPhp\Results\Mutation\MutationFindingFactory;
use GruffPhp\Output\Reporter\FailThreshold;
use GruffPhp\Output\Reporter\FailThresholds;
use GruffPhp\Output\Reporter\FindingDisplayFilter;
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
 * Runs `gruff-php analyse` for callers who need individual findings, scores, and diagnostics.
 *
 * The caller can choose scan paths, changed scope, a baseline, a mutation report, and an output format.
 * Coordinates setup, analysis, and rendering so the exit status and the report describe the same run.
 */
final class AnalyseCommand extends Command
{
    /**
     * Superseded flag spellings and the family names that replace them, each behaving identically.
     */
    private const SUPERSEDED_SPELLINGS = ['diff-vs' => '--diff-base'];



    /**
     * Declares the paths, options, and help text specific to the gruff-php analyse command.
     *
     * @return void - Registers the analyse arguments, options, and help text.
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
            ->addOption('deep-scan-budget', null, InputOption::VALUE_REQUIRED, 'Bound structural analysis as <lines>:<bytes>, or disable it with off.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Rule execution profile: default or security.', default: 'default')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, json, html, markdown, github, hotspot, or sarif.', default: OutputFormat::Text->value)
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the run: advisory, warning, error, or none.', default: FailThreshold::Advisory->value)
            ->addOption('fail-on-new', null, InputOption::VALUE_NONE, 'Fail only on findings introduced by the change (requires --baseline or --diff-vs). Shorthand for failureConditions.newFindings.severityThresholds.error: 0.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite an older baseline (0.5, v1, or an unknown schema) at the default path; without it a generate that would destroy that file is refused.',
            )
            ->addOption('migrate-baseline', null, InputOption::VALUE_REQUIRED, 'Path of a 0.5 baseline whose reviewed findings are carried into --generate-baseline; the original file is left untouched.')
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
            ->addOption('min-confidence', null, InputOption::VALUE_REQUIRED, 'Lowest confidence that reaches the exit gate: low, medium, or high. Never filters the report.')
            ->addOption('show-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated rule IDs. Never changes execution or the score.')
            ->addOption('hide-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated rule IDs from the report.')
            ->addOption('show-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated pillars.')
            ->addOption('hide-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated pillars from the report.')
            ->addOption('scan-timeout', null, InputOption::VALUE_REQUIRED, 'Parsed and accepted for cross-port compatibility; gruff-php enforces no scan deadline on analyse.')
            ->addOption('diff-base', null, InputOption::VALUE_REQUIRED, 'The ref a diff is taken against. Canonical spelling of --diff-vs.')
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
     * Orchestrates one `gruff-php analyse` request from source discovery through report rendering.
     *
     * It runs rules, scoring, baselines, and branch review in the order users expect.
     * A setup failure returns early only after giving the user an actionable message.
     *
     * @param InputInterface  $input  - Parsed CLI arguments and options for this analyse run.
     * @param OutputInterface $output - Destination for the rendered report; its stderr stream carries any runtime payload.
     *
     * @return int - Symfony exit code: SUCCESS when clean, FAILURE when a fail-on gate trips, INVALID on a run diagnostic.
     */
    /**
     * Prints one notice per supplied superseded flag, naming its replacement without stopping the run.
     *
     * @param InputInterface  $input  - The parsed command line.
     * @param OutputInterface $output - Uses console stderr when available, otherwise this output.
     *
     * @return void - Writes one notice per supplied superseded option.
     */
    private function warnSupersededSpellings(InputInterface $input, OutputInterface $output): void
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // Name each older spelling the caller used so scripts can switch to its supported replacement.
        foreach (self::SUPERSEDED_SPELLINGS as $superseded => $replacement) {
            // A flag the user did not type needs no notice, and a null value means exactly that.
            if ($input->getOption($superseded) === null) {
                continue;
            }

            $errorOutput->writeln(sprintf(
                '--%s is superseded by %s and behaves identically; the old spelling is going away.',
                $superseded,
                $replacement,
            ));
        }
    }

    /**
     * Runs one gruff-php analyse call end to end: validates flags, scans, scores, then renders the chosen format.
     *
     * @param InputInterface  $input  - Parsed flags and paths for this run.
     * @param OutputInterface $output - Destination for the rendered report.
     *
     * @return int - Setup failure's exit code, otherwise 2 for fatal diagnostics, 1 for a tripped gate, or 0.
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

        $this->warnSupersededSpellings($input, $output);

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
        $reviewDiff    = $this->buildDiffResult($projectRoot, $options->changeScope->diffVs, $diagnostics);
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
        $filesDiscovered  = count($sources->discovery->files);
        $diagnostics      = $this->withEmptyAnalysisDiagnostic($diagnostics, $filesDiscovered);
        $mutationAnalysis = (new MutationAnalysisBuilder())->build(
            $projectRoot,
            $options->mutation,
            $diagnostics,
        );

        $findings = $this->withMutationFindings($findings, $mutationAnalysis);

        $findings = $this->findingsForChangedReview($findings, $options, $reviewDiff, $findingSupport);

        $findings = $this->findingsWithinReadableScope($findings, $diagnostics);

        $suppressedCount = null;
        // Changed-region filtering honors the requested symbol, hunk, or file scope.
        // The suppressed count explains how many otherwise-visible findings were outside that scope.
        if ($diff instanceof DiffResult && $diff->active) {
            $diffFilterResult = (new DiffFindingFilter())->apply($findings, $diff, $sources->analysisUnits, $options->changeScope->changedScope);
            $findings         = $diffFilterResult->findings;
            $suppressedCount  = $diffFilterResult->suppressedCount;
            $diff             = $diff->withSuppressedCount($suppressedCount);
        }

        $baselineReport = (new BaselineApplication())->apply(
            projectRoot:     $projectRoot,
            options:         $options->baseline,
            findings:        $findings,
            diff:            $diff,
            diagnostics:     $diagnostics,
            hasPartialScope: $options->changeScope->diffVs !== null && $options->changeScope->isChangedOnly,
        );
        $findings = $findingSupport->normalizeFindingPaths($findings, $options->pathsRelativeTo);

        $scoreStart     = hrtime(true);
        $evaluatedFiles = $sources->evaluatedFileCount();
        $score          = (new ScoreCalculator())->calculate($findings, $evaluatedFiles, $mutationAnalysis, $diff, scorePillars: $options->profileScorePillars(), analysisConfig: $config);
        $scoreNs        = hrtime(true) - $scoreStart;
        // Without --diff-vs, review covers the whole run.
        // With a base ref, mutation findings are excluded so the branch verdict reflects only comparable rules.
        $reviewFindings = $options->changeScope->diffVs === null ? $findings : array_values(array_filter(
                                                                                   $findings,
                                                                                   static fn(Finding $finding): bool => $finding->pillar !== Pillar::Mutation,
                                                                               ));
        $reviewScore = $options->changeScope->diffVs === null
            ? $score->composite?->score
            : (new ScoreCalculator())->calculate($reviewFindings, $evaluatedFiles, null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config)->composite?->score;
        $review = $branchReviewBuilder->build(
            projectRoot:     $projectRoot,
            options:         $options,
            config:          $config,
            registry:        $registry,
            currentFindings: $reviewFindings,
            scoreContext:    new ReviewScoreContext($this->reviewScoreForDiscoveredFiles($reviewScore, $filesDiscovered), $evaluatedFiles),
            reviewDiff:      $reviewDiff,
            diagnostics:     $diagnostics,
        );
        $trend = $this->recordTrendWhenFilesDiscovered(
            $projectRoot, $options, $score, count($findings), $filesDiscovered, $diagnostics,
        );

        $newFindings      = $this->newFindingsForGate($findings, $review, $baselineReport);
        $gate             = $this->resolveExitCode($diagnostics, $findings, $newFindings, $setup->failThresholds, $options->minConfidence);
        $exitCode         = $gate['exitCode'];
        $failureReason    = $gate['trip'];
        $newFindingsCount = $setup->failThresholds->newFindingsGate instanceof FailThresholds ? count($newFindings) : null;
        $displayFilter    = $options->displayFilter()->withConfiguredFloor($config->displayFloor());
        $displayFindings  = $displayFilter->apply($findings);
        $displayReview    = $review?->filtered(fn(array $reviewFindings): array => $displayFilter->apply($reviewFindings));
        $analysisReport   = new AnalysisReport(
            toolVersion:              Application::VERSION,
            requestedPaths:           $options->paths,
            format:                   $format->value,
            failOn:                   $failThreshold->value,
            filesDiscovered:          $filesDiscovered,
            filesParsed:              $sources->parsedFileCount(),
            ignoredPaths:             $sources->discovery->ignoredPaths,
            missingPaths:             $sources->discovery->missingPaths,
            diagnostics:              $diagnostics,
            findings:                 $displayFindings,
            exitCode:                 $exitCode,
            configPath:               $setup->configPath,
            mutation:                 $mutationAnalysis,
            score:                    $this->scoreForDiscoveredFiles($score, $filesDiscovered),
            diff:                     $diff,
            trend:                    $trend,
            baseline:                 $baselineReport,
            review:                   $displayReview,
            filters:                  $displayFilter,
            suppressedCount:          $suppressedCount,
            shouldListAbsentBaseline: $shouldListAbsentBaseline,
            failureReason:            $failureReason,
            newFindingsCount:         $newFindingsCount,
            sensitiveExclusions:      $analysisRun['suppressions'],
            machineContext:           [
                'ignoredPathDetails' => $sources->discovery->ignoredPathDetails,
                'shouldIncludeIgnored' => $options->shouldIncludeIgnored,
                'projectRoot' => $projectRoot,
                'unfilteredFindings' => $findings,
                'suppressedFindings' => $this->sarifSuppressionsWithinScope($format, $analysisRun['suppressedFindings'], $diagnostics, $diff, $sources, $options, $displayFilter),
            ],
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

        return $exitCode;
    }

    /**
     * Emits the requested runtime payload as one JSON line on stderr.
     * Includes wall time, peak memory, phase timings, and optional per-rule totals.
     *
     * @param bool            $shouldEmit   - False returns without writing output.
     * @param OutputInterface $output       - Supplies console stderr; other output types use process STDERR.
     * @param int             $runtimeStart - hrtime(true) nanoseconds recorded at command start.
     *
     * @param array{discoverParseNs: int, analyseNs: int, scoreNs: int, reportNs: int} $phaseDurationsNs - Phase durations in nanoseconds.
     *
     * @param int                        $filesParsed           - Number of source files parsed during this run.
     * @param int                        $rulesExecuted         - Number of rules enabled for this run.
     * @param RuntimeTimingObserver|null $runtimeTimingObserver - Per-rule timing source; null omits rule totals.
     * @param bool                       $isDetailed            - Selects detailed mode; rule totals also require an observer.
     *
     * @return void - Writes one runtime JSON line when requested.
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

        // Timing goes to stderr only when the caller asks for it, keeping normal runs and piped JSON quiet.
        if (!$shouldEmit) {
            return;
        }

        $totalNs = hrtime(true) - $runtimeStart;
        $payload = [
            'wallMs' => (int)round($totalNs / 1_000_000),
            'peakBytes' => memory_get_peak_usage(true),
            'filesParsed' => $filesParsed,
            'rulesExecuted' => $rulesExecuted,
            'phases' => $phaseDurationsNs,
            'mode' => $isDetailed ? 'detailed' : 'summary',
        ];


        // Detailed timing adds per-rule measurements when the runner collected them.
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


        fwrite(STDERR, $line);
    }

    /**
     * Renders a setup failure (a bad flag, an unreadable config) either as a plain console error or,
     * when setup got far enough to build a partial report, in the user's requested `--format`.
     *
     * @param AnalyseCommandSetupResult $result - Failed setup outcome with the error, exit code, and any partial report.
     * @param OutputInterface           $output - Destination the error text or formatted report is written to.
     *
     * @return int - The setup result's exit code, even when neither error text nor a renderable report is available.
     */
    private function renderSetupFailure(AnalyseCommandSetupResult $result, OutputInterface $output): int
    {

        // An early validation error uses the console message when there is no analysis report to render.
        if ($result->plainError !== null) {
            $output->writeln($result->plainError);

            return $result->exitCode;
        }


        // Machine-format callers still receive the requested report when setup fails.
        if ($result->report instanceof AnalysisReport && $result->format instanceof OutputFormat) {
            $this->renderReport($result->report, $result->format, $output);
            $this->writeSetupDiagnosticsToError($result->report, $output);
        }

        return $result->exitCode;
    }

    /**
     * Repeats setup diagnostic messages after the partial report so failures are readable without parsing it.
     *
     * @param AnalysisReport  $report - Partial report whose diagnostic messages are repeated.
     * @param OutputInterface $output - Uses console stderr when available, otherwise this output.
     *
     * @return void - Writes one line per diagnostic; an empty diagnostic list writes nothing.
     */
    private function writeSetupDiagnosticsToError(AnalysisReport $report, OutputInterface $output): void
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // Show every failed prerequisite before running the scan so the caller can fix them together.
        foreach ($report->diagnostics as $diagnostic) {
            $errorOutput->writeln($diagnostic->message);
        }
    }

    /**
     * Resolves a Git base ref or diff selector into changed-file line ranges for review or scope filtering.
     *
     * @param string              $projectRoot - Project root the Git diff is computed within.
     * @param string|null         $diffMode    - Git ref or diff selector; null returns an inactive diff.
     * @param list<RunDiagnostic> $diagnostics - Receives a changed-region diagnostic if Git diff resolution fails.
     *
     * @return DiffResult|null - Resolved diff; inactive for null input, or null after a DiffException.
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
            // A ref such as a deleted branch cannot define the changed region. Return an actionable diff error.
            $diagnostics[] = new RunDiagnostic(
                type:    RunDiagnostic::CHANGED_REGION_TYPE,
                message: $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * Drops all findings when changed-region resolution failed, so an unscoped scan is not presented as the requested narrow result.
     *
     * @param list<Finding>       $findings    - Findings the run produced before the scope was considered.
     * @param list<RunDiagnostic> $diagnostics - Run diagnostics collected so far.
     *
     * @return list<Finding> - the findings unchanged, or none when a changed-region scope failure was recorded
     */
    private function findingsWithinReadableScope(array $findings, array $diagnostics): array
    {
        // A changed-code report needs a usable region before it can show any findings for that region.
        foreach ($diagnostics as $diagnostic) {
            // A failed scope lookup leaves the report empty because the requested changes could not be identified.
            if ($diagnostic->type === RunDiagnostic::CHANGED_REGION_TYPE) {
                return [];
            }
        }

        return $findings;
    }

    /**
     * Scopes SARIF suppression results like visible findings before serializing them.
     *
     * @param OutputFormat                                         $format        - Requested report format.
     * @param list<array{finding: Finding, justification: string}> $suppressed    - Inline and duplicate removals. Empty adds no SARIF results.
     * @param list<RunDiagnostic>                                  $diagnostics   - Run diagnostics that can make the selection unreadable.
     * @param DiffResult|null                                      $diff          - Selected changes. Null keeps removals across the scan.
     * @param AnalysisSourceSet                                    $sources       - Parsed units for declaration-level scope.
     * @param AnalyseCommandOptions                                $options       - Changed scope and display path options.
     * @param FindingDisplayFilter                                 $displayFilter - The same display filter used for visible findings.
     *
     * @return list<array{finding: Finding, justification: string}> - In-scope SARIF removals. Empty means none will appear in the renderer.
     */
    private function sarifSuppressionsWithinScope(
        OutputFormat $format,
        array $suppressed,
        array $diagnostics,
        ?DiffResult $diff,
        AnalysisSourceSet $sources,
        AnalyseCommandOptions $options,
        FindingDisplayFilter $displayFilter,
    ): array {
        // SARIF is the renderer that needs per-finding suppression records. Other formats use the audit totals.
        if ($format !== OutputFormat::Sarif || $suppressed === []) {
            return [];
        }

        $findings = $this->findingsWithinReadableScope(array_column($suppressed, 'finding'), $diagnostics);
        // Apply the same changed region to suppressed SARIF results so the file does not show issues outside the requested review.
        if ($diff instanceof DiffResult && $diff->active && $findings !== []) {
            $findings = (new DiffFindingFilter())->apply($findings, $diff, $sources->analysisUnits, $options->changeScope->changedScope)->findings;
        }

        $findings                      = $displayFilter->apply($findings);
        $displayFindings               = (new AnalysisFindingSupport())->normalizeFindingPaths($findings, $options->pathsRelativeTo);
        $suppressionReasonsByFindingId = [];
        // Keep each explanation attached to its finding when paths are rewritten for the report.
        foreach ($suppressed as $entry) {
            $suppressionReasonsByFindingId[spl_object_id($entry['finding'])] = $entry['justification'];
        }

        return array_map(
            static fn (Finding $finding, Finding $original): array => [
                'finding' => $finding,
                'justification' => $suppressionReasonsByFindingId[spl_object_id($original)],
            ],
            $displayFindings,
            $findings,
        );
    }

    /**
     * Picks where the changed region for --diff, --since, or --changed-ranges comes from and
     * builds the diff that later narrows findings to the selected changed lines.
     *
     * @param string                $projectRoot - Project root the diff and requested paths resolve against.
     * @param AnalyseCommandOptions $options     - CLI options selecting the changed-region source (explicit ranges, a since-ref, or a diff).
     * @param list<RunDiagnostic>   $diagnostics - Run diagnostics; a diff-mode error is appended in place on failure.
     *
     * @return DiffResult|null - Selected changed-region diff; inactive when none applies, or null when lookup fails.
     */
    private function buildChangedDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        // --changed-ranges takes precedence because the user supplied exact line ranges, so Git is not consulted.
        if ($options->changeScope->changedRanges !== null) {
            return $this->buildExplicitRangesDiffResult($projectRoot, $options, $diagnostics);
        }

        // --since=REF scopes the run to changes against that base ref, for example --since=main.
        if ($options->changeScope->since !== null) {
            return $this->buildDiffResult($projectRoot, $options->changeScope->since, $diagnostics);
        }

        // --diff=- reads a unified patch from stdin, for example when git diff is piped into gruff-php analyse --diff=-.
        if ($options->changeScope->diffMode === '-') {
            $patch = stream_get_contents(STDIN);
            // A failed stdin read leaves no diff scope.
            // An empty or closed pipe yields an empty string, which correctly parses as an empty patch.
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

        // A bare or ref-valued --diff uses Git for the selected staged, unstaged, working-tree, or base-ref comparison.
        return $this->buildDiffResult($projectRoot, $options->changeScope->diffMode, $diagnostics);
    }

    /**
     * Turns an explicit changed-ranges value and requested paths into a diff without consulting Git.
     * Applies the same parsed ranges to every normalized requested path.
     *
     * @param string                $projectRoot - Project root the requested paths resolve against.
     * @param AnalyseCommandOptions $options     - Effective CLI options carrying the paths and changed ranges.
     * @param list<RunDiagnostic>   $diagnostics - Receives changed-region diagnostics for an empty normalized path list or invalid ranges.
     *
     * @return DiffResult|null - Active explicit-ranges diff, or null when normalized paths are empty or range parsing fails.
     */
    private function buildExplicitRangesDiffResult(string $projectRoot, AnalyseCommandOptions $options, array &$diagnostics): ?DiffResult
    {
        $changedFiles = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        // Line ranges need a normalized target, so reject --changed-ranges when no requested path remains after normalization.
        if ($changedFiles === []) {
            $diagnostics[] = new RunDiagnostic(
                type:    RunDiagnostic::CHANGED_REGION_TYPE,
                message: '--changed-ranges requires at least one file path.',
            );

            return null;
        }

        try {
            $ranges = $this->parseChangedRanges($options->changeScope->changedRanges ?? '');
        } catch (DiffException $exception) {
            // A range such as 8-3 cannot identify a hunk. Report the option error before scanning.
            $diagnostics[] = new RunDiagnostic(
                type:    RunDiagnostic::CHANGED_REGION_TYPE,
                message: $exception->getMessage(),
            );

            return null;
        }

        $changedLines = [];

        // Explicit line ranges apply to each selected file.
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
     * Parses comma-separated line numbers and inclusive ranges, ignoring empty tokens.
     * Rejects non-positive or reversed ranges and requires at least one range.
     *
     * @param string $ranges - Comma-separated 1-based numbers or start-end ranges; surrounding token whitespace is ignored.
     *
     * @return list<ChangedLineRange> - Non-empty ranges in input order; overlaps and duplicates are retained.
     * @throws DiffException - When a nonempty token is malformed, a range is invalid, or no ranges remain.
     */
    private function parseChangedRanges(string $ranges): array
    {
        $parsed = [];


        // Read each comma-separated span so a caller can review several edits in one file.
        foreach (explode(',', $ranges) as $part) {
            $part = trim($part);
            // A trailing or doubled comma leaves an empty token, which we quietly skip rather than treat as an error.
            if ($part === '') {
                continue;
            }

            // Accept a bare 1-based line number or a 1-based start-end pair; any other form, such as 3-x, is a usage error.
            if (!preg_match('/^(\d+)(?:-(\d+))?$/', $part, $matches)) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $startLine = (int)$matches[1];
            $endLine   = isset($matches[2]) ? (int)$matches[2] : $startLine;

            // Non-positive or reversed ranges such as 0-2 or 10-3 cannot identify real source lines.
            if ($startLine < 1 || $endLine < $startLine) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $parsed[] = new ChangedLineRange($startLine, $endLine);
        }

        // If every token is empty, there is no line scope to apply.
        if ($parsed === []) {
            throw new DiffException('--changed-ranges requires at least one range like "3-3,8-10".');
        }

        return $parsed;
    }

    /**
     * Chooses discovery paths from explicit paths, changed-region input, and changed-only review.
     * Null skips discovery; an empty list retains the default project scope.
     *
     * @param string                $projectRoot       - Root used to normalize requested and changed file paths.
     * @param AnalyseCommandOptions $options           - Requested paths and changed-scope options.
     * @param DiffResult|null       $reviewDiff        - Review diff, possibly with no changed files; null when its lookup failed.
     * @param DiffResult|null       $changedRegionDiff - Changed-region diff; null means resolution failed, inactive means no scope was requested.
     *
     * @return list<string>|null - Paths to scan, [] for default project scope, or null when the selected scope supplies no files.
     */
    private function currentAnalysisPaths(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult           $reviewDiff,
        ?DiffResult           $changedRegionDiff,
    ): ?array {
        // A changed-only review with no resolved base diff has no file list.
        // Return "scan nothing" instead of surprising the user with a whole-project scan.
        if ($options->changeScope->isChangedOnly && $options->paths === [] && $reviewDiff === null) {
            return null;
        }

        $findingSupport = new AnalysisFindingSupport();

        // A changed-region mode is active, so limit discovery to the files that diff touched and that still exist on disk.
        if ($options->usesChangedFilesForDiscovery() && $changedRegionDiff instanceof DiffResult && $changedRegionDiff->active) {
            $changedFiles = $findingSupport->existingChangedFiles($projectRoot, $changedRegionDiff->changedFiles);
            // No changed files remain available on disk, so there is nothing to scan.
            if ($changedFiles === []) {
                return null;
            }


            // With no operands, a changed-code scan uses the files Git identified.
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

        // Explicit paths remain authoritative unless an active changed-region scope has already narrowed them.
        // An empty path list means the user requested the whole project.
        if (!$options->changeScope->isChangedOnly || $options->paths !== []) {
            return $options->paths;
        }

        // A changed-only --diff-vs review whose base has no changed files leaves nothing to scan.
        if (!$reviewDiff instanceof DiffResult || $reviewDiff->changedFiles === []) {
            return null;
        }


        return $reviewDiff->changedFiles;
    }

    /**
     * Suppresses missing-path diagnostics for paths included in a changed-only review diff.
     * Reviewed deletions are expected; unrelated missing paths and other diagnostics remain visible.
     *
     * @param list<RunDiagnostic>   $diagnostics - Discovery diagnostics that may include missing reviewed paths.
     * @param string                $projectRoot - Root used to normalize each diagnostic path.
     * @param AnalyseCommandOptions $options     - Only changed-only review enables this filtering.
     * @param DiffResult|null       $reviewDiff  - Reviewed changed files; null or an empty file list leaves diagnostics unchanged.
     *
     * @return list<RunDiagnostic> - Original diagnostics except missing paths matched by a changed-only review diff.
     */
    private function filterSourceDiagnostics(
        array                 $diagnostics,
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult           $reviewDiff,
    ): array {
        // Outside a changed-only review there is no narrower scope to apply, so hand back every diagnostic untouched.
        if (!$options->changeScope->isChangedOnly || !$reviewDiff instanceof DiffResult || $reviewDiff->changedFiles === []) {
            return $diagnostics;
        }

        $findingSupport = new AnalysisFindingSupport();

        return array_values(array_filter(
                                $diagnostics,
                                function (RunDiagnostic $diagnostic) use ($projectRoot, $reviewDiff, $findingSupport): bool {
                                    // This suppression applies only to missing paths; other diagnostic types retain their meaning.
                                    if ($diagnostic->type !== 'missing-path' || $diagnostic->path === null) {
                                        return true;
                                    }

                                    $requestedPaths = $findingSupport->normaliseRequestedPaths($projectRoot, [$diagnostic->path]);
                                    // The path did not normalise to anything recognisable, so keep the warning rather than silently drop it.
                                    if ($requestedPaths === []) {
                                        return true;
                                    }


                                    // Reconcile changed paths with the source files available in the working tree.
                                    foreach ($reviewDiff->changedFiles as $changedFile) {
                                        // A reviewed file may have been deleted in this diff, so its absence is expected and the warning is noise.
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
     * @param list<RunDiagnostic> $diagnostics    - Run diagnostics; fatal entries force INVALID ahead of findings.
     * @param list<Finding>       $findings       - Post-baseline findings the all-findings gate inspects.
     * @param list<Finding>       $newFindings    - Findings introduced by the change that can trip a new-findings gate.
     * @param FailThresholds      $failThresholds - Configured gate that decides which findings cause failure.
     * @param string|null         $minConfidence  - Exit-gate confidence floor. Null lets findings at any confidence reach the severity gate.
     *
     * @return array{exitCode: int, trip: ThresholdTrip|null} - 2 on fatal diagnostics, 1 on a gate trip, else 0; trip is null unless a gate trips.
     */
    private function resolveExitCode(array $diagnostics, array $findings, array $newFindings, FailThresholds $failThresholds, ?string $minConfidence): array
    {
        // Fatal diagnostics mean the scan is untrustworthy; informational diagnostics stay visible without overriding --fail-on semantics.
        foreach ($diagnostics as $diagnostic) {
            // An unreadable scan exits 2 before checking whether any finding meets the requested threshold.
            if ($diagnostic->isFatal) {
                return ['exitCode' => Command::INVALID, 'trip' => null];
            }
        }

        // Confidence limits which findings fail the run. The report still shows findings below the threshold.
        if ($minConfidence !== null) {
            $minimumRank  = Confidence::from($minConfidence)->rank();
            $reachesFloor = static fn (Finding $finding): bool => $finding->confidence->rank() >= $minimumRank;
            $findings     = array_values(array_filter($findings, $reachesFloor));
            $newFindings  = array_values(array_filter($newFindings, $reachesFloor));
        }

        $trip = $failThresholds->tripsOnScope($findings, $newFindings);

        return [
            'exitCode' => $trip instanceof ThresholdTrip ? Command::FAILURE : Command::SUCCESS,
            'trip' => $trip,
        ];
    }

    /**
     * Adds the non-fatal diagnostic that distinguishes an empty scan from a clean scan.
     *
     * @param list<RunDiagnostic> $diagnostics     - Diagnostics already produced by setup and source parsing.
     * @param int                 $filesDiscovered - Number of PHP files admitted by discovery.
     *
     * @return list<RunDiagnostic> - Original diagnostics plus the empty-analysis diagnostic when no source evidence exists.
     */
    private function withEmptyAnalysisDiagnostic(array $diagnostics, int $filesDiscovered): array
    {
        // A discovered file gives the scan something to report even if its contents later produce a diagnostic.
        if ($filesDiscovered !== 0) {
            return $diagnostics;
        }

        $diagnostics[] = new RunDiagnostic(
            type:    'empty-analysis',
            message: 'No scannable PHP files were discovered; the score is not applicable. Check the requested paths and ignore rules.',
            isFatal: false,
        );

        return $diagnostics;
    }

    /**
     * Adds findings derived from an explicitly requested Infection result.
     *
     * @param list<Finding>               $findings         - Rule findings already produced by source analysis.
     * @param MutationAnalysisResult|null $mutationAnalysis - Parsed Infection result, or null when mutation analysis was not requested.
     *
     * @return list<Finding> - Source findings plus escaped-mutant, budget, and MSI-regression findings when available.
     */
    private function withMutationFindings(array $findings, ?MutationAnalysisResult $mutationAnalysis): array
    {
        // A scan without mutation results publishes the ordinary rule findings.
        if (!$mutationAnalysis instanceof MutationAnalysisResult) {
            return $findings;
        }

        return array_merge($findings, (new MutationFindingFactory())->findingsFor($mutationAnalysis));
    }

    /**
     * Narrows a changed-only branch review to files present in the reviewed diff.
     *
     * @param list<Finding>          $findings       - Findings produced for the current source set.
     * @param AnalyseCommandOptions  $options        - Effective review and changed-only options.
     * @param DiffResult|null        $reviewDiff     - Diff against the requested review base; null leaves the user's findings unfiltered.
     * @param AnalysisFindingSupport $findingSupport - Path matcher shared with other finding filters.
     *
     * @return list<Finding> - Changed-file findings for a narrowed review, otherwise the original set.
     */
    private function findingsForChangedReview(
        array $findings,
        AnalyseCommandOptions $options,
        ?DiffResult $reviewDiff,
        AnalysisFindingSupport $findingSupport,
    ): array {
        // Restrict branch review to changed files only when the caller pairs a comparison ref with --changed-only.
        if ($options->changeScope->diffVs === null || !$options->changeScope->isChangedOnly || !$reviewDiff instanceof DiffResult) {
            return $findings;
        }

        return $findingSupport->filterFindingsToChangedFiles($findings, $reviewDiff->changedFiles);
    }

    /**
     * Suppresses the calculator's synthetic score when discovery supplied no source evidence.
     *
     * @param ScoreReport $score           - Score calculated for the run's finding set.
     * @param int         $filesDiscovered - Number of PHP files admitted by discovery.
     *
     * @return ScoreReport|null - Calculated score for a real source set, otherwise null.
     */
    private function scoreForDiscoveredFiles(ScoreReport $score, int $filesDiscovered): ?ScoreReport
    {
        return $filesDiscovered === 0 ? null : $score;
    }

    /**
     * Withholds the branch review's current score when discovery supplied no source evidence.
     *
     * The comparison can still show removed findings without pricing them against an inapplicable score.
     * Users therefore see the same no-score meaning in both the ordinary report and branch review.
     *
     * @param float $reviewScore     - Composite score for the reviewed findings; null means no applicable score is available.
     * @param int   $filesDiscovered - Number of PHP files admitted by discovery.
     *
     * @return float|null - Review score for a real source set, otherwise null.
     */
    private function reviewScoreForDiscoveredFiles(?float $reviewScore, int $filesDiscovered): ?float
    {
        return $filesDiscovered === 0 ? null : $reviewScore;
    }

    /**
     * Chooses the findings the user's --fail-on-new gate judges against its branch review or baseline reference.
     * Review mode uses introduced findings; baseline mode uses findings that survived the baseline, and no reference means no new-finding gate input.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Post-baseline findings for the run.
     * @param BranchReviewResult|null                 $review   - Branch-review result when --diff-vs is active; null when no review ran.
     * @param BaselineReport|null                     $baseline - Baseline application result when a baseline ran; null when none applied.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Introduced review findings, else applied-baseline survivors; empty without either.
     */
    private function newFindingsForGate(array $findings, ?BranchReviewResult $review, ?BaselineReport $baseline): array
    {
        // A --diff-vs review already isolated exactly what the branch added, so those introduced findings are the ones the gate judges.
        if ($review instanceof BranchReviewResult) {
            return $review->introduced;
        }

        // With a real baseline applied (not one being freshly generated), everything that survived it is by definition the new debt to gate on.
        if ($baseline instanceof BaselineReport && !$baseline->generated) {
            return $findings;
        }

        // No --diff-vs and no applied baseline means there is no reference point, so nothing qualifies as newly introduced.
        return [];
    }

    /**
     * Serialises an analysis or setup-failure report in the requested format.
     * Raw output prevents Symfony console styling from interpreting report content.
     *
     * @param AnalysisReport  $report              - Analysis result or partial setup-failure report to serialize.
     * @param OutputFormat    $format              - Output format that selects the reporter.
     * @param OutputInterface $output              - Destination for the rendered report, written raw.
     * @param string|null     $projectRoot         - Root passed to HTML and SARIF reporters; null becomes an empty root for HTML.
     * @param string          $reportEditorLink    - HTML editor-link style; ignored by non-HTML formats.
     * @param bool            $isReportInteractive - Whether HTML output renders the opt-in interactive finding filters.
     *
     * @return void - Renders the report and writes it without console formatting.
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
            OutputFormat::Sarif => new SarifReporter($projectRoot),
            OutputFormat::Text => new TextReporter(),
        };
        // OUTPUT_RAW skips Symfony's OutputFormatter tag scan, which would otherwise
        // parse every <...> in HTML/JSON/Markdown output as a console style tag.
        $output->write($renderer->render($report), false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Appends this run's composite score to the --history-file trend log when one is configured, so
     * a team can watch code health rise or fall across successive scans.
     *
     * @param string                $projectRoot  - Project root the history file resolves against.
     * @param AnalyseCommandOptions $options      - Effective CLI options carrying the history-file path.
     * @param ScoreReport           $score        - Composite score recorded for this run.
     * @param int                   $findingCount - Number of findings recorded alongside the score.
     * @param list<RunDiagnostic>   $diagnostics  - Run diagnostics; a history error is appended in place.
     *
     * @return TrendReport|null - Trend entry, or null when history is disabled, no composite score exists, or recording fails (adds a diagnostic).
     */
    private function recordTrend(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ScoreReport           $score,
        int                   $findingCount,
        array                 &$diagnostics,
    ): ?TrendReport {
        // No --history-file was given, so the user opted out of trend tracking and there is nothing to record.
        if ($options->historyFile === null) {
            return null;
        }

        // A run that evaluated nothing has no score, so it contributes no trend point. Writing one
        // would put a scoreless row in the history that every later delta would have to subtract from.
        if ($score->composite === null) {
            return null;
        }

        try {
            return (new TrendRecorder())->record($projectRoot, $options->historyFile, $score, $findingCount);
        } catch (JsonException|RuntimeException $exception) {
            // A history file with truncated JSON or a read-only destination becomes a diagnostic for this report.
            $diagnostics[] = new RunDiagnostic(
                type:    'history-error',
                message: $exception->getMessage(),
                path:    $options->historyFile,
            );

            return null;
        }
    }

    /**
     * Records trend data only when discovery produced a score-bearing source set.
     *
     * @param string                $projectRoot     - Project root the history file resolves against.
     * @param AnalyseCommandOptions $options         - Effective CLI options carrying the history-file path.
     * @param ScoreReport           $score           - Composite score calculated for the run.
     * @param int                   $findingCount    - Number of findings recorded alongside the score.
     * @param int                   $filesDiscovered - Number of PHP files admitted by discovery.
     * @param list<RunDiagnostic>   $diagnostics     - Run diagnostics; a history error is appended in place.
     *
     * @return TrendReport|null - Trend entry, or null for no discovered files, disabled history, an absent composite score, or recording failure.
     */
    private function recordTrendWhenFilesDiscovered(
        string $projectRoot,
        AnalyseCommandOptions $options,
        ScoreReport $score,
        int $findingCount,
        int $filesDiscovered,
        array &$diagnostics,
    ): ?TrendReport {
        // Persisting the calculator's synthetic empty score would pollute later trend comparisons.
        if ($filesDiscovered === 0) {
            return null;
        }

        return $this->recordTrend($projectRoot, $options, $score, $findingCount, $diagnostics);
    }
}
