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

final class SummaryCommand extends Command
{
    public const SCHEMA_VERSION = 'gruff.summary.v1';

    private const DEFAULT_TOP = 10;

    protected function configure(): void
    {
        $this
            ->setName('summary')
            ->setDescription('Print a compact digest of a scan: per-pillar finding counts, top rules, and top file offenders. Runs the analyser once and renders only the summary; no per-finding spam.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff.yaml file for this run.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many top rules and file offenders to list.', (string) self::DEFAULT_TOP)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['text', 'json'], true)) {
            $output->writeln(sprintf(
                '<error>USAGE-ERROR Unsupported summary format "%s". Use text or json.</error>',
                is_string($format) ? $format : '',
            ));

            return Command::INVALID;
        }

        $topRaw = $input->getOption('top');
        if (!is_string($topRaw) || preg_match('/^\d+$/', $topRaw) !== 1) {
            $output->writeln('<error>USAGE-ERROR --top must be a non-negative integer.</error>');

            return Command::INVALID;
        }
        $top = (int) $topRaw;

        $configPath = $input->getOption('config');
        $configPath = is_string($configPath) ? $configPath : null;
        $noConfig = (bool) $input->getOption('no-config');
        if ($noConfig && $configPath !== null) {
            $output->writeln('<error>USAGE-ERROR --no-config cannot be combined with --config.</error>');

            return Command::INVALID;
        }

        $paths = $input->getArgument('paths');
        if (!is_array($paths)) {
            $paths = [];
        }
        /** @var list<string> $paths */
        $paths = array_values(array_filter($paths, 'is_string'));

        $registry = RuleRegistry::defaults();
        $configLoader = new ConfigLoader($projectRoot, ConfigLoader::packageRoot());

        try {
            $config = $noConfig
                ? AnalysisConfig::fromRegistry($registry)
                : $configLoader->load($configPath, $registry);
        } catch (ConfigException $exception) {
            $output->writeln(sprintf('<error>[CONFIG-ERROR] %s</error>', $exception->getMessage()));

            return Command::INVALID;
        }

        $effectiveConfigPath = $noConfig ? null : ($configPath ?? $configLoader->resolveConfigPath(null));
        $includeIgnored = (bool) $input->getOption('include-ignored');
        $sources = (new AnalysisSourceLoader())->load(
            $projectRoot,
            $paths,
            $includeIgnored,
            $config->ignoredPathPatterns(),
        );

        $findings = $registry->analyse($sources->analysisUnits, new RuleContext($projectRoot, $config));
        $findings = array_merge($findings, (new CompositeFindingFactory())->build($findings));
        $score = (new ScoreCalculator())->calculate($findings, null, DiffResult::inactive());

        $pillarLookup = [];
        foreach ($registry->all() as $rule) {
            $definition = $rule->definition();
            $pillarLookup[$definition->id] = $definition->pillar->value;
        }

        $ruleAggregates = $this->aggregateByRule($findings, $pillarLookup);
        $totals = $this->severityTotals($findings);
        $parseErrors = $this->parseErrorCount($sources->diagnostics);
        $topRules = array_slice($ruleAggregates, 0, $top);
        $topOffenders = array_slice($score->topOffenders, 0, $top);

        if ($format === 'json') {
            try {
                $output->write($this->renderJson(
                    paths: $paths,
                    configPath: $effectiveConfigPath,
                    sourcesDiscovered: count($sources->discovery->files),
                    sourcesParsed: $sources->parsedFileCount(),
                    ignoredPaths: count($sources->discovery->ignoredPaths),
                    missingPaths: count($sources->discovery->missingPaths),
                    parseErrors: $parseErrors,
                    score: $score,
                    totals: $totals,
                    topRules: $topRules,
                    topOffenders: $topOffenders,
                ) . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                $output->writeln(sprintf('<error>Unable to encode summary: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $output->write($this->renderText(
            paths: $paths,
            configPath: $effectiveConfigPath,
            sourcesDiscovered: count($sources->discovery->files),
            sourcesParsed: $sources->parsedFileCount(),
            ignoredPaths: count($sources->discovery->ignoredPaths),
            missingPaths: count($sources->discovery->missingPaths),
            parseErrors: $parseErrors,
            score: $score,
            totals: $totals,
            topRules: $topRules,
            topOffenders: $topOffenders,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<Finding> $findings
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
     * @param list<string> $paths
     * @param array{advisory: int, warning: int, error: int, total: int} $totals
     * @param list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}> $topRules
     * @param list<\GruffPhp\Scoring\FileScore> $topOffenders
     */
    private function renderText(
        array $paths,
        ?string $configPath,
        int $sourcesDiscovered,
        int $sourcesParsed,
        int $ignoredPaths,
        int $missingPaths,
        int $parseErrors,
        \GruffPhp\Scoring\ScoreReport $score,
        array $totals,
        array $topRules,
        array $topOffenders,
    ): string {
        $lines = [];
        $lines[] = sprintf('gruff %s — summary', Application::VERSION);
        $lines[] = '';
        $lines[] = sprintf('Paths     %s', $paths === [] ? '(none)' : implode(', ', $paths));
        $lines[] = sprintf('Config    %s', $configPath ?? '(none)');
        $lines[] = sprintf('Files     %d discovered, %d parsed, %d ignored, %d missing, %d parse errors',
            $sourcesDiscovered, $sourcesParsed, $ignoredPaths, $missingPaths, $parseErrors);
        $lines[] = '';
        $lines[] = sprintf('Composite %s (%.2f / 100)', $score->composite->letter, $score->composite->score);
        $lines[] = sprintf('Scope     %s', $score->scope);
        $lines[] = '';
        $lines[] = 'Pillars';

        $sortedPillars = $score->pillars;
        usort($sortedPillars, static function ($left, $right): int {
            return $right->findings <=> $left->findings;
        });

        $pillarWidth = $this->columnWidth(array_map(static fn ($pillar): string => $pillar->pillar, $sortedPillars), 14);
        foreach ($sortedPillars as $pillar) {
            $grade = $pillar->grade === null ? 'n/a' : $pillar->grade->letter;
            $scoreText = $pillar->grade === null ? '  n/a ' : sprintf('%6.2f', $pillar->grade->score);
            $lines[] = sprintf(
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

        if ($topRules !== []) {
            $lines[] = '';
            $lines[] = sprintf('Top %d rules by finding count', count($topRules));
            $idWidth = $this->columnWidth(array_map(static fn (array $row): string => $row['ruleId'], $topRules), 30);
            foreach ($topRules as $row) {
                $lines[] = sprintf(
                    '  %5d  %-' . $idWidth . 's  %s  a=%d w=%d e=%d',
                    $row['count'],
                    $row['ruleId'],
                    $row['pillar'],
                    $row['advisory'],
                    $row['warning'],
                    $row['error'],
                );
            }
        }

        if ($topOffenders !== []) {
            $lines[] = '';
            $lines[] = sprintf('Top %d file offenders', count($topOffenders));
            $fileWidth = $this->columnWidth(array_map(static fn ($file): string => $file->filePath, $topOffenders), 30);
            foreach ($topOffenders as $file) {
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
            $totals['total'],
            $totals['advisory'],
            $totals['warning'],
            $totals['error'],
        );

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<string> $paths
     * @param array{advisory: int, warning: int, error: int, total: int} $totals
     * @param list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}> $topRules
     * @param list<\GruffPhp\Scoring\FileScore> $topOffenders
     * @throws JsonException
     */
    private function renderJson(
        array $paths,
        ?string $configPath,
        int $sourcesDiscovered,
        int $sourcesParsed,
        int $ignoredPaths,
        int $missingPaths,
        int $parseErrors,
        \GruffPhp\Scoring\ScoreReport $score,
        array $totals,
        array $topRules,
        array $topOffenders,
    ): string {
        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'tool' => ['name' => 'gruff', 'version' => Application::VERSION],
            'scope' => [
                'paths' => $paths,
                'configPath' => $configPath,
                'filesDiscovered' => $sourcesDiscovered,
                'filesParsed' => $sourcesParsed,
                'ignoredPaths' => $ignoredPaths,
                'missingPaths' => $missingPaths,
                'parseErrors' => $parseErrors,
                'scope' => $score->scope,
            ],
            'composite' => $score->composite->toArray(),
            'findings' => $totals,
            'pillars' => array_map(static fn ($pillar): array => $pillar->toArray(), $score->pillars),
            'topRules' => $topRules,
            'topOffenders' => array_map(static fn ($file): array => $file->toArray(), $topOffenders),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $values
     */
    private function columnWidth(array $values, int $minimum): int
    {
        $maximum = $minimum;
        foreach ($values as $value) {
            $length = strlen($value);
            if ($length > $maximum) {
                $maximum = $length;
            }
        }

        return $maximum;
    }
}
