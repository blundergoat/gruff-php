<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Results\Baseline\BaselineApplicationOptions;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSelection;
use GruffPhp\Cli\Application as GruffApplication;
use GruffPhp\Results\Diff\ChangedLineRange;
use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Diff\GitDiffProvider;
use GruffPhp\Results\Diff\UnifiedDiffParser;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Output\Hook\HookFindingFilter;
use GruffPhp\Output\Hook\HookFindingIdentity;
use GruffPhp\Output\Hook\HookFindingPresenter;
use GruffPhp\Output\Hook\HookFindingScope;
use GruffPhp\Results\Mutation\MutationAnalysisOptions;
use GruffPhp\Results\Review\GitArchiveSnapshot;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\IgnoredPath;
use GruffPhp\Support\PathHelper;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emits the gruff.hook.v1 contract for editor/agent changed-code feedback.
 */
final class HookCommand extends Command
{
    /**
     * Contract version advertised in every hook payload and capability probe.
     */
    private const CONTRACT_VERSION = 'gruff.hook.v1';

    /**
     * Configure the hook command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('hook')
            ->setDescription('Run gruff-php using the cross-analyzer agent-hook contract.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'File to analyse. Can be repeated.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format. Hook mode supports json.', default: 'json')
            ->addOption('capabilities', null, InputOption::VALUE_NONE, 'Print hook capabilities and exit.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff-php.yaml file for this run.')
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.')
            ->addOption('changed-ranges', null, InputOption::VALUE_REQUIRED, 'Explicit changed line ranges, e.g. 3-3,8-10.')
            ->addOption('changed-scope', null, InputOption::VALUE_REQUIRED, 'Changed-region scope. Hook mode supports symbol.', default: 'symbol')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Use a git diff mode/base ref for changed regions and new-only filtering.')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Use a git base ref for changed regions and new-only filtering.')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Path to a prior gruff.hook.v1 JSON report for new-only filtering.')
            ->addOption('include-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only the named rule id. Can be repeated or comma-separated.')
            ->addOption('exclude-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip the named rule id. Can be repeated or comma-separated.');
    }

    /**
     * Execute the hook command.
     *
     * @param InputInterface  $input  - Console input.
     * @param OutputInterface $output - Console output.
     *
     * @return int - 0 when analysis ran; non-zero only for operational errors.
     * @throws JsonException When JSON encoding fails.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool)$input->getOption('capabilities')) {
            $this->writeJson($output, $this->capabilities());

            return Command::SUCCESS;
        }

        $format = $input->getOption('format');
        if ($format !== 'json') {
            $this->writeJson($output, $this->emptyReport(false, 'hook supports only --format json.'));

            return Command::INVALID;
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        $paths        = $this->paths($input);
        $changedScope = $this->stringOption($input, 'changed-scope') ?? 'symbol';
        if ($changedScope !== 'symbol') {
            $this->writeJson($output, $this->emptyReport(false, 'hook supports only --changed-scope symbol.'));

            return Command::INVALID;
        }

        $registry = RuleRegistry::defaults();

        try {
            $config = $this->config($input, $projectRoot, $registry);
        } catch (ConfigException $exception) {
            $this->writeJson($output, $this->emptyReport(false, $exception->getMessage()));

            return Command::INVALID;
        }

        try {
            $diff          = $this->changedRegion($input, $projectRoot, $paths);
            $analysisPaths = $this->analysisPaths($projectRoot, $paths, $diff);
            $analysis      = $this->analyse(
                projectRoot:          $projectRoot,
                paths:                $analysisPaths,
                shouldIncludeIgnored: (bool)$input->getOption('include-ignored'),
                config:               $config,
                registry:             $registry,
            );
            $findingSupport       = new AnalysisFindingSupport();
            $findings             = $findingSupport->filterAllowedSecretPreviews($analysis['findings'], $config);
            $baseStableIdentities = $this->baseStableIdentities(
                input:                $input,
                projectRoot:          $projectRoot,
                paths:                $analysisPaths ?? $paths,
                shouldIncludeIgnored: (bool)$input->getOption('include-ignored'),
                config:               $config,
            );
            $hasNewOnlySource = $baseStableIdentities !== null;
            $filterResult     = (new HookFindingFilter())->apply($findings, $diff, $baseStableIdentities ?? [], $hasNewOnlySource);

            $this->writeJson(
                $output,
                $this->report(
                    findings:        $filterResult->findings,
                    suppressedCount: $filterResult->suppressedCount,
                    ignoredPathRows: $analysis['sources']->discovery->ignoredPathDetails,
                    identities:       $filterResult->identities,
                    isConfigSchemaOk: true,
                    configError:      null,
                ),
            );

            return Command::SUCCESS;
        } catch (DiffException | RuntimeException $exception) {
            $this->writeJson($output, $this->emptyReport(true, $exception->getMessage()));

            return Command::INVALID;
        }
    }

    /**
     * Return the hook capability payload.
     *
     * @return array<string, mixed> - JSON-ready capabilities.
     */
    private function capabilities(): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'analyzer' => [
                'name' => 'gruff-php',
                'version' => GruffApplication::VERSION,
            ],
            'supports' => [
                'changedRanges' => true,
                'diff' => true,
                'baseline' => true,
                'scopeField' => true,
                'metadata' => true,
                'stableIdentity' => true,
                'ignoreReport' => true,
                'newOnly' => true,
            ],
            'flags' => [
                'changedRanges' => '--changed-ranges',
                'diff' => '--diff',
                'baseline' => '--baseline',
            ],
            'flagOrder' => 'any',
        ];
    }

    /**
     * Load the effective analysis config for hook mode.
     *
     * @param InputInterface $input       - Console input.
     * @param string         $projectRoot - Project root.
     * @param RuleRegistry   $registry    - Rule registry.
     *
     * @return AnalysisConfig - Effective config.
     */
    private function config(InputInterface $input, string $projectRoot, RuleRegistry $registry): AnalysisConfig
    {
        $configPath = $this->stringOption($input, 'config');
        if ((bool)$input->getOption('no-config') && $configPath !== null) {
            throw new ConfigException('--no-config cannot be combined with --config.');
        }

        $config = (bool)$input->getOption('no-config')
            ? AnalysisConfig::fromRegistry($registry)
            : (new ConfigLoader($projectRoot, ConfigLoader::packageRoot()))->load($configPath, $registry);

        $includeRules = $this->stringListOption($input, 'include-rule');
        $excludeRules = $this->stringListOption($input, 'exclude-rule');
        $ruleFilterError = $this->ruleFilterError($registry, $includeRules, $excludeRules);
        if ($ruleFilterError !== null) {
            throw new ConfigException($ruleFilterError);
        }
        if ($includeRules !== [] || $excludeRules !== []) {
            $config = $config->withRuleSelection($this->refinedSelection($config->ruleSelection(), $includeRules, $excludeRules));
        }

        return $config;
    }

    /**
     * Resolve the rule selection for hook --include-rule/--exclude-rule against the project config.
     *
     * --include-rule means "run only these ids" (per the option help), so it must narrow to exactly
     * those rules: RuleSelection::allows() ORs tier/pillar/rule includes, so inheriting the config's
     * tiers/pillars would widen the focused run. A bare --exclude-rule instead keeps the configured
     * selection and only drops the named rules, so a configured selection.rules narrowing is not
     * widened to the whole rule set (an empty include list means "all rules").
     *
     * @param RuleSelection $existing     - Selection already resolved from the project config.
     * @param list<string>  $includeRules - Hook --include-rule ids; when non-empty they focus the run.
     * @param list<string>  $excludeRules - Hook --exclude-rule ids dropped on top of the existing selection.
     *
     * @return RuleSelection - Focused selection for --include-rule, or the configured selection plus excludes.
     */
    private function refinedSelection(RuleSelection $existing, array $includeRules, array $excludeRules): RuleSelection
    {
        if ($includeRules !== []) {
            return new RuleSelection(rules: $includeRules, excludeRules: $excludeRules);
        }

        return new RuleSelection(
            tiers:          $existing->tiers,
            pillars:        $existing->pillars,
            rules:          $existing->rules,
            excludePillars: $existing->excludePillars,
            excludeRules:   array_values(array_unique([...$existing->excludeRules, ...$excludeRules])),
        );
    }

    /**
     * Validate hook rule filters before they can focus execution to zero rules.
     *
     * @param RuleRegistry $registry - Registry whose ids define valid hook rule filters.
     * @param list<string> $includeRules - Rule ids from --include-rule.
     * @param list<string> $excludeRules - Rule ids from --exclude-rule.
     *
     * @return string|null - Config-contract error for the first unknown id, or null when every id is registered.
     */
    private function ruleFilterError(RuleRegistry $registry, array $includeRules, array $excludeRules): ?string
    {
        foreach (['--include-rule' => $includeRules, '--exclude-rule' => $excludeRules] as $option => $ruleIds) {
            $unknownRuleIds = $registry->unknownRuleIds($ruleIds);
            if ($unknownRuleIds !== []) {
                return sprintf('Unknown rule id "%s" for %s.', $unknownRuleIds[0], $option);
            }
        }

        return null;
    }

    /**
     * Analyse a project root without legacy diff or baseline filtering.
     *
     * @param string            $projectRoot          - Project root.
     * @param list<string>|null $paths                - Paths to analyse, or null to analyse nothing.
     * @param bool              $shouldIncludeIgnored - Whether ignored files should be included.
     * @param AnalysisConfig    $config               - Effective config.
     * @param RuleRegistry      $registry             - Rule registry.
     *
     * @return array{sources: AnalysisSourceSet, findings: list<Finding>} - Native analysis output.
     */
    private function analyse(
        string $projectRoot,
        ?array $paths,
        bool $shouldIncludeIgnored,
        AnalysisConfig $config,
        RuleRegistry $registry,
    ): array {
        $options = new AnalyseCommandOptions(
            paths:                         $paths ?? [],
            shouldIncludeIgnored:          $shouldIncludeIgnored,
            configPath:                    null,
            noConfig:                      true,
            noCache:                       true,
            profile:                       'default',
            mutation:                      new MutationAnalysisOptions(
                                      infectionReportPath:           null,
                                      shouldRunInfection:            false,
                                      infectionBin:                  'infection',
                                      infectionConfigPath:           null,
                                      infectionTestFrameworkOptions: null,
                                      mutationBaselinePath:          null,
                                      mutationBudget:                null,
                                  ),
            diffMode:             null,
            since:                null,
            changedRanges:        null,
            changedScope:         'symbol',
            diffVs:               null,
            isChangedOnly:        false,
            historyFile:          null,
            noBaseline:           true,
            baseline:             new BaselineApplicationOptions(
                                      baselinePath:         null,
                                      isBaselineExplicit:   false,
                                      generateBaselinePath: null,
                                  ),
            reportEditorLink:    'none',
            isReportInteractive: false,
            pathsRelativeTo:     null,
            minSeverity:         null,
            includePillars:      [],
            excludePillars:      [],
            includeRules:        [],
            excludeRules:        [],
        );

        $branchReviewBuilder = new BranchReviewBuilder();
        $analysisPipeline    = new AnalysisPipeline($registry, $branchReviewBuilder->projectContextUnits(...));
        $analysisRun         = $analysisPipeline->runAnalysis(
            projectRoot:        $projectRoot,
            options:            $options,
            config:             $config,
            ruleContext:        new RuleContext($projectRoot, $config),
            reviewDiff:         null,
            analysisPaths:      $paths,
            discoverStart:      hrtime(true),
            ruleRunnerObserver: null,
        );

        return [
            'sources' => $analysisRun['sources'],
            'findings' => $analysisRun['findings'],
        ];
    }

    /**
     * Resolve changed-region input from hook flags.
     *
     * @param InputInterface $input       - Console input.
     * @param string         $projectRoot - Project root.
     * @param list<string>   $paths       - Requested paths.
     *
     * @return DiffResult|null - Active changed region or null for full scan.
     */
    private function changedRegion(InputInterface $input, string $projectRoot, array $paths): ?DiffResult
    {
        $changedRanges = $this->stringOption($input, 'changed-ranges');
        if ($changedRanges !== null) {
            $changedFiles = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $paths);
            if ($changedFiles === []) {
                throw new DiffException('--changed-ranges requires at least one file path.');
            }

            $changedLines = [];
            foreach ($changedFiles as $changedFile) {
                $changedLines[$changedFile] = $this->parseChangedRanges($changedRanges);
            }

            return new DiffResult(
                active:       true,
                mode:         'explicit-ranges',
                base:         null,
                changedLines: $changedLines,
                changedFiles: $changedFiles,
                message:      'Hook mode filters findings to explicit changed line ranges.',
            );
        }

        $since = $this->stringOption($input, 'since');
        if ($since !== null) {
            return (new GitDiffProvider())->changedLines($projectRoot, $since);
        }

        $diffMode = $this->diffMode($input);
        if ($diffMode === null) {
            return null;
        }

        if ($diffMode === '-') {
            $patch = stream_get_contents(STDIN);
            if ($patch === false) {
                throw new DiffException('Unable to read unified diff from stdin.');
            }

            $parsed = (new UnifiedDiffParser())->parse($patch);

            return new DiffResult(
                active:       true,
                mode:         'stdin',
                base:         null,
                changedLines: $parsed['lines'],
                changedFiles: $parsed['files'],
                message:      'Hook mode filters findings to changed regions from unified diff stdin.',
            );
        }

        return (new GitDiffProvider())->changedLines($projectRoot, $diffMode);
    }

    /**
     * Resolve paths the current analysis pass should scan.
     *
     * @param string          $projectRoot - Project root.
     * @param list<string>    $paths       - Requested paths.
     * @param DiffResult|null $diff        - Changed-region data.
     *
     * @return list<string>|null - Paths to scan, or null when an active diff has no existing files.
     */
    private function analysisPaths(string $projectRoot, array $paths, ?DiffResult $diff): ?array
    {
        if ($paths !== []) {
            return $paths;
        }

        if (!$diff instanceof DiffResult || !$diff->active) {
            return [];
        }

        $changedFiles = (new AnalysisFindingSupport())->existingChangedFiles($projectRoot, $diff->changedFiles);

        return $changedFiles === [] ? null : $changedFiles;
    }

    /**
     * Build the base stable-identity set from --baseline and/or --diff.
     *
     * @param InputInterface $input                - Console input.
     * @param string         $projectRoot          - Project root.
     * @param list<string>   $paths                - Paths to compare against the base.
     * @param bool           $shouldIncludeIgnored - Whether ignored files should be included.
     * @param AnalysisConfig $config               - Effective config.
     *
     * @return array<string, true>|null - Base identity set, or null when no new-only source was supplied.
     */
    private function baseStableIdentities(
        InputInterface $input,
        string $projectRoot,
        array $paths,
        bool $shouldIncludeIgnored,
        AnalysisConfig $config,
    ): ?array {
        $identities = null;

        $baselinePath = $this->stringOption($input, 'baseline');
        if ($baselinePath !== null) {
            $identities = $this->baselineIdentities($projectRoot, $baselinePath);
        }

        $baseRef = $this->baseRef($input);
        if ($baseRef !== null) {
            $baseIdentities = $this->baseRefIdentities(
                projectRoot:          $projectRoot,
                baseRef:              $baseRef,
                paths:                $paths,
                shouldIncludeIgnored: $shouldIncludeIgnored,
                config:               $config,
            );
            $identities = $identities === null ? $baseIdentities : $identities + $baseIdentities;
        }

        return $identities;
    }

    /**
     * Read stable identities from a hook JSON baseline report.
     *
     * @param string $projectRoot  - Project root.
     * @param string $baselinePath - Baseline path.
     *
     * @return array<string, true> - Stable identities found in the report.
     */
    private function baselineIdentities(string $projectRoot, string $baselinePath): array
    {
        $path = PathHelper::resolveAgainst($projectRoot, $baselinePath);
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Hook baseline not found: %s', $baselinePath));
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Unable to read hook baseline: %s', $baselinePath));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // A malformed --baseline file is an operational error; surface it in-band rather than crashing the contract.
            throw new RuntimeException(sprintf('Hook baseline is not valid JSON: %s', $baselinePath), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Hook baseline is not a JSON object: %s', $baselinePath));
        }

        $rows = $decoded['findings'] ?? [];
        if (!is_array($rows)) {
            throw new RuntimeException(sprintf('Hook baseline findings must be an array: %s', $baselinePath));
        }

        $identities = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $identity = $row['stableIdentity'] ?? null;
            if (is_string($identity) && $identity !== '') {
                $identities[$identity] = true;
            }
        }

        return $identities;
    }

    /**
     * Analyse a git base ref and build hook stable identities from its findings.
     *
     * @param string         $projectRoot          - Project root.
     * @param string         $baseRef              - Git base ref.
     * @param list<string>   $paths                - Paths to analyse in the base snapshot.
     * @param bool           $shouldIncludeIgnored - Whether ignored files should be included.
     * @param AnalysisConfig $config               - Effective config.
     *
     * @return array<string, true> - Base finding identities.
     */
    private function baseRefIdentities(
        string $projectRoot,
        string $baseRef,
        array $paths,
        bool $shouldIncludeIgnored,
        AnalysisConfig $config,
    ): array {
        $snapshot     = new GitArchiveSnapshot();
        $snapshotRoot = null;

        try {
            $snapshotRoot  = $snapshot->create($projectRoot, $baseRef, $paths);
            $snapshotPaths = (new AnalysisFindingSupport())->existingSnapshotPaths($snapshotRoot, $paths);
            if ($snapshotPaths === []) {
                return [];
            }

            $registry = RuleRegistry::defaults();
            $analysis = $this->analyse(
                projectRoot:          $snapshotRoot,
                paths:                $snapshotPaths,
                shouldIncludeIgnored: $shouldIncludeIgnored,
                config:               $config,
                registry:             $registry,
            );

            $identities = [];
            foreach (HookFindingIdentity::forFindings($analysis['findings']) as $identity) {
                $identities[$identity] = true;
            }

            return $identities;
        } finally {
            if ($snapshotRoot !== null) {
                $snapshot->remove($snapshotRoot);
            }
        }
    }

    /**
     * Resolve the git ref used for new-only filtering from diff/since flags.
     *
     * @param InputInterface $input - Console input.
     *
     * @return string|null - Base ref, or null when no comparable diff base exists.
     */
    private function baseRef(InputInterface $input): ?string
    {
        $since = $this->stringOption($input, 'since');
        if ($since !== null) {
            return $since;
        }

        $diffMode = $this->diffMode($input);
        if ($diffMode === null || $diffMode === '-') {
            return null;
        }

        return in_array($diffMode, ['working-tree', 'staged', 'unstaged'], true) ? 'HEAD' : $diffMode;
    }

    /**
     * Build the hook report.
     *
     * @param list<Finding>     $findings        - Findings to render.
     * @param int               $suppressedCount - Hook suppression count.
     * @param list<IgnoredPath>  $ignoredPathRows - Ignored path records.
     * @param array<int, string> $identities      - Disambiguated hook identity keyed by spl_object_id($finding).
     * @param bool               $isConfigSchemaOk - Whether config loaded cleanly.
     * @param string|null        $configError     - Config or operational error message.
     *
     * @return array<string, mixed> - Hook report.
     */
    private function report(
        array $findings,
        int $suppressedCount,
        array $ignoredPathRows,
        array $identities,
        bool $isConfigSchemaOk,
        ?string $configError,
    ): array {
        $presenter = new HookFindingPresenter();
        $rows      = [];
        foreach ($findings as $finding) {
            $stableIdentity = $identities[spl_object_id($finding)]
                ?? HookFindingIdentity::forFinding($finding, HookFindingScope::classify($finding));
            $rows[]         = $presenter->toArray($finding, $stableIdentity);
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'analyzer' => [
                'name' => 'gruff-php',
                'version' => GruffApplication::VERSION,
            ],
            'findings' => $presenter->sort($rows),
            'suppressed' => [
                'count' => $suppressedCount,
            ],
            'ignored' => [
                'paths' => array_map(
                    static fn(IgnoredPath $ignoredPath): array => $ignoredPath->toArray(),
                    $ignoredPathRows,
                ),
            ],
            'config' => [
                'schemaOk' => $isConfigSchemaOk,
                'error' => $configError,
            ],
        ];
    }

    /**
     * Build an empty hook report, usually for an operational/config error before analysis.
     *
     * @param bool        $isConfigSchemaOk - Config status.
     * @param string|null $configError    - Error message.
     *
     * @return array<string, mixed> - Empty hook report.
     */
    private function emptyReport(bool $isConfigSchemaOk, ?string $configError): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'analyzer' => [
                'name' => 'gruff-php',
                'version' => GruffApplication::VERSION,
            ],
            'findings' => [],
            'suppressed' => [
                'count' => 0,
            ],
            'ignored' => [
                'paths' => [],
            ],
            'config' => [
                'schemaOk' => $isConfigSchemaOk,
                'error' => $configError,
            ],
        ];
    }

    /**
     * Write JSON to the output stream.
     *
     * @param OutputInterface      $output  - Console output.
     * @param array<string, mixed> $payload - Payload to encode.
     *
     * @return void
     * @throws JsonException When encoding fails.
     */
    private function writeJson(OutputInterface $output, array $payload): void
    {
        $output->write(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Parse changed line ranges.
     *
     * @param string $ranges - Comma-separated 1-based line ranges.
     *
     * @return list<ChangedLineRange> - Parsed ranges.
     */
    private function parseChangedRanges(string $ranges): array
    {
        $parsed = [];

        foreach (explode(',', $ranges) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Accept a single 1-based line ("8") or an inclusive range ("3-8"); group 2 holds the optional end bound.
            if (!preg_match('/^(\d+)(?:-(\d+))?$/', $part, $matches)) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $startLine = (int)$matches[1];
            $endLine   = isset($matches[2]) ? (int)$matches[2] : $startLine;

            if ($startLine < 1 || $endLine < $startLine) {
                throw new DiffException(sprintf('Invalid --changed-ranges value "%s". Use ranges like "3-3,8-10".', $ranges));
            }

            $parsed[] = new ChangedLineRange($startLine, $endLine);
        }

        if ($parsed === []) {
            throw new DiffException('--changed-ranges requires at least one range like "3-3,8-10".');
        }

        return $parsed;
    }

    /**
     * Read positional and repeated file paths.
     *
     * @param InputInterface $input - Console input.
     *
     * @return list<string> - Requested paths.
     */
    private function paths(InputInterface $input): array
    {
        /** @var list<string> $paths The paths argument is declared variadic, so the console returns a list of strings. */
        $paths = $input->getArgument('paths');
        foreach ($this->stringListOption($input, 'file') as $filePath) {
            $paths[] = $filePath;
        }

        return $paths;
    }

    /**
     * Parse --diff while distinguishing absent from bare.
     *
     * @param InputInterface $input - Console input.
     *
     * @return string|null - Diff mode/base ref or null when absent.
     */
    private function diffMode(InputInterface $input): ?string
    {
        if (!$input->hasParameterOption('--diff', true)) {
            return null;
        }

        $diffOption = $input->getOption('diff');

        return is_string($diffOption) && $diffOption !== '' ? $diffOption : 'working-tree';
    }

    /**
     * Read a string option.
     *
     * @param InputInterface $input - Console input.
     * @param string         $name  - Option name.
     *
     * @return string|null - Non-empty string value.
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $rawOption = $input->getOption($name);

        return is_string($rawOption) && $rawOption !== '' ? $rawOption : null;
    }

    /**
     * Read a repeatable string option and comma-expand each occurrence.
     *
     * @param InputInterface $input - Console input.
     * @param string         $name  - Option name.
     *
     * @return list<string> - Parsed string values.
     */
    private function stringListOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);
        if (!is_array($values)) {
            return [];
        }

        $items = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        return array_values(array_unique($items));
    }
}
