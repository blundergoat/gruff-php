<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

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
 * Backs the `gruff-php summary` command - the one-screen health verdict on a codebase.
 *
 * Reach for this when a user wants "how good is this code?" at a glance instead of scrolling
 * through every finding: it runs the analyser once, then prints the composite grade, per-pillar
 * grades, the rules that fire most, and the worst files. Just the digest, nothing else. The
 * fuller per-finding view lives in the `analyse` and `report` commands.
 */
final class SummaryCommand extends Command
{
    /**
     * Schema identifier stamped on JSON output so tools can tell which summary shape they received.
     */
    public const SCHEMA_VERSION = 'gruff.summary.v2';

    /**
     * Default number of top rules and offenders shown when the user does not pass `--top`.
     */
    private const DEFAULT_TOP = 10;

    /**
     * Registers the `summary` command's paths argument, flags, and `--help` text - everything the
     * user can type after `gruff-php summary`.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('summary')
            ->setDescription('Print a compact digest of a scan: per-pillar finding counts, top rules, and top file offenders. Runs the analyser once and renders only the summary; no per-finding spam.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff-php.yaml file for this run.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', default: 'text')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many top rules and file offenders to list.', default: (string)self::DEFAULT_TOP)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.');
    }

    /**
     * Runs the whole command when a user types `gruff-php summary`: validate each flag, analyse
     * once, then render. Every early return below stops with a clear message instead of a broken digest.
     *
     * @param InputInterface  $input - Parsed console arguments and options for this summary invocation.
     * @param OutputInterface $output - Destination for the rendered summary and any usage or config errors.
     *
     * @return int - Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->projectRoot($output);
        // Without a readable working directory there is nothing to scan, so stop before pretending to.
        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        $format = $this->summaryFormat($input, $output);
        // The user asked for a format we don't render (say `--format=xml`); the usage error is already shown.
        if ($format === null) {
            return Command::INVALID;
        }

        $topLimit = $this->topLimit($input, $output);
        // `--top` wasn't a whole number (e.g. `--top=abc`), so we can't tell how many rows to list.
        if ($topLimit === null) {
            return Command::INVALID;
        }

        $configPath = $this->configPath($input);
        $noConfig   = (bool)$input->getOption('no-config');
        // The user both named a config and asked to skip config; obeying one would silently ignore the other.
        if ($this->hasConfigConflict($noConfig, $configPath, $output)) {
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
        // First run in a project with no config file: the prompt already handled the user, so pass on its verdict.
        if ($promptExitCode !== null) {
            return $promptExitCode;
        }

        $registry     = RuleRegistry::defaults();
        $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());
        $config       = $this->analysisConfig(
            noConfig:     $noConfig,
            configPath:   $configPath,
            registry:     $registry,
            configLoader: $configLoader,
            output:       $output,
        );
        // Their `.gruff-php.yaml` could not be loaded (missing, malformed, or naming an unknown rule); error shown.
        if (!$config instanceof AnalysisConfig) {
            return Command::INVALID;
        }

        return $this->writeSummary(
            output:            $output,
            format:            $format,
            summaryReportData: $this->summaryData(
                                   projectRoot:          $projectRoot,
                                   paths:                $this->paths($input),
                                   shouldIncludeIgnored: (bool)$input->getOption('include-ignored'),
                                   effectiveConfigPath:  $noConfig ? null : ($configPath ?? $configLoader->resolveConfigPath(null)),
                                   config:               $config,
                                   registry:             $registry,
                                   topLimit:             $topLimit,
                               ),
        );
    }

    /**
     * Anchors the run to the directory the user launched from, since every path shown in the
     * summary is displayed relative to it.
     *
     * @param OutputInterface $output - Destination for the error shown when the working directory is unreadable.
     *
     * @return string|null - Project root path; null when the working directory can't be read, aborting the summary.
     */
    private function projectRoot(OutputInterface $output): ?string
    {
        $projectRoot = getcwd();
        // getcwd() fails when the launch directory was deleted or is unreadable; warn rather than guess a path.
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return null;
        }

