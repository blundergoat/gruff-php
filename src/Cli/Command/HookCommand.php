<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Baseline\BaselineApplicationOptions;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleSelection;
use GruffPhp\Cli\Application as GruffApplication;
use GruffPhp\Results\Diff\ChangedLineRange;
use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Results\Diff\ChangeScopeOptions;
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
 * Backs `gruff-php hook`, the machine-readable feedback channel editors and coding agents use instead of human reports.
 *
 * Callers can scope JSON findings to ranges, git changes, or items absent from a baseline under the stable `gruff.hook.v1` contract.
 * A `--capabilities` probe lets an integration discover supported behavior before requesting analysis.
 */
final class HookCommand extends Command
{
    /**
     * Contract version advertised in every hook payload and capability probe.
     */
    private const CONTRACT_VERSION = 'gruff.hook.v1';

    /**
     * Registers the `hook` command's paths argument and every flag a caller types after `gruff-php hook`.
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
            ->addOption('deep-scan-budget', null, InputOption::VALUE_REQUIRED, 'Bound structural analysis as <lines>:<bytes>, or disable it with off.')
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
     * Runs a `gruff-php hook` call end to end: answer a probe, reject bad flags, analyse once, then filter to the changed region.
     *
     * @param InputInterface  $input  - Parsed flags and paths for this hook invocation.
     * @param OutputInterface $output - Destination for the one JSON report, success or error alike.
     *
     * @return int - 0 when analysis ran (even with findings); non-zero only for a bad flag or operational error.
     * @throws JsonException When the report payload cannot be encoded to JSON.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // A `--capabilities` probe just wants the feature list, so answer it and skip the scan entirely.
        if ((bool)$input->getOption('capabilities')) {
            $this->writeJson($output, $this->capabilities());

            return Command::SUCCESS;
        }

        $format = $input->getOption('format');
        // Hook mode only emits JSON; anything else (say `--format=text`) gets a JSON error, not a broken render.
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
        // The contract only implements symbol-level scoping today, so a different `--changed-scope` is refused up front.
        if ($changedScope !== 'symbol') {
            $this->writeJson($output, $this->emptyReport(false, 'hook supports only --changed-scope symbol.'));

            return Command::INVALID;
        }

        $registry = RuleRegistry::defaults();

        try {
            $config = $this->config($input, $projectRoot, $registry);
        } catch (ConfigException $exception) {
            // Malformed YAML or an old non-empty secret-preview list returns its correction in-band without a stack trace.
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
            $findings             = $analysis['findings'];
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
                    findings:         $filterResult->findings,
                    suppressedCount:  $filterResult->suppressedCount + $analysis['suppressedCount'],
                    ignoredPathRows:  $analysis['sources']->discovery->ignoredPathDetails,
                    diagnostics:      $analysis['sources']->diagnostics,
                    identities:       $filterResult->identities,
                    isConfigSchemaOk: true,
                    configError:      null,
                ),
            );

            return Command::SUCCESS;
        } catch (DiffException | RuntimeException $exception) {
            // An invalid git ref or unreadable baseline can prevent scoped analysis; integrations receive the operational error in-band.
            $this->writeJson($output, $this->emptyReport(true, $exception->getMessage()));

            return Command::INVALID;
        }
    }

    /**
     * Answers a `--capabilities` probe so a caller can check the contract version and features before a real run.
     *
     * @return array<string, mixed> - JSON-ready capabilities payload for the `--capabilities` probe.
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
                'deepScanBudget' => true,
            ],
            'flags' => [
                'changedRanges' => '--changed-ranges',
                'diff' => '--diff',
                'baseline' => '--baseline',
                'deepScanBudget' => '--deep-scan-budget',
            ],
            'flagOrder' => 'any',
        ];
    }

    /**
     * Resolves the config a hook run analyses under: load or skip the YAML, then apply `--include-rule`/`--exclude-rule`.
     *
     * @param InputInterface $input       - Flags carrying `--config`, `--no-config`, and the rule filters.
     * @param string         $projectRoot - Project root the loader resolves the default config file against.
     * @param RuleRegistry   $registry    - Rule set used to seed defaults and validate any named rule ids.
     *
     * @return AnalysisConfig - Effective config, already narrowed to any requested rule filters.
     */
    private function config(InputInterface $input, string $projectRoot, RuleRegistry $registry): AnalysisConfig
    {
        $configPath = $this->stringOption($input, 'config');
        // The caller both named a config and asked to skip config; obeying one would silently drop the other.
        if ((bool)$input->getOption('no-config') && $configPath !== null) {
            throw new ConfigException('--no-config cannot be combined with --config.');
        }

        $config = (bool)$input->getOption('no-config')
            ? AnalysisConfig::fromRegistry($registry)
            : (new ConfigLoader($projectRoot, ConfigLoader::packageRoot()))->load($configPath, $registry);

        $deepScanBudgetOverride = AnalyseCommandOptions::parseDeepScanBudgetOverride(
            $this->stringOption($input, 'deep-scan-budget'),
        );
        if (is_string($deepScanBudgetOverride)) {
            throw new ConfigException($deepScanBudgetOverride);
        }
        if ($deepScanBudgetOverride !== null) {
            $config = $config->withDeepScanBudget(
                $deepScanBudgetOverride['enabled'],
                $deepScanBudgetOverride['maxLines'],
                $deepScanBudgetOverride['maxBytes'],
                'cli',
            );
        }

        $includeRules    = $this->stringListOption($input, 'include-rule');
        $excludeRules    = $this->stringListOption($input, 'exclude-rule');
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
     * Resolves hook rule filters so the integration runs exactly what the user requested on top of project config.
     * `--include-rule` narrows to named rules, while `--exclude-rule` keeps the configured selection and removes only the named rules.
     *
     * @param RuleSelection $existing     - Selection already resolved from the project config.
     * @param list<string>  $includeRules - Hook --include-rule ids; when non-empty they focus the run.
     * @param list<string>  $excludeRules - Hook --exclude-rule ids dropped on top of the existing selection.
     *
     * @return RuleSelection - Focused selection for --include-rule, or the configured selection plus excludes.
     */
    private function refinedSelection(RuleSelection $existing, array $includeRules, array $excludeRules): RuleSelection
    {
        // A non-empty `--include-rule` list means "only these rules", so replace the selection rather than widen it.
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
     * Rejects any `--include-rule`/`--exclude-rule` id the registry doesn't know, so a typo fails loudly not silently.
     *
     * @param RuleRegistry $registry     - Registry whose ids define valid hook rule filters.
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
     * Runs one full analysis pass over the given paths - the shared engine step every hook run and base snapshot feeds from.
     *
     * @param string            $projectRoot          - Project root the scan is anchored to.
     * @param list<string>|null $paths                - Paths to analyse; null scans nothing and yields an empty result.
     * @param bool              $shouldIncludeIgnored - Whether ignored files are pulled in via filesystem traversal.
     * @param AnalysisConfig    $config               - Effective config driving rule selection and ignores.
     * @param RuleRegistry      $registry             - Rule set executed against the discovered sources.
     *
     * @return array{sources: AnalysisSourceSet, findings: list<Finding>, suppressedCount: int} - Discovered sources, every finding left after the
     *                            configured sensitive exclusions ran, and how many those exclusions removed.
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
            changeScope:          new ChangeScopeOptions(
                                      diffMode:      null,
                                      since:         null,
                                      changedRanges: null,
                                      changedScope:  'symbol',
                                      diffVs:        null,
                                      isChangedOnly: false,
                                  ),
            historyFile:          null,
            noBaseline:           true,
            baseline:             new BaselineApplicationOptions(
                                      baselinePath:         null,
                                      isBaselineExplicit:   false,
                                      generateBaselinePath: null,
                                  ),
            reportEditorLink:       'none',
            isReportInteractive:    false,
            pathsRelativeTo:        null,
            minSeverity:            null,
            includePillars:         [],
            excludePillars:         [],
            includeRules:           [],
            excludeRules:           [],
            deepScanBudgetOverride: null,
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

        $suppressedCount = 0;
        // Sensitive exclusions already removed their findings inside the pipeline; total them so the hook
        // payload still reports a suppression count rather than a silently shorter list.
        foreach ($analysisRun['suppressions'] as $summary) {
            $suppressedCount += $summary->suppressed;
        }

        return [
            'sources' => $analysisRun['sources'],
            'findings' => $analysisRun['findings'],
            'suppressedCount' => $suppressedCount,
        ];
    }

    /**
     * Works out which lines count as "changed" from whichever source the caller gave - ranges, `--since`, `--diff`, or stdin.
     *
     * @param InputInterface $input       - Flags naming the changed-region source.
     * @param string         $projectRoot - Project root that git refs and file paths resolve against.
     * @param list<string>   $paths       - Requested paths, the files `--changed-ranges` are applied to.
     *
     * @return DiffResult|null - Active changed region to filter against; null when no source was given, so nothing is narrowed.
     */
    private function changedRegion(InputInterface $input, string $projectRoot, array $paths): ?DiffResult
    {
        $changedRanges = $this->stringOption($input, 'changed-ranges');
        // Explicit `--changed-ranges` is the most direct source: the caller hands us the exact lines it touched.
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

        // A `--diff=-` value means the unified patch arrives on stdin rather than from a git ref.
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
     * Decides what the scan opens: the caller's paths, or - when they gave none but passed a diff - just the changed files.
     *
     * @param string          $projectRoot - Project root the diff's file paths resolve against.
     * @param list<string>    $paths       - Paths the caller requested; empty means "derive them from the diff".
     * @param DiffResult|null $diff        - Changed-region data, or null when no changed-region source was given.
     *
     * @return list<string>|null - Paths to scan; null when an active diff names only files that no longer exist, so nothing is scanned.
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

        // Narrow to changed files that still exist; a diff that only renamed or deleted files leaves nothing to scan.
        return $changedFiles === [] ? null : $changedFiles;
    }

    /**
     * Gathers findings that already existed before this change, so a run can surface only new ones (from `--baseline` and/or a diff base).
     *
     * @param InputInterface $input                - Flags naming the `--baseline` file and/or the diff base.
     * @param string         $projectRoot          - Project root the base snapshot is taken from.
     * @param list<string>   $paths                - Paths to analyse in the base so their prior findings are known.
     * @param bool           $shouldIncludeIgnored - Whether ignored files should be included in the base scan.
     * @param AnalysisConfig $config               - Effective config, applied identically to the base for a fair comparison.
     *
     * @return array<string, true>|null - Prior identities; null means no baseline or diff base was given, so every finding is new
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
            // Union both sources so a finding known to either the baseline or the base ref reads as pre-existing.
            $identities = $identities === null ? $baseIdentities : $identities + $baseIdentities;
        }

        return $identities;
    }

    /**
     * Reads the stable identities from a prior `gruff.hook.v1` report passed as `--baseline`, feeding new-only filtering.
     *
     * @param string $projectRoot  - Project root the `--baseline` path resolves against.
     * @param string $baselinePath - Caller-supplied path to the earlier hook JSON report.
     *
     * @return array<string, true> - Every stable identity found in the report; empty when it listed no findings.
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
     * Snapshots a git base ref, scans it with the same rules, and records its finding identities - the "before" of new-only filtering.
     *
     * @param string         $projectRoot          - Project root the base snapshot is exported from.
     * @param string         $baseRef              - Git base ref to compare the working tree against.
     * @param list<string>   $paths                - Paths to analyse inside the base snapshot.
     * @param bool           $shouldIncludeIgnored - Whether ignored files should be included in the base scan.
     * @param AnalysisConfig $config               - Effective config, matched to the main scan for a fair diff.
     *
     * @return array<string, true> - Identities of every finding already present at the base; empty when it had none.
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
            // The base ref lacked these files (they're new in the working tree), so there is nothing prior to record.
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
            // Always delete the temporary snapshot, even if the scan threw, so hook runs never leak checkout directories.
            if ($snapshotRoot !== null) {
                $snapshot->remove($snapshotRoot);
            }
        }
    }

    /**
     * Collapses the diff/since flags into the single git ref new-only filtering treats as "before", or none.
     *
     * @param InputInterface $input - Flags carrying `--since` and `--diff`.
     *
     * @return string|null - Git base ref to snapshot; null when no diff base applies, so no git base is scanned.
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
     * Assembles the successful `gruff.hook.v1` payload the caller reads: surviving findings, suppression count, ignored paths, config status.
     *
     * @param list<Finding>       $findings         - Findings that passed changed-region and new-only filtering.
     * @param int                 $suppressedCount  - How many findings were filtered out, shown so the caller knows some were hidden.
     * @param list<IgnoredPath>   $ignoredPathRows  - Ignored path records surfaced so the caller sees what was skipped.
     * @param list<RunDiagnostic> $diagnostics      - Analysis diagnostics, including nonfatal bounded-scan notes.
     * @param array<int, string>  $identities       - Pre-disambiguated identity per finding, keyed by spl_object_id($finding).
     * @param bool                $isConfigSchemaOk - Whether the config loaded cleanly; false flags a degraded run.
     * @param string|null         $configError      - Error message to relay, or null when the config was fine.
     *
     * @return array<string, mixed> - The full JSON-ready hook report.
     */
    private function report(
        array $findings,
        int $suppressedCount,
        array $ignoredPathRows,
        array $diagnostics,
        array $identities,
        bool $isConfigSchemaOk,
        ?string $configError,
    ): array {
        $presenter = new HookFindingPresenter();
        $rows      = [];
        foreach ($findings as $finding) {
            $stableIdentity = $identities[spl_object_id($finding)]
                ?? HookFindingIdentity::forFinding($finding, HookFindingScope::classify($finding));
            $rows[] = $presenter->toArray($finding, $stableIdentity);
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
            'diagnostics' => array_map(
                static fn(RunDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $diagnostics,
            ),
            'config' => [
                'schemaOk' => $isConfigSchemaOk,
                'error' => $configError,
            ],
        ];
    }

    /**
     * Builds the findings-free `gruff.hook.v1` payload for a run that can't proceed, so the caller still gets valid JSON.
     *
     * @param bool        $isConfigSchemaOk - False when format, scope, or config stopped analysis; true once user config parsed successfully.
     * @param string|null $configError      - The error to relay, or null when the empty report isn't about an error.
     *
     * @return array<string, mixed> - Empty but schema-valid hook report.
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
            'diagnostics' => [],
            'config' => [
                'schemaOk' => $isConfigSchemaOk,
                'error' => $configError,
            ],
        ];
    }

    /**
     * Encodes and writes the one JSON document a hook run emits - the single channel for both success and error reports.
     *
     * @param OutputInterface      $output  - Destination stream for the encoded report.
     * @param array<string, mixed> $payload - The hook report (or capabilities) to serialise.
     *
     * @return void
     * @throws JsonException When the payload cannot be encoded to JSON.
     */
    private function writeJson(OutputInterface $output, array $payload): void
    {
        // Invalid source bytes become U+FFFD so the coding agent driving the hook always gets parseable JSON.
        $output->write(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Turns a `--changed-ranges` string like `3-3,8-10` into structured ranges, rejecting anything malformed.
     *
     * @param string $ranges - Comma-separated 1-based line ranges, single lines or `start-end` spans.
     *
     * @return list<ChangedLineRange> - Parsed ranges, always at least one; a value with no usable range throws instead.
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
     * Merges the positional `paths` argument and any repeated `--file` options into one list of files to analyse.
     *
     * @param InputInterface $input - Console input carrying the paths argument and `--file` options.
     *
     * @return list<string> - Every requested path; empty when the caller named none.
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
     * Reads the optional-value `--diff` flag, telling apart absent, bare, and mode/ref - which decides if a git diff drives the run.
     *
     * @param InputInterface $input - Console input carrying the optional `--diff` value.
     *
     * @return string|null - The diff mode or ref (`working-tree` for a bare flag); null when `--diff` was absent, so no diff applies.
     */
    private function diffMode(InputInterface $input): ?string
    {
        // Tell "flag never given" apart from "given with no value"; only a truly absent `--diff` returns null.
        if (!$input->hasParameterOption('--diff', true)) {
            return null;
        }

        $diffOption = $input->getOption('diff');

        return is_string($diffOption) && $diffOption !== '' ? $diffOption : 'working-tree';
    }

    /**
     * Reads a single-value option, collapsing an absent or blank flag to null so callers treat "unset" and "empty" alike.
     *
     * @param InputInterface $input - Console input to read the option from.
     * @param string         $name  - Option name to look up.
     *
     * @return string|null - The option's non-empty string value; null when it was absent or blank.
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $rawOption = $input->getOption($name);

        return is_string($rawOption) && $rawOption !== '' ? $rawOption : null;
    }

    /**
     * Reads a repeatable option and comma-splits each occurrence, so `--include-rule=a,b` and repeated flags fold into one de-duped list.
     *
     * @param InputInterface $input - Console input to read the repeated option from.
     * @param string         $name  - Option name to look up.
     *
     * @return list<string> - Every distinct non-empty value; empty when the option was absent.
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

            // Split this occurrence on commas so a single `a,b,c` flag expands into separate values.
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
