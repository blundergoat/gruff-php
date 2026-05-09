<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\AnalysisReport;
use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Console\Application;
use GruffPhp\Mutation\InfectionReportParser;
use GruffPhp\Mutation\InfectionRunner;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Mutation\MutationFindingFactory;
use GruffPhp\Mutation\MutationReportException;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Reporting\FailThreshold;
use GruffPhp\Reporting\JsonReporter;
use GruffPhp\Reporting\OutputFormat;
use GruffPhp\Reporting\TextReporter;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceDiscovery;
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
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', OutputFormat::Text->value)
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the run: advisory, warning, error, or none.', FailThreshold::Error->value)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.')
            ->addOption('infection-report', null, InputOption::VALUE_REQUIRED, 'Path to a full Infection JSON report to ingest.')
            ->addOption('infection-run', null, InputOption::VALUE_NONE, 'Run Infection before ingesting the report path supplied by --infection-report.')
            ->addOption('infection-bin', null, InputOption::VALUE_REQUIRED, 'Infection executable for --infection-run.', 'infection')
            ->addOption('infection-config', null, InputOption::VALUE_REQUIRED, 'Path to infection.json5 for --infection-run.')
            ->addOption('mutation-baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline Infection JSON report for MSI diff mode.')
            ->addOption('mutation-budget', null, InputOption::VALUE_REQUIRED, 'Maximum escaped/timed-out mutants allowed.');
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
        $discoveryResult = $discovery->discover($paths, $includeIgnored);
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
        );

        $this->renderReport($report, $format, $output);

        return $exitCode;
    }

    private function parseFormat(mixed $value, OutputInterface $output): ?OutputFormat
    {
        $rawValue = is_string($value) ? $value : OutputFormat::Text->value;
        $format = OutputFormat::fromInput($rawValue);

        if (!$format instanceof OutputFormat) {
            $output->writeln(sprintf('<error>USAGE-ERROR Unsupported output format "%s". Use text or json.</error>', $rawValue));
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
        $renderer = $format === OutputFormat::Json ? new JsonReporter() : new TextReporter();
        $output->write($renderer->render($report));
    }
}