        return $projectRoot;
    }

    /**
     * Picks terminal text vs machine JSON from `--format`, rejecting anything else before the run
     * so the user gets a one-line usage error rather than a broken render at the end.
     *
     * @param InputInterface  $input - Console input carrying the optional --format value.
     * @param OutputInterface $output - Destination for the usage error shown when the format is unrecognised.
     *
     * @return string|null - Either 'text' or 'json'; null when `--format` was neither, ending the run with a usage error.
     */
    private function summaryFormat(InputInterface $input, OutputInterface $output): ?string
    {
        $format = $input->getOption('format');
        // Accept the request only when it is a format we can actually render; everything else falls through.
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
     * Reads how many rows the top-rules and top-offenders lists show, letting the user widen or
     * narrow the digest with `--top`.
     *
     * @param InputInterface  $input - Console input carrying the optional --top value.
     * @param OutputInterface $output - Destination for the usage error shown when --top is not a non-negative integer.
     *
     * @return int|null - Row count for the top lists; null when `--top` wasn't a non-negative integer, ending the run with an error.
     */
    private function topLimit(InputInterface $input, OutputInterface $output): ?int
    {
        $topRaw = $input->getOption('top');
        // Accept only unsigned decimal digits, so `--top` can't smuggle in a negative or fractional row count.
        if (is_string($topRaw) && preg_match('/^\d+$/', $topRaw) === 1) {
            return (int)$topRaw;
        }

        $output->writeln('<error>USAGE-ERROR --top must be a non-negative integer.</error>');

        return null;
    }

    /**
     * Resolves the user's `--config` flag to a concrete path or "none given", which decides whether
     * the default config is auto-discovered later.
     *
     * @param InputInterface $input - Console input carrying the optional --config value.
     *
     * @return string|null - Explicit config path; null when no `--config` was given, so the default `.gruff-php.yaml` is used.
     */
    private function configPath(InputInterface $input): ?string
    {
        $configPath = $input->getOption('config');

        // Treat an omitted or blank `--config` as "no explicit path", leaving the loader to find the default.
        return is_string($configPath) && $configPath !== '' ? $configPath : null;
    }

    /**
     * Rejects the one contradictory flag pair (`--config` together with `--no-config`) up front, so
     * neither of the user's flags is silently ignored.
     *
     * @param bool            $noConfig - Whether --no-config was requested.
     * @param string|null     $configPath - Explicit --config path, or null when none was given.
     * @param OutputInterface $output - Destination for the usage error shown when both flags are present.
     *
     * @return bool - True when the options are invalid.
     */
    private function hasConfigConflict(bool $noConfig, ?string $configPath, OutputInterface $output): bool
    {
        // Only the both-set case is a conflict; if either flag is absent there is nothing to reconcile.
        if (!$noConfig || $configPath === null) {
            return false;
        }

        $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

        return true;
    }

    /**
     * Collects the files or directories the user named; an empty result is the common case (a bare
     * `gruff-php summary`) and later means "scan the whole project".
     *
     * @param InputInterface $input - Console input carrying the variadic paths argument.
     *
     * @return list<string> - Paths the user named; empty means none were given, which scans the whole project.
     */
    private function paths(InputInterface $input): array
    {
        $paths = $input->getArgument('paths');
        // Defensive: this variadic argument is always an array in practice, so anything else means "none given".
        if (!is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * Builds the settings that decide which rules run and which paths are ignored - the difference
     * between the scan the user configured and a bare default run.
     *
     * @param bool            $noConfig - When true, skip the YAML file and build defaults straight from the registry.
     * @param string|null     $configPath - Explicit config file to load, or null to let the loader resolve the default.
     * @param RuleRegistry    $registry - Rule set used to seed defaults and validate configured rule ids.
     * @param ConfigLoader    $configLoader - Loader that reads and merges the YAML config for this project.
     * @param OutputInterface $output - Destination for the CONFIG-ERROR line shown when loading fails.
     *
     * @return AnalysisConfig|null - Resolved config; null when missing or malformed, so the run stops with a CONFIG-ERROR.
     */
    private function analysisConfig(
        bool            $noConfig,
        ?string         $configPath,
        RuleRegistry    $registry,
        ConfigLoader    $configLoader,
        OutputInterface $output,
    ): ?AnalysisConfig {
        try {
            // --no-config bypasses YAML entirely and runs the registry defaults.
            return $noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($configPath, $registry);
        } catch (ConfigException $exception) {
            // A broken config is the user's to fix, so surface the reason instead of a stack trace.
            $output->writeln(sprintf('<error>[CONFIG-ERROR] %s</error>', $exception->getMessage()));
        }

        return null;
    }

    /**
     * The core of the command: discover the user's sources, run every rule once, score the result,
     * and reduce thousands of findings to the handful of numbers the digest shows.
     *
     * @param string         $projectRoot - Absolute project root that anchors source discovery.
     * @param list<string>   $paths - Project-relative paths requested by the summary command.
     * @param bool           $shouldIncludeIgnored - When true, scan ignored files via filesystem traversal instead of Git/default ignores.
     * @param string|null    $effectiveConfigPath - Config path to echo in the report, or null when running without config.
     * @param AnalysisConfig $config - Resolved configuration driving rule selection and ignore patterns.
     * @param RuleRegistry   $registry - Rule set executed against the discovered sources.
     * @param int            $topLimit - Maximum rows kept for the top-rules and top-offenders lists.
     *
     * @return SummaryReportData - Source, score, and aggregate finding data.
     */
    private function summaryData(
        string         $projectRoot,
        array          $paths,
        bool           $shouldIncludeIgnored,
        ?string        $effectiveConfigPath,
        AnalysisConfig $config,
        RuleRegistry   $registry,
        int            $topLimit,
    ): SummaryReportData {
        $sources  = (new AnalysisSourceLoader())->load(
            $projectRoot,
            $paths,
            $shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        );
        $findings = $registry->analyse(
                                             $sources->analysisUnits,
                                             new RuleContext($projectRoot, $config),
            shouldReleaseUnitsAfterAnalysis: true,
        );
        $score    = (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive(), $topLimit, analysisConfig: $config);

        return new SummaryReportData(
            paths:             $paths,
            configPath:        $effectiveConfigPath,
            sourcesDiscovered: count($sources->discovery->files),
            sourcesParsed:     $sources->parsedFileCount(),
            ignoredPaths:      count($sources->discovery->ignoredPaths),
            missingPaths:      count($sources->discovery->missingPaths),
            parseErrors:       $this->parseErrorCount($sources->diagnostics),
            score:             $score,
            totals:            $this->severityTotals($findings),
            topRules:          array_slice($this->aggregateByRule($findings, $this->pillarLookup($registry)), 0, $topLimit),
            topOffenders:      array_slice($score->topOffenders, 0, $topLimit),
        );
    }

    /**
     * The last step the user sees: send the digest to the terminal as formatted text, or as raw
     * JSON for a script or editor to consume.
     *
     * @param OutputInterface   $output - Destination for the rendered summary or an encode-failure error.
     * @param string            $format - Validated output format, either 'text' or 'json'.
     * @param SummaryReportData $summaryReportData - Aggregated run data to render.
     *
     * @return int - Symfony command exit code.
     */
    private function writeSummary(OutputInterface $output, string $format, SummaryReportData $summaryReportData): int
    {
        // JSON is the machine-readable path (CI, editors); text is the default a person reads in the terminal.
        if ($format === 'json') {
            try {
                // Raw output keeps the JSON payload free of console style markup.
                $output->write($this->renderJson($summaryReportData) . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                // Encoding only fails on genuinely unencodable data; tell the user rather than emit half a document.
                $output->writeln(sprintf('<error>Unable to encode summary: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $output->write($this->renderText($summaryReportData));

        return Command::SUCCESS;
    }

    /**
     * Maps each rule to its pillar so findings can be grouped under the naming/security/complexity
     * headings the user reads in the summary.
     *
     * @param RuleRegistry $registry - Rule set whose definitions supply the rule-id-to-pillar mapping.
     *
     * @return array<string, string> - Pillar values keyed by rule ID.
     */
    private function pillarLookup(RuleRegistry $registry): array
    {
        $pillarLookup = [];
        // Walk every registered rule so any finding can later be traced back to the pillar it belongs to.
        foreach ($registry->all() as $rule) {
            $definition                    = $rule->definition();
            $pillarLookup[$definition->id] = $definition->pillar->value;
        }

        return $pillarLookup;
    }

    /**
     * Builds the "top rules" list - which checks the user's code trips most often, ranked worst-first
     * so the biggest problems sit at the top.
     *
     * @param list<Finding>         $findings - Findings to aggregate into per-rule summary rows.
     * @param array<string, string> $pillarLookup - Rule-id to pillar map from the registry; findings fall back to their own pillar when absent.
     *
     * @return list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}> - per-rule tallies ordered by
     *                            descending total count, ties broken by rule id; empty when there are no findings
     */
    private function aggregateByRule(array $findings, array $pillarLookup): array
    {
        $aggregates = [];
        // Tally findings per rule so the digest can show which checks the user's code trips most often.
        foreach ($findings as $finding) {
            $ruleId = $finding->ruleId;
            // First time this rule has fired this run: open a fresh row before counting into it.
            if (!isset($aggregates[$ruleId])) {
                $aggregates[$ruleId] = [
                    'ruleId'   => $ruleId,
                    'count'    => 0,
                    'advisory' => 0,
                    'warning'  => 0,
                    'error'    => 0,
                    'pillar'   => $pillarLookup[$ruleId] ?? $finding->pillar->value,
                ];
            }

            $aggregates[$ruleId]['count']++;
            $aggregates[$ruleId][$finding->severity->value]++;
        }

        $rows = array_values($aggregates);
        usort($rows, static function (array $left, array $right): int {
            $countDelta = $right['count'] <=> $left['count'];
            // Order by finding count, busiest rule first; only a genuine tie falls through to the id tie-break.
            if ($countDelta !== 0) {
                return $countDelta;
            }

            return strcmp($left['ruleId'], $right['ruleId']);
        });

        return $rows;
    }

    /**
     * Counts findings per severity to fill the headline "N error · N warning · N advisory" line the
     * user reads first to gauge how serious the run was.
     *
     * @param list<Finding> $findings - Findings to count by severity; empty input keeps every bucket at zero.
     *
     * @return array{advisory: int, warning: int, error: int, total: int} - per-severity finding counts plus the grand total; every key is zero when
     *                         there are no findings
     */
    private function severityTotals(array $findings): array
    {
        $totals = ['advisory' => 0, 'warning' => 0, 'error' => 0, 'total' => count($findings)];
        // Sort every finding into its severity bucket to build that one-line headline tally.
        foreach ($findings as $finding) {
            $totals[$finding->severity->value]++;
        }

        return $totals;
    }

    /**
     * Reports how many files could not be parsed, so the "N parse errors" figure warns the user that
     * some code was skipped entirely rather than scanned and found clean.
     *
     * @param list<\GruffPhp\Engine\Analysis\RunDiagnostic> $diagnostics - Run diagnostics to scan; only parse-error entries are counted.
     *
     * @return int - Number of parse-error diagnostics in the source set.
     */
    private function parseErrorCount(array $diagnostics): int
    {
        $count = 0;
        // Diagnostics also carry non-fatal notes, so count only the entries that mean a file went unanalysed.
        foreach ($diagnostics as $diagnostic) {
            // A parse error is the one kind that left a file unscanned; that's what the user needs warning about.
            if ($diagnostic->type === 'parse-error') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Lays out the terminal digest the user reads: header, composite grade, and pillar table, plus -
     * only when there are findings - the top rules, worst files, and a baseline nudge.
     *
     * @param SummaryReportData $summaryReportData - Aggregated run data to format as aligned console text.
     *
     * @return string - Human-readable summary report.
     */
    private function renderText(SummaryReportData $summaryReportData): string
    {
        $lines   = [];
        $lines[] = sprintf('%s %s summary', Application::NAME, Application::VERSION);
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
        $lines[] = '';
        $lines[] = sprintf('Composite: %s (%.2f / 100)', $summaryReportData->score->composite->letter, $summaryReportData->score->composite->score);
        $lines[] = sprintf(
            'Findings: %d total · %d error · %d warning · %d advisory',
            $summaryReportData->totals['total'],
            $summaryReportData->totals['error'],
            $summaryReportData->totals['warning'],
            $summaryReportData->totals['advisory'],
        );
        $lines[] = sprintf('Scope     %s', $summaryReportData->score->scope);
        $lines[] = sprintf('Score note %s', $summaryReportData->score->explanation);
        $lines[] = '';
        $lines[] = 'Pillars';

        $sortedPillars = $summaryReportData->score->pillars;
        usort($sortedPillars, static function ($left, $right): int {
            return $right->findings <=> $left->findings;
        });

        $pillarWidth = $this->columnWidth(array_map(static fn($pillar): string => $pillar->pillar, $sortedPillars), 14);
        // One aligned row per pillar (naming, complexity, security, …) - the grades users scan first.
        foreach ($sortedPillars as $pillar) {
            $grade     = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
            // A pillar with no applicable rules has no grade, so show "n/a" rather than a misleading zero.
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

        // Print the "top rules" block only when something actually fired; on clean code it would just be clutter.
        if ($summaryReportData->topRules !== []) {
            $lines[] = '';
            $lines[] = sprintf('Top %d rules by finding count', count($summaryReportData->topRules));
            $idWidth = $this->columnWidth(array_map(static fn(array $ruleSummary): string => $ruleSummary['ruleId'], $summaryReportData->topRules), 30);
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
        }

        // Likewise, rank the worst files only when there are findings to rank; no findings means no offenders.
        if ($summaryReportData->topOffenders !== []) {
            $lines[]   = '';
            $lines[]   = sprintf('Top %d file offenders', count($summaryReportData->topOffenders));
            $fileWidth = $this->columnWidth(array_map(static fn($file): string => $file->filePath, $summaryReportData->topOffenders), 30);
            foreach ($summaryReportData->topOffenders as $file) {
                $lines[] = sprintf(
                    '  %s  %6.2f  %-' . $fileWidth . 's  findings=%-4d a=%d w=%d e=%d',
                    $file->grade->letter,
                    $file->grade->score,
                    $file->filePath,
                    $file->findings,
                    $file->advisory,
                    $file->warning,
                    $file->error,
                );
            }
        }

        // With findings on the board, point the user at the baseline workflow for accepting known debt.
        if ($summaryReportData->totals['total'] > 0) {
            $lines[] = '';
            $lines[] = 'Baseline  After review, `gruff-php analyse --generate-baseline` records current findings as known debt.';
            $lines[] = '          Use `gruff-php analyse --no-baseline` to audit without a baseline.';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * The machine-readable twin of the text digest: the same numbers under a stable schema so CI
     * gates and editor integrations can read a run's verdict.
     *
     * @param SummaryReportData $summaryReportData - Aggregated run data to serialise under the gruff.summary schema.
     *
     * @return string - JSON-encoded summary report.
     * @throws JsonException When the summary payload cannot be encoded.
     */
    private function renderJson(SummaryReportData $summaryReportData): string
    {
        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'tool'          => ['name' => Application::NAME, 'version' => Application::VERSION],
            'scope'         => [
                'paths'           => $summaryReportData->paths,
                'configPath'      => $summaryReportData->configPath,
                'filesDiscovered' => $summaryReportData->sourcesDiscovered,
                'filesParsed'     => $summaryReportData->sourcesParsed,
                'ignoredPaths'    => $summaryReportData->ignoredPaths,
                'missingPaths'    => $summaryReportData->missingPaths,
                'parseErrors'     => $summaryReportData->parseErrors,
                'scope'           => $summaryReportData->score->scope,
            ],
            'composite'     => $summaryReportData->score->composite->toArray(),
            'findings'      => $summaryReportData->totals,
            'pillars'       => array_map(static fn($pillar): array => $pillar->toArray(), $summaryReportData->score->pillars),
            'topRules'      => $summaryReportData->topRules,
            'topOffenders'  => array_map(static fn($file): array => $file->toArray(), $summaryReportData->topOffenders),
        ];

        // Invalid source bytes become U+FFFD so `summary --format json` always hands the user parseable JSON.
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Measures the widest cell in a column so the digest's pillar, rule, and offender tables stay
     * aligned and readable in a plain terminal.
     *
     * @param list<string> $columnTexts - Rendered cell text for one summary column.
     * @param int          $minimum - Floor width applied when every value is shorter, keeping columns from collapsing.
     *
     * @return int - Width needed for aligned summary columns.
     */
    private function columnWidth(array $columnTexts, int $minimum): int
    {
        $maximum = $minimum;
        // Grow the column to the longest value present, so no cell is truncated in the aligned output.
        foreach ($columnTexts as $columnText) {
            $length = strlen($columnText);
            // Track the widest cell seen; that width is what every row underneath lines up against.
            if ($length > $maximum) {
                $maximum = $length;
            }
        }

        return $maximum;
    }
}
