<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Baseline\BaselineApplication;
use GruffPhp\Baseline\BaselineStore;
use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Console\Application;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Diff\DiffException;
use GruffPhp\Diff\DiffFindingFilter;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Diff\GitDiffProvider;
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
use GruffPhp\Reporting\TextReporter;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Scoring\CompositeFindingFactory;
use GruffPhp\Scoring\ScoreCalculator;
use GruffPhp\Trend\TrendRecorder;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AnalyseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('analyse')
            ->setDescription('Run gruff analysis.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff.yaml file for this run.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, json, html, markdown, github, or hotspot.', OutputFormat::Text->value)
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the run: advisory, warning, error, or none.', FailThreshold::Error->value)
            ->addOption('report-editor-link', null, InputOption::VALUE_REQUIRED, 'Editor link style for HTML file:line references: vscode, phpstorm, or none.', 'none')
            ->addOption('report-interactive', null, InputOption::VALUE_OPTIONAL, 'Render opt-in interactive HTML finding filters. Accepts true or false.', null)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.')
            ->addOption('infection-report', null, InputOption::VALUE_REQUIRED, 'Path to a full Infection JSON report to ingest.')
            ->addOption('infection-run', null, InputOption::VALUE_NONE, 'Run Infection before ingesting the report path supplied by --infection-report.')
            ->addOption('infection-bin', null, InputOption::VALUE_REQUIRED, 'Infection executable for --infection-run.', 'infection')
            ->addOption('infection-config', null, InputOption::VALUE_REQUIRED, 'Path to infection.json5 for --infection-run.')
            ->addOption('infection-test-framework-options', null, InputOption::VALUE_REQUIRED, 'Options passed to Infection/PHPUnit for --infection-run.')
            ->addOption('mutation-baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline Infection JSON report for MSI diff mode.')
            ->addOption('mutation-budget', null, InputOption::VALUE_REQUIRED, 'Maximum escaped/timed-out mutants allowed.')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Filter findings to changed lines. Use working-tree, staged, unstaged, or a base ref.', null)
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
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Skip auto-applying the default baseline file for this run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $setupResult = (new AnalyseCommandSetupBuilder())->build($input);

        if (!$setupResult->setup instanceof AnalyseCommandSetup) {
            return $this->renderSetupFailure($setupResult, $output);
        }

        $setup = $setupResult->setup;
        $projectRoot = $setup->projectRoot;
        $options = $setup->options;
        $format = $setup->format;
        $failThreshold = $setup->failThreshold;
        $config = $setup->config;
        $registry = $setup->registry;

        $sources = (new AnalysisSourceLoader())->load(
            $projectRoot,
            $options->paths,
            $options->includeIgnored,
            $config->ignoredPathPatterns(),
        );
        $diagnostics = $sources->diagnostics;

        $findings = $registry->analyse($sources->analysisUnits, new RuleContext($projectRoot, $config));
        $mutationAnalysis = (new MutationAnalysisBuilder())->build(
            $projectRoot,
            $options->mutation,
            $diagnostics,
        );

        if ($mutationAnalysis instanceof MutationAnalysisResult) {
            $findings = array_merge($findings, (new MutationFindingFactory())->findingsFor($mutationAnalysis));
        }

        $findings = array_merge($findings, (new CompositeFindingFactory())->build($findings));
        $diff = $this->buildDiffResult($projectRoot, $options->diffMode, $diagnostics);
        if ($diff instanceof DiffResult && $diff->active) {
            $findings = (new DiffFindingFilter())->filter($findings, $diff);
        }

        $findings = $this->filterAllowedSecretPreviews($findings, $config);
        $baselineReport = (new BaselineApplication())->apply(
            $projectRoot,
            $options->baseline,
            $findings,
            $diff,
            $diagnostics,
        );

        $score = (new ScoreCalculator())->calculate($findings, $mutationAnalysis, $diff);
        $trend = null;

        if ($options->historyFile !== null) {
            try {
                $trend = (new TrendRecorder())->record($projectRoot, $options->historyFile, $score, count($findings));
            } catch (JsonException | RuntimeException $exception) {
                $diagnostics[] = new RunDiagnostic(
                    type: 'history-error',
                    message: $exception->getMessage(),
                    path: $options->historyFile,
                );
            }
        }

        $exitCode = $this->resolveExitCode($diagnostics, $findings, $failThreshold);
        $report = new AnalysisReport(
            toolVersion: Application::VERSION,
            requestedPaths: $options->paths,
            format: $format->value,
            failOn: $failThreshold->value,
            filesDiscovered: count($sources->discovery->files),
            filesParsed: $sources->parsedFileCount(),
            ignoredPaths: $sources->discovery->ignoredPaths,
            missingPaths: $sources->discovery->missingPaths,
            diagnostics: $diagnostics,
            findings: $findings,
            exitCode: $exitCode,
            configPath: $options->configPath,
            mutation: $mutationAnalysis,
            score: $score,
            diff: $diff,
            trend: $trend,
            baseline: $baselineReport,
        );

        $this->renderReport(
            $report,
            $format,
            $output,
            $projectRoot,
            $options->reportEditorLink,
            $options->reportInteractive,
        );

        return $exitCode;
    }

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
                type: 'diff-mode-error',
                message: $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * @param list<RunDiagnostic> $diagnostics
     * @param list<\GruffPhp\Finding\Finding> $findings
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

    private function renderReport(
        AnalysisReport $report,
        OutputFormat $format,
        OutputInterface $output,
        ?string $projectRoot = null,
        string $reportEditorLink = 'none',
        bool $reportInteractive = false,
    ): void
    {
        $renderer = match ($format) {
            OutputFormat::Json => new JsonReporter(),
            OutputFormat::Html => new HtmlReporter($projectRoot ?? '', $reportEditorLink, $reportInteractive),
            OutputFormat::Markdown => new MarkdownReporter(),
            OutputFormat::Github => new GithubAnnotationsReporter(),
            OutputFormat::Hotspot => new HotspotReporter(),
            OutputFormat::Text => new TextReporter(),
        };
        // OUTPUT_RAW skips Symfony's OutputFormatter tag scan, which would otherwise
        // parse every <...> in HTML/JSON/Markdown output as a console style tag.
        $output->write($renderer->render($report), false, OutputInterface::OUTPUT_RAW);
    }
}
