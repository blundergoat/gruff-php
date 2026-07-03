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
 * Renders a compact project quality summary from one analysis run.
 */
final class SummaryCommand extends Command
{
    /**
     * Schema identifier for machine-readable summary output.
     */
    public const SCHEMA_VERSION = 'gruff.summary.v2';

    /**
     * Default number of top rules and offenders shown in summaries.
     */
    private const DEFAULT_TOP = 10;

    /**
     * Register summary CLI arguments, options, and command metadata.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
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
     * Run analysis once and render a compact project summary.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param InputInterface  $input - Parsed console arguments and options for this summary invocation.
     * @param OutputInterface $output - Destination for the rendered summary and any usage or config errors.
     *
     * @return int - Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->projectRoot($output);
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        $format = $this->summaryFormat($input, $output);
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($format === null) {
            return Command::INVALID;
        }

        $topLimit = $this->topLimit($input, $output);
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($topLimit === null) {
            return Command::INVALID;
        }

        $configPath = $this->configPath($input);
        $noConfig   = (bool)$input->getOption('no-config');
        // User view: choose the terminal output branch for this case.
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
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
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
        // User view: choose the terminal output branch for this case.
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
                                   // User view: missing data becomes a safe terminal output default.
                                   effectiveConfigPath:  $noConfig ? null : ($configPath ?? $configLoader->resolveConfigPath(null)),
                                   config:               $config,
                                   registry:             $registry,
                                   topLimit:             $topLimit,
                               ),
        );
    }

    /**
     * Resolve the current project root or emit an error when it cannot be read.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param OutputInterface $output - Destination for the error shown when the working directory is unreadable.
     *
     * @return string|null - Project root path, or null when unavailable.
     */
    private function projectRoot(OutputInterface $output): ?string
    {
        $projectRoot = getcwd();
        // User view: choose the terminal output branch for this case.
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return null;
        }

