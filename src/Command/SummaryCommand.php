<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Console\Application;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Finding;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Scoring\CompositeFindingFactory;
use GruffPhp\Scoring\ScoreCalculator;
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
    public const SCHEMA_VERSION = 'gruff.summary.v1';

    /**
     * Default number of top rules and offenders shown in summaries.
     */
    private const DEFAULT_TOP = 10;

    /**
     * Register summary CLI arguments, options, and command metadata.
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
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many top rules and file offenders to list.', default: (string) self::DEFAULT_TOP)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.');
    }

    /**
     * Run analysis once and render a compact project summary.
     *
     * @return int Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->projectRoot($output);
        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        $format = $this->summaryFormat($input, $output);
        if ($format === null) {
            return Command::INVALID;
        }

        $topLimit = $this->topLimit($input, $output);
        if ($topLimit === null) {
            return Command::INVALID;
        }

        $configPath = $this->configPath($input);
        $noConfig   = (bool) $input->getOption('no-config');
        if ($this->hasConfigConflict($noConfig, $configPath, $output)) {
            return Command::INVALID;
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
        if (!$config instanceof AnalysisConfig) {
            return Command::INVALID;
        }

        return $this->writeSummary(
            output:               $output,
            format:               $format,
            summaryReportData:    $this->summaryData(
                projectRoot:          $projectRoot,
                paths:                $this->paths($input),
                shouldIncludeIgnored: (bool) $input->getOption('include-ignored'),
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
     * @return string|null Project root path, or null when unavailable.
     */
    private function projectRoot(OutputInterface $output): ?string
    {
        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return null;
        }

        return $projectRoot;
    }

    /**
     * Parse and validate the requested summary output format.
     *
     * @return string|null Summary format, or null after emitting a usage error.
     */
    private function summaryFormat(InputInterface $input, OutputInterface $output): ?string
    {
        $format = $input->getOption('format');
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
     * @return int|null Top limit, or null after emitting a usage error.
     */
    private function topLimit(InputInterface $input, OutputInterface $output): ?int
    {
        $topRaw = $input->getOption('top');
        // Accept only unsigned decimal digits for the summary row limit.
        if (is_string($topRaw) && preg_match('/^\d+$/', $topRaw) === 1) {
            return (int) $topRaw;
        }

        $output->writeln('<error>USAGE-ERROR --top must be a non-negative integer.</error>');

        return null;
    }

    /**
     * Read the optional config path from console input.
     *
     * @return string|null Config path, or null when omitted.
     */
    private function configPath(InputInterface $input): ?string
    {
        $configPath = $input->getOption('config');

        return is_string($configPath) ? $configPath : null;
    }

    /**
     * Emit and report whether mutually exclusive config flags were supplied.
     *
     * @return bool True when the options are invalid.
     */
    private function hasConfigConflict(bool $noConfig, ?string $configPath, OutputInterface $output): bool
    {
        if (!$noConfig || $configPath === null) {
            return false;
        }

        $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

        return true;
    }

    /**
     * Return normalized path arguments from console input.
     *
     * @return list<string> Project-relative paths requested by the summary command.
     */
    private function paths(InputInterface $input): array
    {
        $paths = $input->getArgument('paths');
        if (!is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * Load analysis configuration or emit a config error.
     *
     * @return AnalysisConfig|null Loaded config, or null when config loading fails.
     */
    private function analysisConfig(
        bool $noConfig,
        ?string $configPath,
        RuleRegistry $registry,
        ConfigLoader $configLoader,
        OutputInterface $output,
    ): ?AnalysisConfig {
        try {
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
     * @param list<string> $paths Project-relative paths requested by the summary command.
     * @return SummaryReportData Source, score, and aggregate finding data.
     */
    private function summaryData(
        string $projectRoot,
        array $paths,
        bool $shouldIncludeIgnored,
        ?string $effectiveConfigPath,
        AnalysisConfig $config,
        RuleRegistry $registry,
        int $topLimit,
    ): SummaryReportData {
        $sources = (new AnalysisSourceLoader())->load(
            $projectRoot,
            $paths,
            $shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        );
        $findings = $registry->analyse($sources->analysisUnits, new RuleContext($projectRoot, $config));
        $findings = array_merge($findings, (new CompositeFindingFactory())->build($findings));
        $score    = (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive(), $topLimit);

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
     * @return int Symfony command exit code.
     */
    private function writeSummary(OutputInterface $output, string $format, SummaryReportData $summaryReportData): int
    {
        if ($format === 'json') {
            try {
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
     * @return array<string, string> Pillar values keyed by rule ID.
     */
    private function pillarLookup(RuleRegistry $registry): array
    {
        $pillarLookup = [];
        foreach ($registry->all() as $rule) {
            $definition                    = $rule->definition();
            $pillarLookup[$definition->id] = $definition->pillar->value;
        }

        return $pillarLookup;
    }

    /**
     * @param list<Finding>         $findings
     * @param array<string, string> $pillarLookup
     * @return list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}>
     */
    private function aggregateByRule(array $findings, array $pillarLookup): array
    {
        $aggregates = [];
        foreach ($findings as $finding) {
            $ruleId = $finding->ruleId;
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
            if ($countDelta !== 0) {
                return $countDelta;
            }

            return strcmp($left['ruleId'], $right['ruleId']);
        });

        return $rows;
    }

    /**
     * @param list<Finding> $findings
     * @return array{advisory: int, warning: int, error: int, total: int}
     */
    private function severityTotals(array $findings): array
    {
        $totals = ['advisory' => 0, 'warning' => 0, 'error' => 0, 'total' => count($findings)];
        foreach ($findings as $finding) {
            $totals[$finding->severity->value]++;
        }

        return $totals;
    }

    /**
     * @param list<\GruffPhp\Analysis\RunDiagnostic> $diagnostics
     * @return int Number of parse-error diagnostics in the source set.
     */
    private function parseErrorCount(array $diagnostics): int
    {
        $count = 0;
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->type === 'parse-error') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Render a human-readable summary report.
     *
     * @return string Human-readable summary report.
     */
    private function renderText(SummaryReportData $summaryReportData): string
    {
        $lines   = [];
        $lines[] = sprintf('%s %s — summary', Application::NAME, Application::VERSION);
        $lines[] = '';
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
        $lines[] = sprintf('Composite %s (%.2f / 100)', $summaryReportData->score->composite->letter, $summaryReportData->score->composite->score);
        $lines[] = sprintf('Scope     %s', $summaryReportData->score->scope);
        $lines[] = sprintf('Score note %s', $summaryReportData->score->explanation);
        $lines[] = '';
        $lines[] = 'Pillars';

        $sortedPillars = $summaryReportData->score->pillars;
        usort($sortedPillars, static function ($left, $right): int {
            return $right->findings <=> $left->findings;
        });

        $pillarWidth = $this->columnWidth(array_map(static fn ($pillar): string => $pillar->pillar, $sortedPillars), 14);
        foreach ($sortedPillars as $pillar) {
            $grade     = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
            $scoreText = $pillar->grade === null ? '  n/a ' : sprintf('%6.2f', $pillar->grade->score);
            $lines[]   = sprintf(
                '  %-' . $pillarWidth . 's %s %s findings=%-5d advisory=%-5d warning=%-5d error=%-5d',
                $pillar->pillar,
                $grade,
                $scoreText,
                $pillar->findings,
                $pillar->advisories,
                $pillar->warnings,
                $pillar->errors,
            );
        }

        if ($summaryReportData->topRules !== []) {
            $lines[] = '';
            $lines[] = sprintf('Top %d rules by finding count', count($summaryReportData->topRules));
            $idWidth = $this->columnWidth(array_map(static fn (array $ruleSummary): string => $ruleSummary['ruleId'], $summaryReportData->topRules), 30);
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

        if ($summaryReportData->topOffenders !== []) {
            $lines[]   = '';
            $lines[]   = sprintf('Top %d file offenders', count($summaryReportData->topOffenders));
            $fileWidth = $this->columnWidth(array_map(static fn ($file): string => $file->filePath, $summaryReportData->topOffenders), 30);
            foreach ($summaryReportData->topOffenders as $file) {
                $lines[] = sprintf(
                    '  %s  %6.2f  %-' . $fileWidth . 's  findings=%-4d a=%d w=%d e=%d',
                    $file->grade->letter,
                    $file->grade->score,
                    $file->filePath,
                    $file->findings,
                    $file->advisories,
                    $file->warnings,
                    $file->errors,
                );
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Totals    %d findings (advisory=%d, warning=%d, error=%d)',
            $summaryReportData->totals['total'],
            $summaryReportData->totals['advisory'],
            $summaryReportData->totals['warning'],
            $summaryReportData->totals['error'],
        );

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Render a JSON-encoded summary report.
     *
     * @return string JSON-encoded summary report.
     * @throws JsonException When the summary payload cannot be encoded.
     */
    private function renderJson(SummaryReportData $summaryReportData): string
    {
        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'tool' => ['name' => Application::NAME, 'version' => Application::VERSION],
            'scope' => [
                'paths' => $summaryReportData->paths,
                'configPath' => $summaryReportData->configPath,
                'filesDiscovered' => $summaryReportData->sourcesDiscovered,
                'filesParsed' => $summaryReportData->sourcesParsed,
                'ignoredPaths' => $summaryReportData->ignoredPaths,
                'missingPaths' => $summaryReportData->missingPaths,
                'parseErrors' => $summaryReportData->parseErrors,
                'scope' => $summaryReportData->score->scope,
            ],
            'composite' => $summaryReportData->score->composite->toArray(),
            'findings' => $summaryReportData->totals,
            'pillars' => array_map(static fn ($pillar): array => $pillar->toArray(), $summaryReportData->score->pillars),
            'topRules' => $summaryReportData->topRules,
            'topOffenders' => array_map(static fn ($file): array => $file->toArray(), $summaryReportData->topOffenders),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $columnTexts
     * @return int Width needed for aligned summary columns.
     */
    private function columnWidth(array $columnTexts, int $minimum): int
    {
        $maximum = $minimum;
        foreach ($columnTexts as $columnText) {
            $length = strlen($columnText);
            if ($length > $maximum) {
                $maximum = $length;
            }
        }

        return $maximum;
    }
}
