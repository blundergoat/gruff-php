<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Analysis\SensitiveExclusionSummary;
use GruffPhp\Results\Baseline\BaselineException;
use GruffPhp\Results\Baseline\BaselineFilter;
use GruffPhp\Results\Baseline\BaselineStore;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Severity;
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
use GruffPhp\Output\Hook\HookRunAudit;
use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\DeclarationInventory;
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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backs `gruff-php hook`, the machine-readable feedback channel editors and coding agents use instead of human reports.
 *
 * Callers can scope JSON findings to ranges, git changes, or items absent from a baseline under the stable `gruff.hook.v2` contract.
 * A `--capabilities` probe lets an integration discover supported behavior before requesting analysis.
 */
final class HookCommand extends Command
{
    /**
     * Contract version advertised in every hook payload and capability probe.
     */
    private const CONTRACT_VERSION = 'gruff.hook.v2';

    /**
     * Baseline schema this hook accepts; anything else is refused rather than read under the wrong matching rules.
     */
    private const BASELINE_SCHEMA_VERSION = 'gruff.baseline.v3';

    /**
     * Severity order used by the exit gate; an unknown severity shares the advisory rank.
     */
    private const SEVERITY_RANK = ['advisory' => 0, 'warning' => 1, 'error' => 2];

    /**
     * Confidence order the exit gate compares against; an unrated finding ranks highest so it cannot slip under a gate.
     */
    private const CONFIDENCE_RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    /**
     * Defines the paths and supported options accepted by gruff-php hook.
     *
     * @return void - Registers arguments and options with Symfony Console.
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
            ->addOption('exclude-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip the named rule id. Can be repeated or comma-separated.')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Lowest severity that exits 1. The hook default of none keeps findings advisory.', default: 'none')
            ->addOption('min-confidence', null, InputOption::VALUE_REQUIRED, 'Lowest confidence that reaches the exit gate: low, medium or high.')
            ->addOption('fail-on-new', null, InputOption::VALUE_NONE, 'Exit 1 when any published finding is new against the applied baseline.')
            ->addOption('fail-on-diagnostics', null, InputOption::VALUE_NONE, 'Exit 1 when the run reports any diagnostic, however minor.');
    }

    /**
     * Handles capability probes and analysis requests, publishes JSON, and applies the requested exit gate.
     *
     * @param InputInterface  $input  - Parsed flags and paths for this hook invocation.
     * @param OutputInterface $output - Destination for the one JSON report, success or error alike.
     *
     * @return int - 0 for probes or an unreached gate; 1 for a reached gate; Command::INVALID for rejected input or caught errors.
     * @throws JsonException - When the report payload cannot be encoded to JSON.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // A --capabilities probe only needs the feature list, so answer it and skip the scan.
        if ((bool)$input->getOption('capabilities')) {
            $this->writeJson($output, $this->capabilities());

            return Command::SUCCESS;
        }

        $format = $input->getOption('format');
        // Hook mode only emits JSON; another format such as --format=text receives a JSON error.
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
        // The contract only implements symbol-level scoping, so another --changed-scope value is refused up front.
        if ($changedScope !== 'symbol') {
            $this->writeJson($output, $this->emptyReport(false, 'hook supports only --changed-scope symbol.'));

            return Command::INVALID;
        }

        $registry = RuleRegistry::defaults();

        try {
            $config = $this->config($input, $projectRoot, $registry);
        } catch (ConfigException $exception) {
            // Malformed YAML or a retired secret-preview key, even with an empty list, produces an in-band configuration error.
            $this->writeJson($output, $this->emptyReport(false, $exception->getMessage()));

            return Command::INVALID;
        }

        $gate = $this->exitGate($input);
        // A gate the user mistyped is worse than a command that refused, because the run would report a verdict nobody asked for.
        if (is_string($gate)) {
            $this->writeJson($output, $this->emptyReport(true, $gate));

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
            $inventory        = DeclarationInventory::forFindings($projectRoot, $filterResult->findings);
            $baselineResult   = $this->applyBaseline($input, $projectRoot, $filterResult->findings, $inventory);

            $payload = $this->report(
                findings:         $baselineResult['findings'],
                suppressedCount:  $filterResult->suppressedCount,
                ignoredPathRows:  $analysis['sources']->discovery->ignoredPathDetails,
                diagnostics:      $analysis['sources']->diagnostics,
                runAudit:         new HookRunAudit(
                                      mode:              $this->runMode($input),
                                      scope:             $this->runScope($input),
                                      paths:             $paths,
                                      analysedFiles:     count($analysis['sources']->discovery->files),
                                      isBaselineApplied: $baselineResult['path'] !== null,
                                      baselinePath:      $baselineResult['path'],
                                  ),
                exclusions:       $analysis['suppressions'],
                baselineStatuses: $baselineResult['statuses'],
                inventory:        $inventory,
            );
            $this->writeJson($output, $payload);

            return $this->exitCode($payload, $gate);
        } catch (BaselineException $exception) {
            // An unreadable or incompatible baseline cannot be applied safely. Report the reason in both the JSON payload
            // and stderr so agents and shell users receive it.
            $this->writeJson($output, $this->emptyReport(true, $exception->getMessage(), 'baseline'));
            $this->writeError($output, $exception->getMessage());

            return Command::INVALID;
        } catch (DiffException $exception) {
            // A scope the run could not read carries one family type on every surface, so an agent reading the hook
            // and a reader of the analyse envelope see the same name for the same failure.
            $this->writeJson($output, $this->emptyReport(true, $exception->getMessage(), RunDiagnostic::CHANGED_REGION_TYPE));

            return Command::INVALID;
        } catch (RuntimeException $exception) {
            // Operational failures such as an unreadable hook baseline are reported in-band, even after the main scan finishes.
            $this->writeJson($output, $this->emptyReport(true, $exception->getMessage()));

            return Command::INVALID;
        }
    }

    /**
     * Validates exit thresholds and reads the switches for new findings and diagnostics.
     *
     * @param InputInterface $input - Flags carrying `--fail-on`, `--min-confidence`, `--fail-on-new` and `--fail-on-diagnostics`.
     *
     * @return array{failOn: string, minConfidence: string, failOnNew: bool, failOnDiagnostics: bool}|string - Gate or validation error.
     */
    private function exitGate(InputInterface $input): array|string
    {
        $failOn = strtolower(trim($this->stringOption($input, 'fail-on') ?? 'none'));

        // `none` disables the severity gate; new-finding and diagnostic gates remain independent.
        if ($failOn !== 'none' && Severity::tryFrom($failOn) === null) {
            return sprintf('Unknown severity "%s" for --fail-on: want advisory, warning, error or none.', $failOn);
        }

        $minConfidence = strtolower(trim($this->stringOption($input, 'min-confidence') ?? Confidence::Low->value));

        if (Confidence::tryFrom($minConfidence) === null) {
            return sprintf('Unknown confidence "%s" for --min-confidence: want low, medium or high.', $minConfidence);
        }

        return [
            'failOn' => $failOn,
            'minConfidence' => $minConfidence,
            'failOnNew' => (bool)$input->getOption('fail-on-new'),
            'failOnDiagnostics' => (bool)$input->getOption('fail-on-diagnostics'),
        ];
    }

