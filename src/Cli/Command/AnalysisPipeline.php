<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\InlineSuppressionFilter;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Analysis\SensitiveExclusionFilter;
use GruffPhp\Engine\Analysis\SensitiveExclusionSummary;
use GruffPhp\Engine\Cache\AnalysisFingerprint;
use GruffPhp\Engine\Cache\ResultCache;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Cli\Application;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\ParseDiagnostic;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Size\SubstantiveLineCounter;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Shared\RuleRunnerObserver;
use Closure;
use GruffPhp\Engine\Source\SourceDiscoveryResult;

/**
 * Discovers and analyses sources for `gruff-php analyse`, returning findings and run metadata.
 * Releases parsed units as it goes when the enabled rules and requested scope support streaming.
 *
 * Uses batch analysis for reviews, changed regions, narrow project-rule scopes, or rules that need all units.
 */
final class AnalysisPipeline
{
    /**
     * Resolver the legacy pipeline calls to gather whole-project rule context, so diff and review
     * runs can weigh the changed files against the rest of the codebase.
     *
     * @var Closure(string, AnalyseCommandOptions, AnalysisConfig, RuleRegistry, ?DiffResult, AnalysisSourceSet): list<AnalysisUnit>
     */
    private readonly Closure $projectContextUnitsResolver;

    /**
     * Stores the rule registry and the project-context resolver used by the legacy pipeline.
     *
     * @param RuleRegistry $registry - Registry used to execute enabled rules.
     * @param Closure(string, AnalyseCommandOptions, AnalysisConfig, RuleRegistry, ?DiffResult, AnalysisSourceSet): list<AnalysisUnit> $closure
     *     - Resolves project-context units for legacy analysis.
     */
    public function __construct(
        private readonly RuleRegistry $registry,
        Closure                       $closure,
    ) {
        $this->projectContextUnitsResolver = $closure;
    }

    /**
     * Entry point AnalyseCommand calls once per gruff-php analyse: it routes the request to the
     * streaming or legacy pipeline and hands back the findings and timings the run then reports.
     *
     * @param string                  $projectRoot        - Project root used for discovery and parsing.
     * @param AnalyseCommandOptions   $options            - Effective CLI analysis options.
     * @param AnalysisConfig          $config             - Effective rule and path configuration.
     * @param RuleContext             $ruleContext        - Context passed to each enabled rule.
     * @param DiffResult|null $reviewDiff - Inactive for plain scans; null after a failed review diff lookup.
     * @param list<string>|null $analysisPaths - Paths to analyse; null skips discovery and rule execution.
     * @param int                     $discoverStart      - Monotonic start timestamp for discovery timing.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Per-rule timing observer; null when this run isn't collecting timings.
     *
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>,
     *     suppressions: list<SensitiveExclusionSummary>,
     *     suppressedFindings: list<array{finding: Finding, justification: string}>
     * } - Sources, filtered findings, discovery/parse and analysis timings, context units, and suppression details.
     */
    public function runAnalysis(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig        $config,
        RuleContext           $ruleContext,
        ?DiffResult           $reviewDiff,
        ?array                $analysisPaths,
        int                   $discoverStart,
        ?RuleRunnerObserver   $ruleRunnerObserver,
    ): array {
        $analysisRun = $this->runPipeline(
            projectRoot:        $projectRoot,
            options:            $options,
            config:             $config,
            ruleContext:        $ruleContext,
            reviewDiff:         $reviewDiff,
            analysisPaths:      $analysisPaths,
            discoverStart:      $discoverStart,
            ruleRunnerObserver: $ruleRunnerObserver,
        );

        // Apply configured, test-path, and inline suppressions before scoring and reporting.

        $exclusionResult = (new SensitiveExclusionFilter())->apply(
            $analysisRun['findings'],
            $config->sensitiveExclusions(),
            InlineSuppressionFilter::forFiles($analysisRun['sources']->discovery->files),
        );

        return [
            'sources' => $analysisRun['sources'],
            'findings' => $exclusionResult->findings,
            'discoverParseNs' => $analysisRun['discoverParseNs'],
            'analyseNs' => $analysisRun['analyseNs'],
            'projectContextUnits' => $analysisRun['projectContextUnits'],
            'suppressions' => $exclusionResult->summaries,
            'suppressedFindings' => $exclusionResult->suppressedFindings,
        ];
    }

