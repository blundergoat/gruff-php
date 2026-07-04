<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Cache\AnalysisFingerprint;
use GruffPhp\Engine\Cache\ResultCache;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Cli\Application;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Shared\RuleRunnerObserver;
use Closure;
use GruffPhp\Engine\Source\SourceDiscoveryResult;

/**
 * Runs the discover-parse-analyse work behind `gruff-php analyse`, turning the paths the
 * user asked to scan into the findings the command then reports.
 *
 * Two pipelines live here and the run auto-selects between them, so the user never has to
 * choose:
 *  - `runStreaming()` parses, analyses, then releases one file at a time, keeping peak memory
 *    near a single file so large repositories scan without running out of memory.
 *  - `runLegacy()` loads everything up front - the mode `--diff-vs` reviews and changed-region
 *    runs need, because they weigh the changed set against a full base snapshot at once.
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
     * Holds the rule registry every run executes and the resolver that gathers whole-project
     * context for the legacy diff and review path.
     *
     * @param RuleRegistry                                                                                                             $registry - Registry used to execute enabled rules.
     * @param Closure(string, AnalyseCommandOptions, AnalysisConfig, RuleRegistry, ?DiffResult, AnalysisSourceSet): list<AnalysisUnit> $closure - Resolves full project context units for legacy review analysis.
     */
    public function __construct(
        private readonly RuleRegistry $registry,
        Closure                       $closure,
    ) {
        $this->projectContextUnitsResolver = $closure;
    }

    /**
     * Entry point `AnalyseCommand` calls once per `gruff-php analyse`: it routes the request to the
     * streaming or legacy pipeline and hands back the findings and timings the run then reports.
     *
     * @param string                  $projectRoot - Project root used for discovery and parsing.
     * @param AnalyseCommandOptions   $options - Effective CLI analysis options.
     * @param AnalysisConfig          $config - Effective rule and path configuration.
     * @param RuleContext             $ruleContext - Rule execution context.
     * @param DiffResult|null         $reviewDiff - Branch-review diff metadata; a plain scan passes a non-null inactive diff, and this is null only when a `--diff-vs` review was requested but its Git lookup failed.
     * @param list<string>|null       $analysisPaths - Paths to analyse; null when a changed-only or diff run resolved to no files in scope, which returns an empty result.
     * @param int                     $discoverStart - Monotonic start timestamp for discovery timing.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Per-rule timing observer; null when this run isn't collecting timings.
     *
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * } - analysis result for the run: discovered sources, finalised findings, discover/parse and analyse
     *     timings in nanoseconds, and the resolved project-context units (empty under the streaming path)
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
        // A changed-only or diff run resolved to no files in scope (nothing changed to review), so there are
        // no paths to scan; return an empty result that still carries the discovery timing measured so far
        // rather than crashing.
        if ($analysisPaths === null) {
            return [
                'sources'             => new AnalysisSourceSet(new SourceDiscoveryResult([], [], []), [], []),
                'findings'            => [],
                'discoverParseNs'     => hrtime(true) - $discoverStart,
                'analyseNs'           => 0,
                'projectContextUnits' => [],
            ];
        }

        // Nothing about this run needs a second copy of the tree in memory, so take the low-peak-memory
        // streaming path that frees each file right after it is analysed.
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

        // A `--diff-vs` review or a changed-region run needs every unit and the base snapshot resident at
        // once, so fall back to the legacy load-all path.
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
     * Decides, with no input from the user, whether this run can take the memory-light streaming path or
     * must fall back to loading every file at once.
     *
     * @param string                $projectRoot - Project root requested paths resolve against.
     * @param AnalyseCommandOptions $options - CLI options; changed-region and diff modes force the legacy path.
     * @param DiffResult|null       $reviewDiff - Branch-review diff metadata; a plain scan passes a non-null inactive diff (null only when a `--diff-vs` review's Git lookup failed), and an active review keeps the base snapshot so streaming is refused.
     * @param RuleContext           $ruleContext - Context whose enabled rules must all tolerate per-unit release.
     *
     * @return bool - true when every unit can be released immediately after analysis (streaming is safe), false when a review/diff or changed-region
     *              mode forces the legacy load-all path
     */
    private function canStream(
        string                $projectRoot,
        AnalyseCommandOptions $options,
        ?DiffResult           $reviewDiff,
        RuleContext           $ruleContext,
    ): bool {
        // An explicit project-root request ('.', './', or the root path itself) still covers the whole
        // tree, so it can stream like a bare invocation; only a genuinely narrower path needs the legacy
        // load-all flow that pulls whole-tree project context separately from the requested files.
        $requestedPaths              = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        $hasNarrowProjectRuleContext = $requestedPaths !== [] && $requestedPaths !== ['.']
            && $this->registry->hasEnabledProjectRules($ruleContext->config);

        // Stream only when nothing needs the whole tree at once: no active review/diff, no changed-region mode, no narrow project-rule context, and every enabled rule tolerates per-unit release.
        return ($reviewDiff === null || !$reviewDiff->active)
               && !$options->hasChangedRegionMode()
               && !$hasNarrowProjectRuleContext
               && $options->diffVs === null
               && $this->registry->supportsStreaming($ruleContext);
    }

    /**
     * Streaming pipeline: parse a file, analyse it, then free its AST before the next one, so a large
     * repository scans with peak memory near a single file instead of the whole tree at once.
     *
     * @param string                  $projectRoot - Root for discovery, parsing, and the per-project result cache.
     * @param AnalyseCommandOptions   $options - CLI options; gate the cache and whether ignored files load.
     * @param AnalysisConfig          $config - Ignore patterns plus the inputs to the cache fingerprint.
     * @param RuleContext             $ruleContext - Context the per-unit and accumulator rule passes run against.
     * @param list<string>            $analysisPaths - Project-relative paths to discover under; never null here.
     * @param int                     $discoverStart - Monotonic hrtime start for the discover-and-parse span.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Per-rule timing sink; null when this run isn't collecting per-rule timings.
     *
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * } - streaming-path result: discovered sources, finalised findings, discover/parse and analyse timings
     *     in nanoseconds, and an always-empty projectContextUnits since streaming retains no parsed units
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
        $discovery         = (new AnalysisSourceLoader())->discover(
            $projectRoot,
            $analysisPaths,
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        );
        $discoverParseNs   = hrtime(true) - $discoverStart;
        $sourceDiagnostics = $discovery['diagnostics'];
        $discoveryResult   = $discovery['discovery'];
        $phpFileParser     = new PhpFileParser();

        // The per-file result cache is only byte-identical-correct when no rule
        // needs cross-file state: project rules (accumulators included) observe
        // every unit during analysis, so reusing a cached file's findings without
        // re-running them would corrupt the project-rule output. A run whose
        // discovered working set exceeds the cache's entry cap is also ineligible:
        // its entries would be evicted before any warm run could reuse them, so
        // over-cap repos silently run uncached instead of thrashing the store.
        $cacheable   = !$options->noCache
            && !$this->registry->hasEnabledProjectRules($config)
            && ResultCache::canHoldRun(count($discoveryResult->files));
        $cache       = $cacheable ? ResultCache::forProject($projectRoot) : null;
        $fingerprint = $cacheable ? AnalysisFingerprint::forRun($this->registry, $config, Application::VERSION) : null;

        $this->registry->beginStreaming($ruleContext);
        $findings     = [];
        $parsedCount  = 0;
        $analyseStart = hrtime(true);

        // Walk the discovered files one at a time; this loop is where each file the user asked to scan is
        // parsed, checked, and then dropped so memory never holds more than the file in hand.
        foreach ($discoveryResult->files as $file) {
            $cacheKey = null;
            // With the cache live and the file readable, try to reuse a previous run's findings for it instead
            // of parsing again - this is what makes a warm re-scan of unchanged code feel instant.
            if ($cache instanceof ResultCache && $fingerprint instanceof AnalysisFingerprint && is_readable($file->absolutePath)) {
                $contents = file_get_contents($file->absolutePath);
                // Only a successful read gives us bytes to fingerprint; a file that could not be read falls
                // through to a normal parse below rather than a cache lookup.
                if (is_string($contents)) {
                    $cacheKey       = $fingerprint->forFile($file->displayPath, $contents);
                    $cachedFindings = $cache->get($cacheKey);
                    // A stored entry means this exact file content was scanned before, so replay its findings,
                    // count it as parsed, and skip straight to the next file without re-running any rule.
                    if ($cachedFindings !== null) {
                        array_push($findings, ...$cachedFindings);
                        $parsedCount++;
                        continue;
                    }
                }
            }

            $unit = $phpFileParser->parse($file);
            // Count the file toward the parsed tally only when it actually compiled; a file with parse errors
            // still has its problems reported but is not counted as successfully scanned.
            if (!$unit->hasParseErrors()) {
                $parsedCount++;
            }
            // Turn each parser complaint into a run diagnostic so a syntactically broken file surfaces to the
            // user as a reported parse error instead of silently vanishing from the results.
            foreach ($unit->diagnostics as $diagnostic) {
                $sourceDiagnostics[] = new RunDiagnostic(
                    type:     'parse-error',
                    message:  $diagnostic->message,
                    filePath: $file->displayPath,
                    line:     $diagnostic->line,
                );
            }

            $unitFindings = $this->registry->analyseUnit($unit, $ruleContext, $ruleRunnerObserver);
            array_push($findings, ...$unitFindings);
            // Store the freshly computed findings only for a clean parse, so the next run over unchanged code
            // can reuse them; a broken file's partial results are never cached.
            if ($cache instanceof ResultCache && $cacheKey !== null && !$unit->hasParseErrors()) {
                $cache->put($cacheKey, $unitFindings);
            }
            NodeIndex::evictUnit($unit);
            $unit->release();
            unset($unit);
        }

        // Trim the store to its cap exactly once per run; evicting per put globbed the cache directory on every write.
        $cache?->finalizeRun();

        array_push($findings, ...$this->registry->endStreaming($ruleContext, $ruleRunnerObserver));
        $findings  = $this->registry->finalizeFindings($findings);
        $analyseNs = hrtime(true) - $analyseStart;

        // Streaming never retains parsed units, so report no project-context units alongside the finalised findings.
        return [
            'sources'             => new AnalysisSourceSet($discoveryResult, [], $sourceDiagnostics, $parsedCount),
            'findings'            => $findings,
            'discoverParseNs'     => $discoverParseNs,
            'analyseNs'           => $analyseNs,
            'projectContextUnits' => [],
        ];
    }

    /**
     * Load-everything-then-analyse pipeline, taken when the run can't stream - a `--diff-vs` review or a
     * changed-region scan - because it needs the changed-only set and the base snapshot resident together.
     *
     * @param string                  $projectRoot - Root for discovery and parsing of the changed-only set.
     * @param AnalyseCommandOptions   $options - CLI options forwarded to discovery and the context resolver.
     * @param AnalysisConfig          $config - Effective config supplying ignore patterns and rule selection.
     * @param RuleContext             $ruleContext - Context the whole-project analysis pass runs against.
     * @param DiffResult|null         $reviewDiff - Branch-review diff driving which base units load; a changed-region scan passes a non-null inactive diff, and this is null only when a `--diff-vs` review was requested but its Git lookup failed.
     * @param list<string>            $analysisPaths - Project-relative paths to load and parse; never null here.
     * @param int                     $discoverStart - Monotonic hrtime start for the discover-and-parse span.
     * @param RuleRunnerObserver|null $ruleRunnerObserver - Per-rule timing sink; null when this run isn't collecting per-rule timings.
     *
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * } - legacy-path result: discovered sources, finalised findings, discover/parse and analyse timings in
     *     nanoseconds, and the resolved project-context units that diff/review flows compare to the base snapshot
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
        $sources             = (new AnalysisSourceLoader())->load(
            $projectRoot,
            $analysisPaths,
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
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
        $findings     = (new AnalysisFindingSupport())->filterProjectRuleFindingsToFiles(
            $findings,
            $this->registry->enabledProjectRuleIds($config),
            $sources->displayPaths(),
        );
        $analyseNs    = hrtime(true) - $analyseStart;

        // Surface the resolved project-context units too so review flows can diff them against the base snapshot.
        return [
            'sources'             => $sources,
            'findings'            => $findings,
            'discoverParseNs'     => $discoverParseNs,
            'analyseNs'           => $analyseNs,
            'projectContextUnits' => $projectContextUnits,
        ];
    }
}