        return $projectRoot;
    }

    /**
     * Parse and validate the requested summary output format.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param InputInterface  $input - Console input carrying the optional --format value.
     * @param OutputInterface $output - Destination for the usage error shown when the format is unrecognised.
     *
     * @return string|null - Summary format, or null after emitting a usage error.
     */
    private function summaryFormat(InputInterface $input, OutputInterface $output): ?string
    {
        $format = $input->getOption('format');
        // User view: choose the terminal output branch for this case.
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
     * Parse and validate the top-N summary limit.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param InputInterface  $input - Console input carrying the optional --top value.
     * @param OutputInterface $output - Destination for the usage error shown when --top is not a non-negative integer.
     *
     * @return int|null - Top limit, or null after emitting a usage error.
     */
    private function topLimit(InputInterface $input, OutputInterface $output): ?int
    {
        $topRaw = $input->getOption('top');
        // Accept only unsigned decimal digits for the summary row limit.
        // User view: choose the terminal output branch for this case.
        if (is_string($topRaw) && preg_match('/^\d+$/', $topRaw) === 1) {
            return (int)$topRaw;
        }

        $output->writeln('<error>USAGE-ERROR --top must be a non-negative integer.</error>');

        return null;
    }

    /**
     * Read the optional config path from console input.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param InputInterface $input - Console input carrying the optional --config value.
     *
     * @return string|null - Config path, or null when omitted.
     */
    private function configPath(InputInterface $input): ?string
    {
        $configPath = $input->getOption('config');

        // User view: an empty value becomes a clear terminal output fallback.
        return is_string($configPath) && $configPath !== '' ? $configPath : null;
    }

    /**
     * Emit and report whether mutually exclusive config flags were supplied.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param bool            $noConfig - Whether --no-config was requested.
     * @param string|null     $configPath - Explicit --config path, or null when none was given.
     * @param OutputInterface $output - Destination for the usage error shown when both flags are present.
     *
     * @return bool - True when the options are invalid.
     */
    private function hasConfigConflict(bool $noConfig, ?string $configPath, OutputInterface $output): bool
    {
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if (!$noConfig || $configPath === null) {
            return false;
        }

        $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

        return true;
    }

    /**
     * Return normalized path arguments from console input.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param InputInterface $input - Console input carrying the variadic paths argument.
     *
     * @return list<string> - Project-relative paths requested by the summary command.
     */
    private function paths(InputInterface $input): array
    {
        $paths = $input->getArgument('paths');
        // User view: choose the terminal output branch for this case.
        if (!is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * Load analysis configuration or emit a config error.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param bool            $noConfig - When true, skip the YAML file and build defaults straight from the registry.
     * @param string|null     $configPath - Explicit config file to load, or null to let the loader resolve the default.
     * @param RuleRegistry    $registry - Rule set used to seed defaults and validate configured rule ids.
     * @param ConfigLoader    $configLoader - Loader that reads and merges the YAML config for this project.
     * @param OutputInterface $output - Destination for the CONFIG-ERROR line shown when loading fails.
     *
     * @return AnalysisConfig|null - Loaded config, or null when config loading fails.
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
            $output->writeln(sprintf('<error>[CONFIG-ERROR] %s</error>', $exception->getMessage()));
        }

        return null;
    }

    /**
     * Build summary render data from one analysis pass.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
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
     * Write text or JSON summary output.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param OutputInterface   $output - Destination for the rendered summary or an encode-failure error.
     * @param string            $format - Validated output format, either 'text' or 'json'.
     * @param SummaryReportData $summaryReportData - Aggregated run data to render.
     *
     * @return int - Symfony command exit code.
     */
    private function writeSummary(OutputInterface $output, string $format, SummaryReportData $summaryReportData): int
    {
        // User view: choose the terminal output branch for this case.
        if ($format === 'json') {
            try {
                // Raw output keeps the JSON payload free of console style markup.
                $output->write($this->renderJson($summaryReportData) . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                $output->writeln(sprintf('<error>Unable to encode summary: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $output->write($this->renderText($summaryReportData));

        return Command::SUCCESS;
    }

    /**
     * Build a lookup table from rule ID to pillar value.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param RuleRegistry $registry - Rule set whose definitions supply the rule-id-to-pillar mapping.
     *
     * @return array<string, string> - Pillar values keyed by rule ID.
     */
    private function pillarLookup(RuleRegistry $registry): array
    {
        $pillarLookup = [];
        // User view: add each item that can appear in terminal output.
        foreach ($registry->all() as $rule) {
            $definition                    = $rule->definition();
            $pillarLookup[$definition->id] = $definition->pillar->value;
        }

        return $pillarLookup;
    }

    /**
     * Group summary findings by rule identifier and severity.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
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
        // User view: add each item that can appear in terminal output.
        foreach ($findings as $finding) {
            $ruleId = $finding->ruleId;
            // User view: choose the terminal output branch for this case.
            if (!isset($aggregates[$ruleId])) {
                $aggregates[$ruleId] = [
                    'ruleId'   => $ruleId,
                    'count'    => 0,
                    'advisory' => 0,
                    'warning'  => 0,
                    'error'    => 0,
                    // User view: missing data becomes a safe terminal output default.
                    'pillar'   => $pillarLookup[$ruleId] ?? $finding->pillar->value,
                ];
            }

            $aggregates[$ruleId]['count']++;
            $aggregates[$ruleId][$finding->severity->value]++;
        }

        $rows = array_values($aggregates);
        usort($rows, static function (array $left, array $right): int {
            $countDelta = $right['count'] <=> $left['count'];
            // User view: choose the terminal output branch for this case.
            if ($countDelta !== 0) {
                return $countDelta;
            }

            return strcmp($left['ruleId'], $right['ruleId']);
        });

        return $rows;
    }

    /**
     * Count findings by severity for summary output.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param list<Finding> $findings - Findings to count by severity; empty input keeps every bucket at zero.
     *
     * @return array{advisory: int, warning: int, error: int, total: int} - per-severity finding counts plus the grand total; every key is zero when
     *                         there are no findings
     */
    private function severityTotals(array $findings): array
    {
        $totals = ['advisory' => 0, 'warning' => 0, 'error' => 0, 'total' => count($findings)];
        // User view: add each item that can appear in terminal output.
        foreach ($findings as $finding) {
            $totals[$finding->severity->value]++;
        }

        return $totals;
    }

    /**
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param list<\GruffPhp\Engine\Analysis\RunDiagnostic> $diagnostics - Run diagnostics to scan; only parse-error entries are counted.
     *
     * @return int - Number of parse-error diagnostics in the source set.
     */
    private function parseErrorCount(array $diagnostics): int
    {
        $count = 0;
        // User view: add each item that can appear in terminal output.
        foreach ($diagnostics as $diagnostic) {
            // User view: choose the terminal output branch for this case.
            if ($diagnostic->type === 'parse-error') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Render a human-readable summary report.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
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
        // User view: an empty value becomes a clear terminal output fallback.
        $lines[] = sprintf('Paths     %s', $summaryReportData->paths === [] ? '(none)' : implode(', ', $summaryReportData->paths));
        // User view: missing data becomes a safe terminal output default.
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
        // User view: add each item that can appear in terminal output.
        foreach ($sortedPillars as $pillar) {
            // User view: missing data becomes the expected terminal output state.
            $grade     = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
            // User view: missing data becomes the expected terminal output state.
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

        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($summaryReportData->topRules !== []) {
            $lines[] = '';
            $lines[] = sprintf('Top %d rules by finding count', count($summaryReportData->topRules));
            $idWidth = $this->columnWidth(array_map(static fn(array $ruleSummary): string => $ruleSummary['ruleId'], $summaryReportData->topRules), 30);
            // User view: add each item that can appear in terminal output.
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

        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($summaryReportData->topOffenders !== []) {
            $lines[]   = '';
            $lines[]   = sprintf('Top %d file offenders', count($summaryReportData->topOffenders));
            $fileWidth = $this->columnWidth(array_map(static fn($file): string => $file->filePath, $summaryReportData->topOffenders), 30);
            // User view: add each item that can appear in terminal output.
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

        // User view: choose the terminal output branch for this case.
        if ($summaryReportData->totals['total'] > 0) {
            $lines[] = '';
            $lines[] = 'Baseline  After review, `gruff-php analyse --generate-baseline` records current findings as known debt.';
            $lines[] = '          Use `gruff-php analyse --no-baseline` to audit without a baseline.';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Render a JSON-encoded summary report.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
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
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param list<string> $columnTexts - Rendered cell text for one summary column.
     * @param int          $minimum - Floor width applied when every value is shorter, keeping columns from collapsing.
     *
     * @return int - Width needed for aligned summary columns.
     */
    private function columnWidth(array $columnTexts, int $minimum): int
    {
        $maximum = $minimum;
        // User view: add each item that can appear in terminal output.
        foreach ($columnTexts as $columnText) {
            $length = strlen($columnText);
            // User view: choose the terminal output branch for this case.
            if ($length > $maximum) {
                $maximum = $length;
            }
        }

        return $maximum;
    }
}