    /**
     * Routes one run to the streaming or legacy pipeline and returns its raw findings, before any
     * configured sensitive exclusion has been applied.
     *
     * @param string                  $projectRoot        - Project root used for discovery and parsing.
     * @param AnalyseCommandOptions   $options            - Effective CLI analysis options.
     * @param AnalysisConfig          $config             - Effective rule and path configuration.
     * @param RuleContext             $ruleContext        - Context passed to each enabled rule.
     * @param DiffResult|null $reviewDiff - Inactive for plain scans; null after a failed review diff lookup.
     * @param list<string>|null $analysisPaths - Paths to analyse; null skips discovery and rule execution.
     * @param int                     $discoverStart      - Monotonic start timestamp for discovery timing.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Per-rule timing observer; null when this run isn't collecting timings.
     *
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * } - Sources, raw findings, discovery/parse and analysis timings, and resolved project-context units.
     */
    private function runPipeline(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig        $config,
        RuleContext           $ruleContext,
        ?DiffResult           $reviewDiff,
        ?array                $analysisPaths,
        int                   $discoverStart,
        ?RuleRunnerObserver   $ruleRunnerObserver,
    ): array {
        // No files are in scope; retain elapsed discovery time so the empty run still has timing metadata.


        if ($analysisPaths === null) {
            return [
                'sources' => new AnalysisSourceSet(new SourceDiscoveryResult([], [], []), [], []),
                'findings' => [],
                'discoverParseNs' => hrtime(true) - $discoverStart,
                'analyseNs' => 0,
                'projectContextUnits' => [],
            ];
        }



        if ($this->canStream($projectRoot, $options, $reviewDiff, $ruleContext)) {
            return $this->runStreaming(
                projectRoot:        $projectRoot,
                options:            $options,
                config:             $config,
                ruleContext:        $ruleContext,
                analysisPaths:      $analysisPaths,
                discoverStart:      $discoverStart,
                ruleRunnerObserver: $ruleRunnerObserver,
            );
        }

        // Scope or rule requirements prevent streaming, so analyse the loaded units together.

        return $this->runLegacy(
            projectRoot:        $projectRoot,
            options:            $options,
            config:             $config,
            ruleContext:        $ruleContext,
            reviewDiff:         $reviewDiff,
            analysisPaths:      $analysisPaths,
            discoverStart:      $discoverStart,
            ruleRunnerObserver: $ruleRunnerObserver,
        );
    }

    /**
     * Reports whether this scope and its enabled rules support releasing units after analysis.
     *
     * @param string                $projectRoot - Project root requested paths resolve against.
     * @param AnalyseCommandOptions $options     - CLI options; changed-region and diff modes force the legacy path.
     * @param DiffResult|null $reviewDiff - Inactive for plain scans; null after a failed review diff lookup.
     * @param RuleContext           $ruleContext - Context whose enabled rules must all tolerate per-unit release.
     *
     * @return bool - True when review, scope, and enabled-rule constraints permit streaming.
     */
    private function canStream(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult           $reviewDiff,
        RuleContext           $ruleContext,
    ): bool {
        // Narrow path scopes need wider context when project rules are enabled, so those runs cannot stream.


        $requestedPaths              = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        $hasNarrowProjectRuleContext = $requestedPaths !== [] && $requestedPaths !== ['.']
            && $this->registry->hasEnabledProjectRules($ruleContext->config);


        return ($reviewDiff === null || !$reviewDiff->active)
               && !$options->hasChangedRegionMode()
               && !$hasNarrowProjectRuleContext
               && $options->changeScope->diffVs === null
               && $this->registry->supportsStreaming($ruleContext);
    }

    /**
     * Counts PHP inputs in the scoring denominator, excluding text-only inputs scanned by raw-text rules.
     *
     * @param SourceFile $file - File accepted without fatal parse diagnostics.
     *
     * @return int - 1 when the file enters the scoring denominator, 0 otherwise.
     */
    private static function evaluatedIncrement(SourceFile $file): int
    {
        return $file->isPhp() ? 1 : 0;
    }