    /**
     * Decides what the hook tells the calling agent, reading the payload it just published rather than the raw scan.
     * What blocks an edit is exactly what the agent was shown: a finding the changed-region filter or the baseline
     * removed is not in the payload and does not block.
     *
     * @param array<string, mixed>                                                                  $payload - The published hook report.
     * @param array{failOn: string, minConfidence: string, failOnNew: bool, failOnDiagnostics: bool} $gate    - The resolved exit gate.
     *
     * @return int - 0 when nothing reached the gate, 1 when something did.
     */
    private function exitCode(array $payload, array $gate): int
    {
        // Diagnostic gating includes nonfatal warnings; it is independent of finding severity and confidence.
        if ($gate['failOnDiagnostics'] && is_array($payload['diagnostics'] ?? null) && $payload['diagnostics'] !== []) {
            return 1;
        }

        /** @var list<array<string, mixed>> $rows - Findings already included in the report payload. */
        $rows = is_array($payload['findings'] ?? null) ? $payload['findings'] : [];

        foreach ($rows as $publishedFinding) {
            if ($this->isGateReached($publishedFinding, $gate)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Tests whether a published finding triggers the caller's exit gate.
     * `--fail-on-new` accepts status `new` regardless of either threshold; otherwise both thresholds must be met.
     *
     * @param array<string, mixed> $publishedFinding - One finding from the payload.
     * @param array{failOn: string, minConfidence: string, failOnNew: bool, failOnDiagnostics: bool} $gate - Validated exit settings.
     *
     * @return bool - True when this finding alone is enough to exit 1.
     */
    private function isGateReached(array $publishedFinding, array $gate): bool
    {
        if ($gate['failOnNew'] && ($publishedFinding['baselineStatus'] ?? null) === 'new') {
            return true;
        }

        $severityFloor = self::SEVERITY_RANK[$gate['failOn']] ?? null;

        // A `none` threshold names no floor at all, so no severity reaches it and only --fail-on-new can block.
        if ($severityFloor === null) {
            return false;
        }

        $severity   = self::SEVERITY_RANK[is_string($publishedFinding['severity'] ?? null) ? $publishedFinding['severity'] : ''] ?? 0;
        $confidence = self::CONFIDENCE_RANK[is_string($publishedFinding['confidence'] ?? null) ? $publishedFinding['confidence'] : ''] ?? 2;

        return $severity >= $severityFloor && $confidence >= (self::CONFIDENCE_RANK[$gate['minConfidence']] ?? 0);
    }

    /**
     * Names which region selector chose the work, so a consumer can tell a targeted run from a whole-tree one.
     *
     * @param InputInterface $input - Flags carrying the region selectors.
     *
     * @return string - One of `changed-ranges`, `diff`, `since` or `full`.
     */
    private function runMode(InputInterface $input): string
    {
        // Explicit ranges are the narrowest selector and win when more than one is given.
        if ($this->providedStringOption($input, 'changed-ranges') !== null) {
            return 'changed-ranges';
        }

        if ($input->getOption('diff') !== null && $input->getOption('diff') !== false) {
            return 'diff';
        }

        return $this->stringOption($input, 'since') !== null ? 'since' : 'full';
    }

    /**
     * Names how wide each changed line was taken to be, which decides what a clean result actually proves.
     *
     * @param InputInterface $input - Flags carrying the region selectors.
     *
     * @return string - `symbol` when a region selector narrowed the run, `file` when nothing did.
     */
    private function runScope(InputInterface $input): string
    {
        // A region selector widens each changed line to its enclosing declaration; without one, the whole file was judged.
        if ($this->runMode($input) === 'full') {
            return 'file';
        }

        return 'symbol';
    }

    /**
     * Applies a --baseline file, classifying every finding it covers and leaving the rest to be reported.
     * The hook reads the same ratified baseline v3 file written by analyse --generate-baseline, so one review carries
     * across both surfaces. A file in any other schema is refused rather than read under the wrong matching rules.
     *
     * @param InputInterface $input       - Flags carrying --baseline.
     * @param string         $projectRoot - Project root the baseline path resolves against.
     * @param list<Finding>  $findings    - Findings the changed-region filter left; the returned set is what the baseline gates.
     * @param DeclarationInventory $inventory - Scanned variable occurrences, so the hook ranks a variable's ordinal as `analyse` does.
     *
     * @return array{findings: list<Finding>, statuses: array<int, string>, path: string|null} - Gated findings, object-ID statuses, and applied path.
     * @throws BaselineException - When the baseline is missing, unreadable, invalid, or from another port.
     */
    private function applyBaseline(InputInterface $input, string $projectRoot, array $findings, DeclarationInventory $inventory): array
    {
        $baselinePath = $this->stringOption($input, 'baseline');

        // With no baseline named, every finding stands and none carries a status a consumer could misread as reviewed.
        if ($baselinePath === null) {
            return ['findings' => $findings, 'statuses' => [], 'path' => null];
        }

        $baseline = (new BaselineStore($projectRoot))->read($baselinePath);
        $applied  = (new BaselineFilter())->apply($baseline, $findings, false, $inventory);

        return ['findings' => $applied['findings'], 'statuses' => $applied['statuses'], 'path' => $baselinePath];
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
                'baseline' => true,
                'baselineV3' => true,
                'changedRanges' => true,
                'confidenceGate' => true,
                'deepScanBudget' => true,
                'diagnostics' => true,
                'diff' => true,
                'ignoreReport' => true,
                'metadata' => true,
                'newOnly' => true,
                'scopeField' => true,
                'stableIdentity' => true,
            ],
            'flags' => [
                'baseline' => '--baseline',
                'changedRanges' => '--changed-ranges',
                'deepScanBudget' => '--deep-scan-budget',
                'diff' => '--diff',
                'failOnDiagnostics' => '--fail-on-diagnostics',
                'minConfidence' => '--min-confidence',
            ],
            'flagOrder' => 'any',
        ];
    }

    /**
     * Resolves the hook config from YAML or defaults, then applies deep-scan budget and rule-selection overrides.
     *
     * @param InputInterface $input       - Flags carrying config selection, deep-scan budget, and rule filters.
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
            : (new ConfigLoader($projectRoot, ConfigLoader::packageRoot(), shouldResolveFromLaunchDir: true))->load($configPath, $registry);

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

        // Resolve retired rule IDs before validation so filters apply to their current replacements.
        $includeRules    = array_map(RuleRegistry::canonicalRuleId(...), $this->stringListOption($input, 'include-rule'));
        $excludeRules    = array_map(RuleRegistry::canonicalRuleId(...), $this->stringListOption($input, 'exclude-rule'));
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
     * @param list<string>  $excludeRules - Rule ids excluded from the focused or existing selection.
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
     * @return array{sources: AnalysisSourceSet, findings: list<Finding>, suppressions: list<SensitiveExclusionSummary>} - Sources, kept findings,
     *     and audit rows for configured exclusions, test-path skips, inline markers, and duplicates.
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
            presentation:           new PresentationSelectors(),
            minConfidence:          null,
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

        return [
            'sources' => $analysisRun['sources'],
            'findings' => $analysisRun['findings'],
            // Preserve each suppression row so hook consumers can explain which exclusions removed findings.
            'suppressions' => $analysisRun['suppressions'],
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
        $changedRanges = $this->providedStringOption($input, 'changed-ranges');

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
     * Chooses explicit paths, existing changed paths for an active diff, or default discovery when neither is supplied.
     *
     * @param string          $projectRoot - Project root the diff's file paths resolve against.
     * @param list<string>    $paths       - Requested paths; empty uses an active diff, or default discovery without one.
     * @param DiffResult|null $diff        - Changed-region data, or null when no changed-region source was given.
     *
     * @return list<string>|null - Scan paths; [] requests default discovery, and null skips scanning when no changed paths exist.
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

        // An empty or deletion-only diff skips discovery; [] would request the default scan instead.
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
     * @return array<string, true>|null - Prior identities; null disables new-only filtering when no comparison source applies.
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
     * Reads non-empty stable identities from the baseline file passed through --baseline for new-only filtering.
     *
     * @param string $projectRoot  - Project root used to resolve the --baseline path.
     * @param string $baselinePath - Baseline file path supplied through --baseline.
     *
     * @return array<string, true> - Every stable identity found in the baseline; empty when it listed no findings.
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
            // If snapshot creation completed, remove its temporary directory even when analysis throws.
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
     * Builds the `gruff.hook.v2` report from findings, suppression counts, ignored paths, diagnostics, and run metadata.
     *
     * @param list<Finding> $findings - Findings remaining after hook filtering and baseline application.
     * @param int $suppressedCount - Count removed by HookFindingFilter before baseline application.
     * @param list<IgnoredPath> $ignoredPathRows - Ignored path records surfaced so the caller sees what was skipped.
     * @param list<RunDiagnostic> $diagnostics - Analysis diagnostics, including nonfatal bounded-scan notes.
     * @param HookRunAudit $runAudit - What this run did: mode, scope, operands, file count and baseline.
     * @param list<SensitiveExclusionSummary> $exclusions - Configured, test-path, inline, and duplicate suppression audits.
     * @param array<int, string> $baselineStatuses - Statuses keyed by spl_object_id($finding); empty without a baseline.
     * @param DeclarationInventory $inventory - Scanned variable occurrences, so each reported ordinal matches the baseline's.
     *
     * @return array<string, mixed> - The full JSON-ready hook report.
     */
    private function report(
        array $findings,
        int $suppressedCount,
        array $ignoredPathRows,
        array $diagnostics,
        HookRunAudit $runAudit,
        array $exclusions,
        array $baselineStatuses,
        DeclarationInventory $inventory,
    ): array {
        $presenter = new HookFindingPresenter();
        $ordinals  = BaselineIdentity::assignOrdinals($findings, $inventory);
        $presented = [];
        foreach ($findings as $finding) {
            $ordinal     = $ordinals[spl_object_id($finding)] ?? 0;
            $presented[] = $presenter->toArray(
                $finding,
                $this->ratifiedIdentity($finding, $ordinal),
                $ordinal,
                $baselineStatuses[spl_object_id($finding)] ?? null,
            );
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'analyzer' => [
                'name' => 'gruff-php',
                'version' => GruffApplication::VERSION,
            ],
            'run' => $runAudit->toArray(self::BASELINE_SCHEMA_VERSION),
            'findings' => $presenter->sort($presented),
            'suppressed' => [
                'count' => $suppressedCount,
            ],
            'suppressions' => array_map(
                static fn(SensitiveExclusionSummary $exclusion): array => [
                    'rule'       => $exclusion->rule,
                    'path'       => $exclusion->path,
                    'symbol'     => $exclusion->symbol,
                    'reason'     => $exclusion->reason,
                    'suppressed' => $exclusion->suppressed,
                    // Configured rows omit source; test-path, inline, and duplicate suppression rows include it.
                    ...($exclusion->source === null ? [] : ['source' => $exclusion->source]),
                ],
                $exclusions,
            ),
            'ignored' => [
                'paths' => array_map(
                    static fn(IgnoredPath $ignoredPath): array => $ignoredPath->toArray(),
                    $ignoredPathRows,
                ),
            ],
            'diagnostics' => $this->diagnosticRows($diagnostics),
            'config' => [
                'schemaOk' => true,
                'error' => null,
            ],
        ];
    }

    /**
     * Names one finding with the ratified family identity, or with nothing where the family refuses to name it.
     * A sensitive-data finding is deliberately unidentifiable: a durable identity is exactly what would let a stored
     * review hide a secret. A finding this port cannot name is reported without one rather than with a guessed one.
     *
     * @param Finding $finding - The finding being named.
     * @param int     $ordinal - 1-based rank among same-named symbols in a file; 0 for a symbol-less finding.
     *
     * @return string|null - The sixteen-character family identity, or null for a sensitive or unnameable finding.
     */
    private function ratifiedIdentity(Finding $finding, int $ordinal): ?string
    {
        // The family gives a sensitive finding no identity at all, so there is nothing to compute here.
        if (!BaselineIdentity::isEligible($finding)) {
            return null;
        }

        try {
            return BaselineIdentity::identityOf($finding, $ordinal);
        } catch (BaselineException) {
            // Invalid subjects, including missing ordinals or embedded ordinal separators, cannot be given a safe identity.
            return null;
        }
    }

    /**
     * Adds an explicit severity to each diagnostic row for the hook report.
     * `isFatal` selects `fatal`; every other diagnostic is `warning`, preserving input order.
     *
     * @param list<RunDiagnostic> $diagnostics - Diagnostics this run produced, in the order the engine emitted them.
     *
     * @return list<array<string, mixed>> - One JSON row per diagnostic, each carrying an explicit severity.
     */
    private function diagnosticRows(array $diagnostics): array
    {
        return array_map(
            static function (RunDiagnostic $diagnostic): array {
                $diagnosticRow = $diagnostic->toArray();
                $diagnosticRow['severity'] = $diagnostic->isFatal ? 'fatal' : 'warning';

                return $diagnosticRow;
            },
            $diagnostics,
        );
    }

    /**
     * Builds a findings-free `gruff.hook.v2` payload for a failed hook invocation.
     *
     * @param bool        $isConfigSchemaOk - Whether configuration was accepted before the reported failure.
     * @param string|null $configError      - The error to relay; null omits both the diagnostic and config error.
     * @param string      $diagnosticType   - Stable machine-readable kind for the fatal diagnostic, such as config or baseline.
     *
     * @return array<string, mixed> - Findings-free report with a fatal diagnostic when an error is supplied.
     */
    private function emptyReport(bool $isConfigSchemaOk, ?string $configError, string $diagnosticType = 'usage-error'): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'analyzer' => [
                'name' => 'gruff-php',
                'version' => GruffApplication::VERSION,
            ],
            'run' => (new HookRunAudit(mode: 'full', scope: 'file', paths: [], analysedFiles: 0))->toArray(self::BASELINE_SCHEMA_VERSION),
            'findings' => [],
            'suppressed' => [
                'count' => 0,
            ],
            'suppressions' => [],
            'ignored' => [
                'paths' => [],
            ],
            // An error makes this report unusable, so its diagnostic is fatal even if analysis already ran.
            'diagnostics' => $configError === null ? [] : [[
                'type'     => $diagnosticType,
                'severity' => 'fatal',
                'message'  => $configError,
                'file'     => null,
                'line'     => null,
                'path'     => null,
            ]],
            'config' => [
                'schemaOk' => $isConfigSchemaOk,
                // A configuration a consumer cannot fix is a dead end, so the error carries its remediation.
                'error' => $configError === null ? null : [
                    'message'     => $configError,
                    'remediation' => 'Fix the reported problem in .gruff-php.yaml, or pass --no-config to run without project configuration.',
                ],
            ],
        ];
    }

    /**
     * Writes an error line to stderr when available, otherwise to the supplied output.
     *
     * @param OutputInterface $output  - The command's output; its error stream is used when one exists.
     * @param string          $message - The reason the run could not proceed.
     *
     * @return void - Writes the reason as a line to the selected stream.
     */
    private function writeError(OutputInterface $output, string $message): void
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errorOutput->writeln($message);
    }

    /**
     * Encodes and writes the one JSON document a hook run emits - the single channel for both success and error reports.
     *
     * @param OutputInterface      $output  - Destination stream for the encoded report.
     * @param array<string, mixed> $payload - The hook report (or capabilities) to serialise.
     *
     * @return void - Writes the encoded document followed by a newline.
     * @throws JsonException - When the payload cannot be encoded to JSON.
     */
    private function writeJson(OutputInterface $output, array $payload): void
    {
        // Replace invalid UTF-8 bytes with U+FFFD so source encoding alone cannot prevent JSON output.
        $output->write(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Parses 1-based line ranges, ignoring empty comma-separated parts and rejecting invalid non-empty parts.
     *
     * @param string $ranges - Comma-separated single lines or inclusive `start-end` spans; surrounding whitespace is ignored.
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
     * Combines positional paths with repeated `--file` values, preserving their order.
     *
     * @param InputInterface $input - Console input carrying the paths argument and `--file` options.
     *
     * @return list<string> - Files or directories, or [] when none were supplied; positional paths come first and duplicates may remain.
     */
    private function paths(InputInterface $input): array
    {
        /** @var list<string> $paths - The paths argument is declared variadic, so the console returns a list of strings. */
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
     * Reads a string option, mapping a non-string or empty string to null without trimming whitespace.
     *
     * @param InputInterface $input - Console input to read the option from.
     * @param string         $name  - Option name to look up.
     *
     * @return string|null - Original non-empty string, including whitespace; null for a non-string or empty value.
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $rawOption = $input->getOption($name);

        return is_string($rawOption) && $rawOption !== '' ? $rawOption : null;
    }

    /**
     * Preserves empty string options so explicit empty values can be validated instead of treated as absent.
     * For `--changed-ranges=`, collapsing the empty string to null would disable filtering and allow a default scan.
     *
     * @param InputInterface $input - Console input to read the option from.
     * @param string         $name  - Option name to look up.
     *
     * @return string|null - Option string unchanged, including ''; null for non-string values such as an absent option.
     */
    private function providedStringOption(InputInterface $input, string $name): ?string
    {
        $rawOption = $input->getOption($name);

        return is_string($rawOption) ? $rawOption : null;
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
