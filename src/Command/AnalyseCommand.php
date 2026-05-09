<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Baseline\BaselineException;
use GruffPhp\Baseline\BaselineFilter;
use GruffPhp\Baseline\BaselineReport;
use GruffPhp\Baseline\BaselineStore;
use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Console\Application;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Diff\DiffException;
use GruffPhp\Diff\DiffFindingFilter;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Diff\GitDiffProvider;
use GruffPhp\Mutation\InfectionReportParser;
use GruffPhp\Mutation\InfectionRunner;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Mutation\MutationFindingFactory;
use GruffPhp\Mutation\MutationReportException;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\GithubAnnotationsReporter;
use GruffPhp\Reporting\HotspotReporter;
use GruffPhp\Reporting\HtmlReporter;
use GruffPhp\Reporting\JsonReporter;
use GruffPhp\Reporting\MarkdownReporter;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Reporting\TextReporter;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Scoring\CompositeFindingFactory;
use GruffPhp\Scoring\ScoreCalculator;
use GruffPhp\Source\SourceDiscovery;
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
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff JSON config file.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, json, html, markdown, github, or hotspot.', OutputFormat::Text->value)
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the run: advisory, warning, error, or none.', FailThreshold::Error->value)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.')
            ->addOption('infection-report', null, InputOption::VALUE_REQUIRED, 'Path to a full Infection JSON report to ingest.')
            ->addOption('infection-run', null, InputOption::VALUE_NONE, 'Run Infection before ingesting the report path supplied by --infection-report.')
            ->addOption('infection-bin', null, InputOption::VALUE_REQUIRED, 'Infection executable for --infection-run.', 'infection')
            ->addOption('infection-config', null, InputOption::VALUE_REQUIRED, 'Path to infection.json5 for --infection-run.')
            ->addOption('mutation-baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline Infection JSON report for MSI diff mode.')
            ->addOption('mutation-budget', null, InputOption::VALUE_REQUIRED, 'Maximum escaped/timed-out mutants allowed.')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Filter findings to changed lines. Use working-tree, staged, unstaged, or a base ref.', null)
            ->addOption('history-file', null, InputOption::VALUE_REQUIRED, 'Append score trend history to this JSON file.')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Suppress current findings that match this gruff baseline JSON file.')
            ->addOption('generate-baseline', null, InputOption::VALUE_REQUIRED, 'Write current findings to this gruff baseline JSON file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();

        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $includeIgnored = (bool) $input->getOption('include-ignored');
        $configPath = $input->getOption('config');
        $configPath = is_string($configPath) ? $configPath : null;
        $infectionReportPath = $this->optionalStringOption($input, 'infection-report');
        $infectionRun = (bool) $input->getOption('infection-run');
        $infectionBin = $this->optionalStringOption($input, 'infection-bin') ?? 'infection';
        $infectionConfigPath = $this->optionalStringOption($input, 'infection-config');
        $mutationBaselinePath = $this->optionalStringOption($input, 'mutation-baseline');
        $diffMode = $this->diffMode($input);
        $historyFile = $this->optionalStringOption($input, 'history-file');
        $baselinePath = $this->optionalStringOption($input, 'baseline');
        $generateBaselinePath = $this->optionalStringOption($input, 'generate-baseline');
        $format = $this->parseFormat($input->getOption('format'), $output);

        if (!$format instanceof OutputFormat) {
            return Command::INVALID;
        }

        $failThreshold = $this->parseFailThreshold($input->getOption('fail-on'), $format, $paths, $configPath, $output);

        if (!$failThreshold instanceof FailThreshold) {
            return Command::INVALID;
        }

        $mutationBudget = $this->parseMutationBudget(
            $input->getOption('mutation-budget'),
            $format,
            $paths,
            $configPath,
            $failThreshold->value,
            $output,
        );

        if ($mutationBudget === false) {
            return Command::INVALID;
        }

        if ($baselinePath !== null && $generateBaselinePath !== null) {
            $this->renderReport(
                new AnalysisReport(
                    toolVersion: Application::VERSION,
                    requestedPaths: $paths,
                    format: $format->value,
                    failOn: $failThreshold->value,
                    filesDiscovered: 0,
                    filesParsed: 0,
                    ignoredPaths: [],
                    missingPaths: [],
                    diagnostics: [new RunDiagnostic(
                        'usage-error',
                        '--baseline and --generate-baseline are mutually exclusive.',
                    )],
                    findings: [],
                    exitCode: Command::INVALID,
                    configPath: $configPath,
                ),
                $format,
                $output,
            );

            return Command::INVALID;
        }

        $registry = RuleRegistry::defaults();

        try {
            $config = (new ConfigLoader($projectRoot))->load($configPath, $registry);
        } catch (ConfigException $exception) {
            $this->renderReport(
                new AnalysisReport(
                    toolVersion: Application::VERSION,
                    requestedPaths: $paths,
                    format: $format->value,
                    failOn: $failThreshold->value,
                    filesDiscovered: 0,
                    filesParsed: 0,
                    ignoredPaths: [],
                    missingPaths: [],
                    diagnostics: [new RunDiagnostic('config-error', $exception->getMessage())],
                    findings: [],
                    exitCode: Command::INVALID,
                    configPath: $configPath,
                ),
                $format,
                $output,
            );

            return Command::INVALID;
        }

        $discovery = new SourceDiscovery($projectRoot);
        $discoveryResult = $discovery->discover($paths, $includeIgnored, $config->ignoredPathPatterns());
        $parser = new PhpFileParser();
        $diagnostics = [];
        $analysisUnits = [];

        foreach ($discoveryResult->missingPaths as $missingPath) {
            $diagnostics[] = new RunDiagnostic(
                type: 'missing-path',
                message: 'Input path does not exist.',
                path: $missingPath,
            );
        }

        foreach ($discoveryResult->files as $file) {
            $unit = $parser->parse($file);
            $analysisUnits[] = $unit;

            if ($unit->hasParseErrors()) {
                foreach ($unit->diagnostics as $diagnostic) {
                    $diagnostics[] = new RunDiagnostic(
                        type: 'parse-error',
                        message: $diagnostic->message,
                        filePath: $file->displayPath,
                        line: $diagnostic->line,
                    );
                }
            }
        }

        $findings = $registry->analyse($analysisUnits, new RuleContext($projectRoot, $config));
        $mutationAnalysis = $this->buildMutationAnalysis(
            projectRoot: $projectRoot,
            infectionReportPath: $infectionReportPath,
            infectionRun: $infectionRun,
            infectionBin: $infectionBin,
            infectionConfigPath: $infectionConfigPath,
            mutationBaselinePath: $mutationBaselinePath,
            mutationBudget: $mutationBudget,
            diagnostics: $diagnostics,
        );

        if ($mutationAnalysis instanceof MutationAnalysisResult) {
            $findings = array_merge($findings, (new MutationFindingFactory())->findingsFor($mutationAnalysis));
        }

        $findings = array_merge($findings, (new CompositeFindingFactory())->build($findings));
        $diff = $this->buildDiffResult($projectRoot, $diffMode, $diagnostics);
        if ($diff instanceof DiffResult && $diff->active) {
            $findings = (new DiffFindingFilter())->filter($findings, $diff);
        }

        $findings = $this->filterAllowedSecretPreviews($findings, $config);
        $baselineReport = $this->applyBaseline(
            projectRoot: $projectRoot,
            baselinePath: $baselinePath,
            generateBaselinePath: $generateBaselinePath,
            findings: $findings,
            diff: $diff,
            diagnostics: $diagnostics,
        );

        $score = (new ScoreCalculator())->calculate($findings, $mutationAnalysis, $diff);
        $trend = null;

        if ($historyFile !== null) {
            try {
                $trend = (new TrendRecorder())->record($projectRoot, $historyFile, $score, count($findings));
            } catch (JsonException | RuntimeException $exception) {
                $diagnostics[] = new RunDiagnostic(
                    type: 'history-error',
                    message: $exception->getMessage(),
                    path: $historyFile,
                );
            }
        }

        $exitCode = $this->resolveExitCode($diagnostics, $findings, $failThreshold);
        $report = new AnalysisReport(
            toolVersion: Application::VERSION,
            requestedPaths: $paths,
            format: $format->value,
            failOn: $failThreshold->value,
            filesDiscovered: count($discoveryResult->files),
            filesParsed: count(array_filter($analysisUnits, static fn ($unit): bool => !$unit->hasParseErrors())),
            ignoredPaths: $discoveryResult->ignoredPaths,
            missingPaths: $discoveryResult->missingPaths,
            diagnostics: $diagnostics,
            findings: $findings,
            exitCode: $exitCode,
            configPath: $configPath,
            mutation: $mutationAnalysis,
            score: $score,
            diff: $diff,
            trend: $trend,
            baseline: $baselineReport,
        );

        $this->renderReport($report, $format, $output);

        return $exitCode;
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

                return $finding->pillar !== Pillar::Secrets
                    || !is_string($preview)
                    || !in_array($preview, $allowedPreviews, true);
            },
        ));
    }

    private function parseFormat(mixed $value, OutputInterface $output): ?OutputFormat
    {
        $rawValue = is_string($value) ? $value : OutputFormat::Text->value;
        $format = OutputFormat::fromInput($rawValue);

        if (!$format instanceof OutputFormat) {
            $output->writeln(sprintf('<error>USAGE-ERROR Unsupported output format "%s". Use text, json, html, markdown, github, or hotspot.</error>', $rawValue));
        }

        return $format;
    }

    /**
     * @param list<string> $paths
     */
    private function parseFailThreshold(
        mixed $value,
        OutputFormat $format,
        array $paths,
        ?string $configPath,
        OutputInterface $output,
    ): ?FailThreshold {
        $rawValue = is_string($value) ? $value : FailThreshold::Error->value;
        $failThreshold = FailThreshold::fromInput($rawValue);

        if ($failThreshold instanceof FailThreshold) {
            return $failThreshold;
        }

        $report = new AnalysisReport(
            toolVersion: Application::VERSION,
            requestedPaths: $paths,
            format: $format->value,
            failOn: $rawValue,
            filesDiscovered: 0,
            filesParsed: 0,
            ignoredPaths: [],
            missingPaths: [],
            diagnostics: [new RunDiagnostic(
                'usage-error',
                sprintf('Unsupported fail threshold "%s". Use advisory, warning, error, or none.', $rawValue),
            )],
            findings: [],
            exitCode: Command::INVALID,
            configPath: $configPath,
        );
        $this->renderReport($report, $format, $output);

        return null;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function diffMode(InputInterface $input): ?string
    {
        if (!$input->hasParameterOption('--diff')) {
            return null;
        }

        $value = $input->getOption('diff');

        return is_string($value) && $value !== '' ? $value : 'working-tree';
    }

    /**
     * @param list<string> $paths
     */
    private function parseMutationBudget(
        mixed $value,
        OutputFormat $format,
        array $paths,
        ?string $configPath,
        string $failOn,
        OutputInterface $output,
    ): int|false|null {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !preg_match('/^\d+$/', $value)) {
            $this->renderReport(
                new AnalysisReport(
                    toolVersion: Application::VERSION,
                    requestedPaths: $paths,
                    format: $format->value,
                    failOn: $failOn,
                    filesDiscovered: 0,
                    filesParsed: 0,
                    ignoredPaths: [],
                    missingPaths: [],
                    diagnostics: [new RunDiagnostic(
                        'usage-error',
                        'Unsupported mutation budget. Use a non-negative integer.',
                    )],
                    findings: [],
                    exitCode: Command::INVALID,
                    configPath: $configPath,
                ),
                $format,
                $output,
            );

            return false;
        }

        return (int) $value;
    }

    /**
     * @param list<Finding> $findings
     * @param list<RunDiagnostic> $diagnostics
     */
    private function applyBaseline(
        string $projectRoot,
        ?string $baselinePath,
        ?string $generateBaselinePath,
        array &$findings,
        ?DiffResult $diff,
        array &$diagnostics,
    ): ?BaselineReport {
        $store = new BaselineStore($projectRoot);

        if ($generateBaselinePath !== null) {
            try {
                $baseline = $store->write($generateBaselinePath, $findings);
            } catch (BaselineException $exception) {
                $diagnostics[] = new RunDiagnostic(
                    type: 'baseline-error',
                    message: $exception->getMessage(),
                    path: $generateBaselinePath,
                );

                return null;
            }

            return new BaselineReport(
                path: $baseline->path,
                generated: true,
                totalEntries: count($baseline->entries),
                suppressedFindings: 0,
                staleEvaluation: 'generated',
            );
        }

        if ($baselinePath === null) {
            return null;
        }

        try {
            $baseline = $store->read($baselinePath);
            $application = (new BaselineFilter())->apply($baseline, $findings, $diff instanceof DiffResult && $diff->active);
        } catch (BaselineException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type: 'baseline-error',
                message: $exception->getMessage(),
                path: $baselinePath,
            );

            return null;
        }

        $findings = $application['findings'];

        return $application['report'];
    }

    /**
     * @param list<RunDiagnostic> $diagnostics
     */
    private function buildMutationAnalysis(
        string $projectRoot,
        ?string $infectionReportPath,
        bool $infectionRun,
        string $infectionBin,
        ?string $infectionConfigPath,
        ?string $mutationBaselinePath,
        ?int $mutationBudget,
        array &$diagnostics,
    ): ?MutationAnalysisResult {
        if ($infectionReportPath === null) {
            $this->addMutationOptionDiagnostics($infectionRun, $infectionConfigPath, $mutationBaselinePath, $mutationBudget, $diagnostics);

            return null;
        }

        if ($infectionRun) {
            $runResult = (new InfectionRunner())->run($projectRoot, $infectionBin, $infectionConfigPath);

            if ($runResult->diagnostic instanceof RunDiagnostic) {
                $diagnostics[] = $runResult->diagnostic;

                return null;
            }

            if ($runResult->exitCode !== Command::SUCCESS && !is_file($this->absolutePath($projectRoot, $infectionReportPath))) {
                $diagnostics[] = new RunDiagnostic(
                    type: 'mutation-run-error',
                    message: sprintf(
                        'Infection exited with code %d before producing the requested report.',
                        $runResult->exitCode,
                    ),
                    path: $infectionReportPath,
                );

                return null;
            }
        }

        $parser = new InfectionReportParser($projectRoot);

        try {
            $report = $parser->parse($infectionReportPath);
            $baselineReport = $mutationBaselinePath === null ? null : $parser->parse($mutationBaselinePath);
        } catch (MutationReportException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type: 'mutation-report-error',
                message: $exception->getMessage(),
                path: $infectionReportPath,
            );

            return null;
        }

        return new MutationAnalysisResult($report, $baselineReport, $mutationBudget);
    }

    /**
     * @param list<RunDiagnostic> $diagnostics
     */
    private function addMutationOptionDiagnostics(
        bool $infectionRun,
        ?string $infectionConfigPath,
        ?string $mutationBaselinePath,
        ?int $mutationBudget,
        array &$diagnostics,
    ): void {
        if ($infectionRun) {
            $diagnostics[] = new RunDiagnostic(
                type: 'usage-error',
                message: '--infection-run requires --infection-report because Infection writes full JSON through configured log paths.',
            );
        }

        if ($infectionConfigPath !== null) {
            $diagnostics[] = new RunDiagnostic(
                type: 'usage-error',
                message: '--infection-config only applies with --infection-run and --infection-report.',
                path: $infectionConfigPath,
            );
        }

        if ($mutationBaselinePath !== null) {
            $diagnostics[] = new RunDiagnostic(
                type: 'usage-error',
                message: '--mutation-baseline requires --infection-report.',
                path: $mutationBaselinePath,
            );
        }

        if ($mutationBudget !== null) {
            $diagnostics[] = new RunDiagnostic(
                type: 'usage-error',
                message: '--mutation-budget requires --infection-report.',
            );
        }
    }

    private function absolutePath(string $projectRoot, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . $path;
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

    private function renderReport(AnalysisReport $report, OutputFormat $format, OutputInterface $output): void
    {
        $renderer = match ($format) {
            OutputFormat::Json => new JsonReporter(),
            OutputFormat::Html => new HtmlReporter(),
            OutputFormat::Markdown => new MarkdownReporter(),
            OutputFormat::Github => new GithubAnnotationsReporter(),
            OutputFormat::Hotspot => new HotspotReporter(),
            OutputFormat::Text => new TextReporter(),
        };
        $output->write($renderer->render($report));
    }
}