    /**
     * Analyses and releases each parsed unit before loading the next, retaining findings and rule accumulator state.
     *
     * @param string                  $projectRoot        - Root for discovery, parsing, and the per-project result cache.
     * @param AnalyseCommandOptions   $options            - CLI options; gate the cache and whether ignored files load.
     * @param AnalysisConfig $config - Ignore patterns, deep-scan budget, and cache fingerprint inputs.
     * @param RuleContext             $ruleContext        - Context the per-unit and accumulator rule passes run against.
     * @param list<string>            $analysisPaths      - Project-relative paths to discover under; never null here.
     * @param int                     $discoverStart      - Monotonic hrtime start for the discover-and-parse span.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Per-rule timing sink; null when this run isn't collecting per-rule timings.
     *
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * } - Discovered sources, final findings, nanosecond timings, and empty projectContextUnits because units are released.
     */
    private function runStreaming(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig        $config,
        RuleContext           $ruleContext,
        array                 $analysisPaths,
        int                   $discoverStart,
        ?RuleRunnerObserver   $ruleRunnerObserver,
    ): array {
        $discovery = (new AnalysisSourceLoader())->discover(
            $projectRoot,
            $analysisPaths,
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        );
        $discoverParseNs   = hrtime(true) - $discoverStart;
        $sourceDiagnostics = $discovery['diagnostics'];
        $discoveryResult   = $discovery['discovery'];
        $phpFileParser     = new PhpFileParser();

        // Cached findings cannot replay the cross-file state collected by project rules, so those runs bypass the cache.
        // Runs larger than the entry cap bypass caching because the store cannot retain every file's result.





        $cacheable = !$options->noCache
            && !$this->registry->hasEnabledProjectRules($config)
            && ResultCache::canHoldRun(count($discoveryResult->files));
        $cache       = $cacheable ? ResultCache::forProject($projectRoot) : null;
        $fingerprint = $cacheable ? AnalysisFingerprint::forRun($this->registry, $config, Application::VERSION) : null;

        $this->registry->beginStreaming($ruleContext);
        $findings       = [];
        $parsedCount    = 0;
        $evaluatedCount = 0;
        $analyseStart   = hrtime(true);



        foreach ($discoveryResult->files as $file) {


            $cached = self::lookUpCachedFile($file, $cache, $fingerprint, $config);



            if ($cached['findings'] !== null) {
                array_push($findings, ...$cached['findings']);
                if ($cached['budgetDiagnostic'] !== null) {
                    $sourceDiagnostics[] = self::runDiagnosticFor($cached['budgetDiagnostic'], $file);
                }
                $parsedCount++;

                $evaluatedCount += self::evaluatedIncrement($file);
                continue;
            }

            $unit = $phpFileParser->parse($file, $config->deepScanBudget());
            // Nonfatal budget limits still count toward coverage even when deep parsing is skipped.

            if (!$unit->hasParseErrors()) {
                $parsedCount++;
                $evaluatedCount += self::evaluatedIncrement($file);
            }
            // Keep nonfatal budget notes as well as fatal parse errors visible in the run report.

            foreach ($unit->diagnostics as $diagnostic) {
                $sourceDiagnostics[] = self::runDiagnosticFor($diagnostic, $file);
            }

            $unitFindings = $this->registry->analyseUnit($unit, $ruleContext, $ruleRunnerObserver);
            array_push($findings, ...$unitFindings);
            // Cache units without fatal parse errors, including bounded scans whose text-level findings can be replayed.

            if ($cache instanceof ResultCache && $cached['key'] !== null && !$unit->hasParseErrors()) {
                $cache->put($cached['key'], $unitFindings);
            }
            NodeIndex::evictUnit($unit);
            SubstantiveLineCounter::evictUnit($unit);
            $unit->release();
            unset($unit);
        }

        // Trim once after all writes to avoid scanning the cache directory for every stored file.
        $cache?->finalizeRun();

        array_push($findings, ...$this->registry->endStreaming($ruleContext, $ruleRunnerObserver));
        $findings  = $this->registry->finalizeFindings($findings);
        $analyseNs = hrtime(true) - $analyseStart;


        return [
            'sources' => new AnalysisSourceSet($discoveryResult, [], $sourceDiagnostics, $parsedCount, $evaluatedCount),
            'findings' => $findings,
            'discoverParseNs' => $discoverParseNs,
            'analyseNs' => $analyseNs,
            'projectContextUnits' => [],
        ];
    }

