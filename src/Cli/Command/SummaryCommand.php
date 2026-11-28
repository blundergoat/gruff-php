<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\InlineSuppressionFilter;
use GruffPhp\Engine\Analysis\SensitiveExclusionFilter;
use GruffPhp\Engine\Analysis\SensitiveExclusionSummary;
use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Cli\Application;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Results\Scoring\ScoreCalculator;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs `gruff-php summary` for callers who need the score, top rules, and worst files at a glance.
 *
 * The same analysis data feeds terminal tables and JSON without individual findings.
 * Use `analyse` or `report` to inspect a finding and its suggested fix.
 */
final class SummaryCommand extends Command
{
    /**
     * Schema identifier stamped on JSON output so tools can tell which summary shape they received.
     */
    public const SCHEMA_VERSION = AnalysisReport::SUMMARY_SCHEMA_VERSION;

    /**
     * Default number of top rules and offenders shown when the user does not pass `--top`.
     */
    private const DEFAULT_TOP = 10;

    /**
     * Registers the paths argument, flags, and help text shown for gruff-php summary.
     *
     * @return void - Configures arguments, options, and help for summary runs.
     */
    protected function configure(): void
    {
        $this
            ->setName('summary')
            ->setDescription('Print a compact digest of a scan: per-pillar finding counts, top rules, and top file offenders. Runs the analyser once and renders only the summary; no per-finding spam.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff-php.yaml file for this run.')
            ->addOption('deep-scan-budget', null, InputOption::VALUE_REQUIRED, 'Bound structural analysis as <lines>:<bytes>, or disable it with off.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', default: 'text')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many top rules and file offenders to list.', default: (string)self::DEFAULT_TOP)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.');
    }

    /**
     * Runs gruff-php summary by resolving setup options, offering config initialization, analysing once, and rendering the selected summary format.
     *
     * @param InputInterface  $input  - Parsed console arguments and options for this summary invocation.
     * @param OutputInterface $output - Destination for the rendered summary and any usage or config errors.
     *
     * @return int - Setup, prompt, or rendered-summary exit status.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->projectRoot($input, $output);

        // A root-selection error already has a diagnostic and an exit code for the terminal.
        if (is_int($projectRoot)) {
            return $projectRoot;
        }

        $format = $this->summaryFormat($input, $output);

        // The summary can only render text or JSON. The format check has already told the caller why it stopped.
        if ($format === null) {
            return Command::INVALID;
        }

        $topLimit = $this->topLimit($input, $output);

        // A rejected --top value leaves no valid limit for the summary tables.
        if ($topLimit === null) {
            return Command::INVALID;
        }

        $configPath = $this->configPathOption($input, 'config');
        $noConfig   = (bool)$input->getOption('no-config');
        // The user both named a config and asked to skip config; obeying one would silently ignore the other.
        if ($this->hasConfigConflict($noConfig, $configPath, $output)) {
            return Command::INVALID;
        }

        $budget                 = $input->getOption('deep-scan-budget');
        $deepScanBudgetOverride = AnalyseCommandOptions::parseDeepScanBudgetOverride(is_string($budget) ? $budget : null);
        // Check the budget before prompting for config or scanning any files.
        if (is_string($deepScanBudgetOverride)) {
            $output->writeln(sprintf('<error>USAGE-ERROR %s</error>', $deepScanBudgetOverride));

            return Command::INVALID;
        }

        $promptExitCode = MissingConfigPrompt::maybeOffer(
            input:                   $input,
            output:                  $output,
            symfonyApplication:      $this->getApplication(),
            projectRoot:             $projectRoot,
            explicitConfigPath:      $configPath,
            shouldSkipConfig:        $noConfig,
            isMachineReadableFormat: $format === 'json',
        );

        // A declined or failed config setup already determined whether the summary can continue.
        if ($promptExitCode !== null) {
            return $promptExitCode;
        }

        $registry     = RuleRegistry::defaults();
        $configLoader = new ConfigLoader($projectRoot, shouldResolveFromLaunchDir: true);
        $config       = $this->analysisConfig(
            noConfig:               $noConfig,
            configPath:             $configPath,
            registry:               $registry,
            configLoader:           $configLoader,
            deepScanBudgetOverride: $deepScanBudgetOverride,
            output:                 $output,
        );

        // A failed config load leaves no trustworthy settings for this summary.
        if (!$config instanceof AnalysisConfig) {
            return Command::INVALID;
        }

        return $this->writeSummary(
            output:               $output,
            format:               $format,
            summaryReportData:    $this->summaryData(
                                   projectRoot:          $projectRoot,
                                   paths:                $this->paths($input),
                                   shouldIncludeIgnored: (bool)$input->getOption('include-ignored'),
                                   effectiveConfigPath:  $noConfig ? null : ($configPath ?? $configLoader->resolveConfigPath(null)),
                                   format:               $format,
                                   config:               $config,
                                   registry:             $registry,
                                   topLimit:             $topLimit,
                               ),
        );
    }

    /**
     * Chooses a shared reporting root while keeping relative scan targets anchored to the launch directory.
     * Rewrites the paths argument when the reporting root differs, so discovery still receives the requested targets.
     *
     * @param InputInterface  $input  - Console input carrying the path operands the root is resolved from.
     * @param OutputInterface $output - Destination for the error shown when no root can be chosen.
     *
     * @return string|int - Root path, FAILURE if getcwd() fails, or INVALID if the targets have no shared filesystem root.
     */
    private function projectRoot(InputInterface $input, OutputInterface $output): string|int
    {
        $launchDirectory = getcwd();
        // getcwd() fails when the launch directory was deleted or is unreadable; warn rather than guess a path.
        if ($launchDirectory === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $projectRoot = AnalyseCommandSetupBuilder::projectRootFromTargets($launchDirectory, $this->paths($input));

        // Operands without a shared root cannot use one project's config for their summary.
        if ($projectRoot === null) {
            $output->writeln('<error>scan targets do not share a filesystem root</error>');

            return Command::INVALID;
        }

        $input->setArgument('paths', AnalyseCommandSetupBuilder::targetsForRoot($launchDirectory, $projectRoot, $this->paths($input)));

        return $projectRoot;
    }

    /**
     * Picks terminal text vs machine JSON from `--format`, rejecting anything else before the run
     * so the user gets a one-line usage error rather than a broken render at the end.
     *
     * @param InputInterface  $input  - Console input carrying the optional --format value.
     * @param OutputInterface $output - Destination for the usage error shown when the format is unrecognised.
     *
     * @return string|null - Either 'text' or 'json'; null when `--format` was neither, ending the run with a usage error.
     */
    private function summaryFormat(InputInterface $input, OutputInterface $output): ?string
    {
        $format = $input->getOption('format');

        // Let the caller choose terminal tables or JSON for another tool.
        if (is_string($format) && in_array($format, ['text', 'json'], true)) {
            return $format;
        }

        $output->writeln(sprintf(
                             '<error>USAGE-ERROR Unsupported summary format "%s". Use text or json.</error>',
                             is_string($format) ? $format : '',
                         ));

        return null;
    }

    /**
     * Reads how many rows the top-rules and top-offenders lists show, letting the user widen or narrow the digest with --top.
     *
     * @param InputInterface  $input  - Console input carrying the optional --top value.
     * @param OutputInterface $output - Destination for the usage error shown when --top is not a non-negative integer.
     *
     * @return int|null - Row count for the top lists; null when --top wasn't a non-negative integer, ending the run with an error.
     */
    private function topLimit(InputInterface $input, OutputInterface $output): ?int
    {
        $topRaw = $input->getOption('top');
        // Restrict --top to unsigned decimal digits so negative and fractional row counts are rejected before conversion.
        if (is_string($topRaw) && preg_match('/^\d+$/', $topRaw) === 1) {
            return (int)$topRaw;
        }

        $output->writeln('<error>USAGE-ERROR --top must be a non-negative integer.</error>');

        return null;
    }

    /**
     * Reads a non-empty string option without trimming or coercing other input shapes.
     *
     * @param InputInterface $input - Parsed console input for this run.
     * @param string         $name  - Option name to read, such as `config`.
     *
     * @return string|null - The option text unchanged; null for an empty string or a non-string value.
     */
    private function configPathOption(InputInterface $input, string $name): ?string
    {
        $configPath = $input->getOption($name);


        return is_string($configPath) && $configPath !== '' ? $configPath : null;
    }

    /**
     * Rejects the one contradictory flag pair (`--config` together with `--no-config`) up front, so neither of the user's flags is silently ignored.
     *
     * @param bool            $noConfig   - Whether --no-config was requested.
     * @param string|null     $configPath - Explicit --config path, or null when none was given.
     * @param OutputInterface $output     - Destination for the usage error shown when both flags are present.
     *
     * @return bool - True when the options are invalid.
     */
    private function hasConfigConflict(bool $noConfig, ?string $configPath, OutputInterface $output): bool
    {

        // The config flags only conflict when the caller supplies a path and also tells summary to ignore it.
        if (!$noConfig || $configPath === null) {
            return false;
        }

        $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

        return true;
    }

    /**
     * Returns the string path operands, using an empty list for the default project scan.
     *
     * @param InputInterface $input - Console input carrying the variadic paths argument.
     *
     * @return list<string> - String operands in input order; empty selects the default project scan.
     */
    private function paths(InputInterface $input): array
    {
        $paths = $input->getArgument('paths');

        // With no positional operands, source discovery uses the project directory.
        if (!is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * Builds the settings that decide which rules run and which paths are ignored - the difference
     * between the scan the user configured and a bare default run.
     *
     * @param bool         $noConfig     - Skip YAML and seed defaults from the registry.
     * @param string|null  $configPath   - Explicit config file, or null to let the loader resolve the default.
     * @param RuleRegistry $registry     - Rules used for defaults and configuration validation.
     * @param ConfigLoader $configLoader - Reads and merges the selected or default project configuration.
     *
     * @param array{enabled: bool, maxLines: int, maxBytes: int}|null $deepScanBudgetOverride - CLI budget. Null keeps the configured limit.
     *
     * @param OutputInterface $output - Receives the CONFIG-ERROR message on ConfigException.
     *
     * @return AnalysisConfig|null - Effective settings; null after reporting a ConfigException.
     */
    private function analysisConfig(
        bool            $noConfig,
        ?string         $configPath,
        RuleRegistry    $registry,
        ConfigLoader    $configLoader,
        ?array          $deepScanBudgetOverride,
        OutputInterface $output,
    ): ?AnalysisConfig {
        try {

            $config = $noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($configPath, $registry);

            // An explicit budget takes priority over the selected project's setting for this summary.
            if ($deepScanBudgetOverride !== null) {
                $config = $config->withDeepScanBudget(
                    $deepScanBudgetOverride['enabled'],
                    $deepScanBudgetOverride['maxLines'],
                    $deepScanBudgetOverride['maxBytes'],
                    'cli',
                );
            }

            return $config;
        } catch (ConfigException $exception) {
            // A broken YAML file or invalid project setting stops summary with a config diagnostic.

            $output->writeln(sprintf('<error>[CONFIG-ERROR] %s</error>', $exception->getMessage()));
        }

        return null;
    }

    /**
     * Loads the requested sources, runs enabled analysis rules, and applies exclusions before scoring.
     * Builds the canonical machine report and the text renderer's aggregates from the surviving findings.
     *
     * @param string         $projectRoot          - Absolute project root that anchors source discovery.
     * @param list<string>   $paths                - Scan operands, with relative paths resolved against the project root.
     * @param bool           $shouldIncludeIgnored - Include ignored files through filesystem traversal.
     * @param string|null    $effectiveConfigPath  - Config path recorded in the report, or null when running without config.
     * @param string         $format               - Output format recorded in canonical run metadata.
     * @param AnalysisConfig $config               - Resolved configuration driving rule selection and ignore patterns.
     * @param RuleRegistry   $registry             - Available rules selected for execution by the configuration.
     * @param int            $topLimit             - Maximum rows kept for the top-rules and top-offenders lists.
     *
     * @return SummaryReportData - Source, score, and aggregate finding data.
     */
    private function summaryData(
        string         $projectRoot,
        array          $paths,
        bool           $shouldIncludeIgnored,
        ?string        $effectiveConfigPath,
        string         $format,
        AnalysisConfig $config,
        RuleRegistry   $registry,
        int            $topLimit,
    ): SummaryReportData {
        $sources = (new AnalysisSourceLoader())->load(
            $projectRoot,
            $paths,
            $shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
            $config->deepScanBudget(),
        );
        $findings = $registry->analyse(
                                             $sources->analysisUnits,
                                             new RuleContext($projectRoot, $config),
            shouldReleaseUnitsAfterAnalysis: true,
        );

        // Apply the same exclusions and inline suppression rules as analyse before counting or scoring findings.
        // Keep the audit rows so the summary can explain which findings it removed.
        $exclusionResult = (new SensitiveExclusionFilter())->apply($findings, $config->sensitiveExclusions(), InlineSuppressionFilter::forFiles($sources->discovery->files));
        $findings        = $exclusionResult->findings;

        $scorePillars    = $registry->enabledStaticPillars($config);
        $score           = (new ScoreCalculator())->calculate($findings, $sources->evaluatedFileCount(), null, DiffResult::inactive(), $topLimit, scorePillars: $scorePillars, analysisConfig: $config);
        $filesDiscovered = count($sources->discovery->files);
        $analysisReport  = new AnalysisReport(
            toolVersion:         Application::VERSION,
            requestedPaths:      $paths,
            format:              $format,
            failOn:              'none',
            filesDiscovered:     $filesDiscovered,
            filesParsed:         $sources->parsedFileCount(),
            ignoredPaths:        $sources->discovery->ignoredPaths,
            missingPaths:        $sources->discovery->missingPaths,
            diagnostics:         $sources->diagnostics,
            findings:            $findings,
            exitCode:            $this->summaryExitCode($sources->diagnostics),
            configPath:          $effectiveConfigPath,
            score:               $filesDiscovered === 0 ? null : $score,
            sensitiveExclusions: $exclusionResult->summaries,
            machineContext:      [
                'ignoredPathDetails' => $sources->discovery->ignoredPathDetails,
                'shouldIncludeIgnored' => $shouldIncludeIgnored,
                'projectRoot' => $projectRoot,
                'unfilteredFindings' => null,
            ],
        );

        return new SummaryReportData(
            paths:               $paths,
            configPath:          $effectiveConfigPath,
            analysisReport:      $analysisReport,
            sourcesDiscovered:   $filesDiscovered,
            sourcesParsed:       $sources->parsedFileCount(),
            ignoredPaths:        count($sources->discovery->ignoredPaths),
            missingPaths:        count($sources->discovery->missingPaths),
            parseErrors:         $this->parseErrorCount($sources->diagnostics),
            score:               $score,
            totals:              $this->severityTotals($findings),
            topRules:            array_slice($this->aggregateByRule($findings, $this->pillarLookup($registry)), 0, $topLimit),
            topOffenders:        array_slice($score->topOffenders, 0, $topLimit),
            sensitiveExclusions: $exclusionResult->summaries,
            diagnostics:         $sources->diagnostics,
        );
    }

    /**
     * The last step the user sees: send the digest to the terminal as formatted text, or as raw
     * JSON for a script or editor to consume.
     *
     * @param OutputInterface   $output            - Destination for the rendered summary or an encode-failure error.
     * @param string            $format            - Validated output format, either 'text' or 'json'.
     * @param SummaryReportData $summaryReportData - Aggregated run data to render.
     *
     * @return int - Encoding failure status for JSON, otherwise the analysis report's exit status.
     */
    private function writeSummary(OutputInterface $output, string $format, SummaryReportData $summaryReportData): int
    {

        // JSON keeps the full summary data available to tools that consume the report.
        if ($format === 'json') {
            try {
                // Raw output keeps the JSON payload free of console style markup.
                $output->write($this->renderJson($summaryReportData) . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                // If report data exceeds the JSON encoder's limits, show an encoding error before returning to the shell.

                $output->writeln(sprintf('<error>Unable to encode summary: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return $summaryReportData->analysisReport->exitCode;
        }

        $output->write($this->renderText($summaryReportData));


        return $summaryReportData->analysisReport->exitCode;
    }

    /**
     * Maps each rule to its pillar so findings can be grouped under the naming/security/complexity headings the user reads in the summary.
     *
     * @param RuleRegistry $registry - Rule set whose definitions supply the rule-id-to-pillar mapping.
     *
     * @return array<string, string> - Pillar values keyed by rule ID.
     */
    private function pillarLookup(RuleRegistry $registry): array
    {
        $pillarLookup = [];

        // Read the catalogue once so the top-rule table can show the pillar beside each rule ID.
        foreach ($registry->all() as $rule) {
            $definition                    = $rule->definition();
            $pillarLookup[$definition->id] = $definition->pillar->value;
        }

        return $pillarLookup;
    }

    /**
     * Aggregates all findings into per-rule counts, ordered by total count and then rule ID.
     *
     * @param list<Finding>         $findings     - Findings to aggregate into per-rule summary rows.
     * @param array<string, string> $pillarLookup - Rule pillars; a missing ID falls back to the finding's pillar.
     *
     * @return list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}> - Count descending, ID; empty if none.
     */
    private function aggregateByRule(array $findings, array $pillarLookup): array
    {
        $aggregates = [];

        // Count each finding toward the rule that the caller will see in the ranked list.
        foreach ($findings as $finding) {
            $ruleId = $finding->ruleId;

            // Start a row when a rule first contributes a visible finding.
            if (!isset($aggregates[$ruleId])) {
                $aggregates[$ruleId] = [
                    'ruleId' => $ruleId,
                    'count' => 0,
                    'advisory' => 0,
                    'warning' => 0,
                    'error' => 0,
                    'pillar' => $pillarLookup[$ruleId] ?? $finding->pillar->value,
                ];
            }

            $aggregates[$ruleId]['count']++;
            $aggregates[$ruleId][$finding->severity->value]++;
        }

        $rows = array_values($aggregates);
        usort($rows, static function (array $left, array $right): int {
            $countDelta = $right['count'] <=> $left['count'];

            // Put the most frequent rules first before applying the requested --top limit.
            if ($countDelta !== 0) {
                return $countDelta;
            }

            return strcmp($left['ruleId'], $right['ruleId']);
        });

        return $rows;
    }

    /**
     * Counts findings per severity for the summary's finding totals.
     *
     * @param list<Finding> $findings - Findings to count by severity; empty input keeps every bucket at zero.
     *
     * @return array{advisory: int, warning: int, error: int, total: int} - Counts by severity and total; all zero for empty input.
     */
    private function severityTotals(array $findings): array
    {
        $totals = ['advisory' => 0, 'warning' => 0, 'error' => 0, 'total' => count($findings)];

        // Put each finding into the severity bucket shown in the summary.
        foreach ($findings as $finding) {
            $totals[$finding->severity->value]++;
        }

        return $totals;
    }

    /**
     * Counts parse-error diagnostics for the summary's parse-error total.
     *
     * @param list<\GruffPhp\Engine\Analysis\RunDiagnostic> $diagnostics - Run diagnostics; only parse-error entries count.
     *
     * @return int - Number of parse-error diagnostics in the source set.
     */
    private function parseErrorCount(array $diagnostics): int
    {
        $count = 0;

        // Count only parse errors in the parse-error total. Other diagnostics have their own messages.
        foreach ($diagnostics as $diagnostic) {

            // A config, discovery, or budget note does not count as a failed PHP parse.
            if ($diagnostic->type === 'parse-error') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Derives the machine report's process status from diagnostics without applying a finding gate.
     *
     * @param list<\GruffPhp\Engine\Analysis\RunDiagnostic> $diagnostics - Run diagnostics whose fatal entries invalidate the summary.
     *
     * @return int - Invalid-command status for a fatal diagnostic, otherwise success.
     */
    private function summaryExitCode(array $diagnostics): int
    {
        // Check run diagnostics before returning an exit code to the shell.
        foreach ($diagnostics as $diagnostic) {
            // A fatal diagnostic means the summary cannot represent a completed scan.
            if ($diagnostic->isFatal) {
                return Command::INVALID;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Lays out the terminal digest: header, composite grade, pillar table, ranked rules, and scored files.
     * Adds baseline guidance when the run has findings.
     *
     * @param SummaryReportData $summaryReportData - Aggregated run data to format as aligned console text.
     *
     * @return string - Human-readable summary report.
     */
    private function renderText(SummaryReportData $summaryReportData): string
    {
        // FAMILY-CONTRACT section 1 requires the masthead and composite block before all port-specific summary lines.



        $lines   = [];
        $lines[] = sprintf('%s %s summary', Application::NAME, Application::VERSION);
        // A run that evaluated nothing has no composite, and printing 100.00 would call it perfect.
        $lines[] = $summaryReportData->score->composite === null
            ? 'Composite: n/a (nothing evaluated)'
            : sprintf('Composite: %s (%.2f / 100)', $summaryReportData->score->composite->letter, $summaryReportData->score->composite->score);
        $lines[] = sprintf(
            'Findings: %d total · %d error · %d warning · %d advisory',
            $summaryReportData->totals['total'],
            $summaryReportData->totals['error'],
            $summaryReportData->totals['warning'],
            $summaryReportData->totals['advisory'],
        );
        $lines[] = '';
        // Show the paths the user scanned, or "(none)" to signal the default whole-project run.
        $lines[] = sprintf('Paths     %s', $summaryReportData->paths === [] ? '(none)' : implode(', ', $summaryReportData->paths));
        $lines[] = sprintf('Config    %s', $summaryReportData->configPath ?? '(none)');
        $lines[] = sprintf(
            'Files     %d discovered, %d parsed, %d ignored, %d missing, %d parse errors',
            $summaryReportData->sourcesDiscovered,
            $summaryReportData->sourcesParsed,
            $summaryReportData->ignoredPaths,
            $summaryReportData->missingPaths,
            $summaryReportData->parseErrors
        );
        $lines[] = sprintf('Scope     %s', $summaryReportData->score->scope);
        $lines[] = sprintf('Score note %s', $summaryReportData->score->explanation);

        array_push($lines, ...$this->diagnosticLines($summaryReportData));
        $lines[] = '';
        $lines[] = 'Pillars';
        array_push($lines, ...$this->pillarLines($summaryReportData));
        array_push($lines, ...$this->topRuleLines($summaryReportData));
        array_push($lines, ...$this->topOffenderLines($summaryReportData));

        // With findings on the board, point the user at the baseline workflow for accepting known debt.
        if ($summaryReportData->totals['total'] > 0) {
            $lines[] = '';
            $lines[] = 'Baseline  After review, `gruff-php analyse --generate-baseline` records current findings as known debt.';
            $lines[] = '          Use `gruff-php analyse --no-baseline` to audit without a baseline.';
        }

        $suppressionLine = SensitiveExclusionSummary::describeTotal($summaryReportData->sensitiveExclusions);

        // Use the same suppression wording as analyse. When there is no audit to show, leave the footer out.
        if ($suppressionLine !== null) {
            $lines[] = '';
            $lines[] = $suppressionLine;
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Renders the run-diagnostic block, which reports notes such as a bounded deep scan or a parse failure.
     *
     * @param SummaryReportData $summaryReportData - Aggregated run data carrying the diagnostics to print.
     *
     * @return list<string> - Leading blank line, heading, and one row per diagnostic; empty when none exist.
     */
    private function diagnosticLines(SummaryReportData $summaryReportData): array
    {
        // Omit the diagnostics section when there is no run problem to show.
        if ($summaryReportData->diagnostics === []) {
            return [];
        }

        $lines = ['', 'Diagnostics'];
        // Show the most specific available location so the caller can find what needs attention.
        foreach ($summaryReportData->diagnostics as $diagnostic) {
            $location = $diagnostic->filePath ?? $diagnostic->path;
            // A source diagnostic with a known line can point directly to it. A generic path cannot.
            if ($location !== null && $diagnostic->filePath !== null && $diagnostic->line !== null) {
                $location .= ':' . $diagnostic->line;
            }
            // Bounded deep-scan messages already include their path=... token, so prepending $location would print the path twice.
            $lines[] = $location === null || $diagnostic->type === 'bounded-deep-scan'
                ? sprintf('  [%s] %s', strtoupper($diagnostic->type), $diagnostic->message)
                : sprintf('  [%s] %s %s', strtoupper($diagnostic->type), $location, $diagnostic->message);
        }

        return $lines;
    }

    /**
     * Renders one aligned row per pillar, ordered by descending scored finding count.
     *
     * @param SummaryReportData $summaryReportData - Aggregated run data carrying the scored pillars.
     *
     * @return list<string> - One row per supplied pillar, most findings first.
     */
    private function pillarLines(SummaryReportData $summaryReportData): array
    {
        $sortedPillars = $summaryReportData->score->pillars;
        usort($sortedPillars, static function ($left, $right): int {
            return $right->findings <=> $left->findings;
        });

        $pillarWidth = $this->columnWidth(array_map(static fn($pillar): string => $pillar->pillar, $sortedPillars), 14);
        $lines       = [];

        // Mark ungraded pillars as n/a so an absent score does not look like a low score.
        foreach ($sortedPillars as $pillar) {
            $grade = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
            // An absent grade must remain n/a rather than look like a measured zero.
            $scoreText = $pillar->grade === null ? '  n/a ' : sprintf('%6.2f', $pillar->grade->score);
            $lines[]   = sprintf(
                '  %-' . $pillarWidth . 's %s %s findings=%-5d advisory=%-5d warning=%-5d error=%-5d',
                $pillar->pillar,
                $grade,
                $scoreText,
                $pillar->findings,
                $pillar->advisory,
                $pillar->warning,
                $pillar->error,
            );
        }

        return $lines;
    }

    /**
     * Renders the highest-volume rules so a user can see which rules produce the largest finding counts.
     *
     * @param SummaryReportData $summaryReportData - Aggregated run data carrying the ranked rules.
     *
     * @return list<string> - Leading blank line, heading, and one row per supplied rule; empty when no rules are supplied.
     */
    private function topRuleLines(SummaryReportData $summaryReportData): array
    {
        // Skip an empty ranking so a successful scan does not show an empty table.
        if ($summaryReportData->topRules === []) {
            return [];
        }

        $lines   = ['', sprintf('Top %d rules by finding count', count($summaryReportData->topRules))];
        $idWidth = $this->columnWidth(array_map(static fn(array $ruleSummary): string => $ruleSummary['ruleId'], $summaryReportData->topRules), 30);
        // Add one compact row per selected rule with its severity counts.
        foreach ($summaryReportData->topRules as $ruleSummary) {
            $lines[] = sprintf(
                '  %5d  %-' . $idWidth . 's  %s  a=%d w=%d e=%d',
                $ruleSummary['count'],
                $ruleSummary['ruleId'],
                $ruleSummary['pillar'],
                $ruleSummary['advisory'],
                $ruleSummary['warning'],
                $ruleSummary['error'],
            );
        }

        return $lines;
    }

    /**
     * Renders the lowest-scoring files, so a user knows where to start reading.
     *
     * @param SummaryReportData $summaryReportData - Aggregated run data carrying the ranked files.
     *
     * @return list<string> - Leading blank line, heading, and one row per supplied file; empty when no files are supplied.
     */
    private function topOffenderLines(SummaryReportData $summaryReportData): array
    {
        // Show the file ranking only when findings produced offenders.
        if ($summaryReportData->topOffenders === []) {
            return [];
        }

        $lines     = ['', sprintf('Top %d file offenders', count($summaryReportData->topOffenders))];
        $fileWidth = $this->columnWidth(array_map(static fn($file): string => $file->filePath, $summaryReportData->topOffenders), 30);
        // Mark a file with no grade as n/a rather than assigning a score to ungraded work.
        foreach ($summaryReportData->topOffenders as $file) {
            $score   = $file->grade === null ? 'n/a' : sprintf('%.2f', $file->grade->score);
            $lines[] = sprintf(
                '  %s  %6s  %-' . $fileWidth . 's  findings=%-4d a=%d w=%d e=%d',
                $file->grade->letter ?? 'n/a',
                $score,
                $file->filePath,
                $file->findings,
                $file->advisory,
                $file->warning,
                $file->error,
            );
        }

        return $lines;
    }

    /**
     * Serialises the canonical analysis report as a summary without individual findings.
     *
     * @param SummaryReportData $summaryReportData - Run data whose canonical report supplies the summary payload.
     *
     * @return string - JSON-encoded summary report.
     * @throws JsonException - When the summary payload cannot be encoded.
     */
    private function renderJson(SummaryReportData $summaryReportData): string
    {
        // Substitute invalid UTF-8 bytes with U+FFFD so they do not cause a malformed-UTF-8 encoding error.
        return json_encode(
            $summaryReportData->analysisReport->toSummaryArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Measures the widest cell in a column so the digest's pillar, rule, and offender tables stay aligned and readable in a plain terminal.
     *
     * @param list<string> $columnTexts - Rendered cell text for one summary column.
     * @param int          $minimum     - Floor width applied when every value is shorter, keeping columns from collapsing.
     *
     * @return int - Width needed for aligned summary columns.
     */
    private function columnWidth(array $columnTexts, int $minimum): int
    {
        $maximum = $minimum;
        // Measure the labels users will read so longer names still fit the table.
        foreach ($columnTexts as $columnText) {
            $length = strlen($columnText);
            // Grow the column only when the current label would overrun it.
            if ($length > $maximum) {
                $maximum = $length;
            }
        }

        return $maximum;
    }
}