    /**
     * Looks up cached findings and returns the key for a later cache write when one can be computed.
     * Disabled caching or unreadable files return no key; null findings mean a miss, while an empty list is a hit.
     * The budget check runs whenever a key is available, including on cache misses.
     *
     * @param SourceFile               $file        - Discovered file about to be analysed.
     * @param ResultCache|null         $cache       - Live store for this run; null when the run is uncacheable.
     * @param AnalysisFingerprint|null $fingerprint - Run identity the file key is derived from; null with no cache.
     * @param AnalysisConfig           $config      - Effective config supplying the deep-scan budget.
     *
     * @return array{key: string|null, findings: list<Finding>|null, budgetDiagnostic: ParseDiagnostic|null} - State for replay or a later write.
     */
    private static function lookUpCachedFile(
        SourceFile           $file,
        ?ResultCache         $cache,
        ?AnalysisFingerprint $fingerprint,
        AnalysisConfig       $config,
    ): array {
        $miss = ['key' => null, 'findings' => null, 'budgetDiagnostic' => null];

        if (!$cache instanceof ResultCache || !$fingerprint instanceof AnalysisFingerprint || !is_readable($file->absolutePath)) {
            return $miss;
        }

        $contents = file_get_contents($file->absolutePath);


        if (!is_string($contents)) {
            return $miss;
        }

        $cacheKey = $fingerprint->forFile($file->displayPath, $contents);

        return [
            'key' => $cacheKey,
            'findings' => $cache->get($cacheKey),
            'budgetDiagnostic' => PhpFileParser::deepScanBudgetDiagnostic($file, $contents, $config->deepScanBudget()),
        ];
    }

    /**
     * Lifts a parse-time diagnostic onto the run, so a bounded or broken file stays visible in the report.
     *
     * @param ParseDiagnostic $diagnostic - Diagnostic raised while parsing, or replayed from the cache.
     * @param SourceFile      $file       - File the diagnostic belongs to; supplies the reported path.
     *
     * @return RunDiagnostic - The same note carrying the file's display path.
     */
    private static function runDiagnosticFor(ParseDiagnostic $diagnostic, SourceFile $file): RunDiagnostic
    {
        return new RunDiagnostic(
            type:     $diagnostic->type,
            message:  $diagnostic->message,
            filePath: $file->displayPath,
            line:     $diagnostic->line,
            isFatal:  $diagnostic->isFatal,
        );
    }

    /**
     * Loads requested sources and any wider project context for runs that cannot stream.
     * Analyses them together, then limits project-rule findings to the requested files.
     *
     * @param string $projectRoot - Root for discovery and parsing of the requested paths.
     * @param AnalyseCommandOptions   $options            - CLI options forwarded to discovery and the context resolver.
     * @param AnalysisConfig          $config             - Effective config supplying ignore patterns and rule selection.
     * @param RuleContext             $ruleContext        - Context the whole-project analysis pass runs against.
     * @param DiffResult|null $reviewDiff - Diff metadata for the context resolver; null after a failed review lookup.
     * @param list<string>            $analysisPaths      - Project-relative paths to load and parse; never null here.
     * @param int                     $discoverStart      - Monotonic hrtime start for the discover-and-parse span.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Per-rule timing sink; null when this run isn't collecting per-rule timings.
     *
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * } - Sources, requested-file findings, discovery/parse and analysis timings, and project-context units.
     */
    private function runLegacy(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig        $config,
        RuleContext           $ruleContext,
        ?DiffResult           $reviewDiff,
        array                 $analysisPaths,
        int                   $discoverStart,
        ?RuleRunnerObserver   $ruleRunnerObserver,
    ): array {
        $sources = (new AnalysisSourceLoader())->load(
            $projectRoot,
            $analysisPaths,
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
            $config->deepScanBudget(),
        );
        $discoverParseNs     = hrtime(true) - $discoverStart;
        $projectContextUnits = ($this->projectContextUnitsResolver)(
            $projectRoot,
            $options,
            $config,
            $this->registry,
            $reviewDiff,
            $sources,
        );

        $analyseStart = hrtime(true);
        $findings     = $this->registry->analyse(
                                             $sources->analysisUnits,
                                             $ruleContext,
                                             $projectContextUnits,
                                             $ruleRunnerObserver,
            shouldReleaseUnitsAfterAnalysis: true,
        );
        $findings = (new AnalysisFindingSupport())->filterProjectRuleFindingsToFiles(
            $findings,
            $this->registry->enabledProjectRuleIds($config),
            $sources->displayPaths(),
        );
        $analyseNs = hrtime(true) - $analyseStart;


        return [
            'sources' => $sources,
            'findings' => $findings,
            'discoverParseNs' => $discoverParseNs,
            'analyseNs' => $analyseNs,
            'projectContextUnits' => $projectContextUnits,
        ];
    }
}
