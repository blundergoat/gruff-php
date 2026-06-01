<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Cache\AnalysisFingerprint;
use GruffPhp\Cache\ResultCache;
use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Console\Application;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\RuleRunnerObserver;
use Closure;
use GruffPhp\Source\SourceDiscoveryResult;

/**
 * Drives source discovery and rule analysis for AnalyseCommand.
 *
 * Two pipelines live here:
 *  - runStreaming(): parse → analyse → release one file at a time so peak
 *    memory stays close to a single unit's worth at scale.
 *  - runLegacy(): the load-all-then-analyse flow used by diff/review runs
 *    that need both the changed-only set and a base snapshot in memory at
 *    the same time.
 */
final class AnalysisPipeline
{
    /**
     * Closure that returns the project-rule context unit list for the legacy pipeline.
     *
     * @var Closure(string, AnalyseCommandOptions, AnalysisConfig, RuleRegistry, ?DiffResult, AnalysisSourceSet): list<AnalysisUnit>
     */
    private readonly Closure $projectContextUnitsResolver;

    /**
     * @param RuleRegistry                                                                                                             $registry Rule registry used to execute enabled rules.
     * @param Closure(string, AnalyseCommandOptions, AnalysisConfig, RuleRegistry, ?DiffResult, AnalysisSourceSet): list<AnalysisUnit> $closure
     *                                                                                                                                           Resolves full project context units for legacy review analysis.
     */
    public function __construct(
        private readonly RuleRegistry $registry,
        Closure $closure,
    ) {
        $this->projectContextUnitsResolver = $closure;
    }

    /**
     * Run the right pipeline for this CLI invocation.
     *
     * @param string                  $projectRoot        Project root used for discovery and parsing.
     * @param AnalyseCommandOptions   $options            Effective CLI analysis options.
     * @param AnalysisConfig          $config             Effective rule and path configuration.
     * @param RuleContext             $ruleContext        Rule execution context.
     * @param DiffResult|null         $reviewDiff         Review diff metadata when branch review is active.
     * @param list<string>|null       $analysisPaths      Paths to analyse, or null when setup failed.
     * @param int                     $discoverStart      Monotonic start timestamp for discovery timing.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing observer.
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * }
     */
    public function runAnalysis(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleContext $ruleContext,
        ?DiffResult $reviewDiff,
        ?array $analysisPaths,
        int $discoverStart,
        ?RuleRunnerObserver $ruleRunnerObserver,
    ): array {
        if ($analysisPaths === null) {
            // Setup failed upstream, so emit an empty result that still carries the discovery timing measured so far.
            return [
                'sources' => new AnalysisSourceSet(new SourceDiscoveryResult([], [], []), [], []),
                'findings' => [],
                'discoverParseNs' => hrtime(true) - $discoverStart,
                'analyseNs' => 0,
                'projectContextUnits' => [],
            ];
        }

        if ($this->canStream($options, $reviewDiff, $ruleContext)) {
            // Streaming is safe here, so take the low-peak-memory path that releases each unit after analysis.
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

        // Diff or review run needs every unit and the base snapshot resident, so fall back to the legacy load-all path.
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
     * Decide whether streaming parse → analyse → release is safe for this run.
     *
     * @param AnalyseCommandOptions $options     CLI options; changed-region and diff modes force the legacy path.
     * @param DiffResult|null       $reviewDiff  Review diff metadata; an active review keeps the base snapshot.
     * @param RuleContext           $ruleContext Context whose enabled rules must all tolerate per-unit release.
     * @return bool True when each unit can be released immediately after analysis.
     */
    private function canStream(
        AnalyseCommandOptions $options,
        ?DiffResult $reviewDiff,
        RuleContext $ruleContext,
    ): bool {
        // Stream only when no review/diff retains the base snapshot and every enabled rule tolerates per-unit release.
        return ($reviewDiff === null || !$reviewDiff->active)
            && !$options->hasChangedRegionMode()
            && $options->diffVs === null
            && $this->registry->supportsStreaming($ruleContext);
    }

    /**
     * Streaming pipeline: each unit's AST is freed as soon as its per-unit
     * and accumulator passes complete, keeping peak memory near one file.
     *
     * @param string                  $projectRoot        Root for discovery, parsing, and the per-project result cache.
     * @param AnalyseCommandOptions   $options            CLI options; gate the cache and whether ignored files load.
     * @param AnalysisConfig          $config             Ignore patterns plus the inputs to the cache fingerprint.
     * @param RuleContext             $ruleContext        Context the per-unit and accumulator rule passes run against.
     * @param list<string>            $analysisPaths      Project-relative paths to discover under; never null here.
     * @param int                     $discoverStart      Monotonic hrtime start for the discover-and-parse span.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing sink, or null to skip timing.
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * }
     */
    private function runStreaming(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleContext $ruleContext,
        array $analysisPaths,
        int $discoverStart,
        ?RuleRunnerObserver $ruleRunnerObserver,
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

        // The per-file result cache is only byte-identical-correct when no rule
        // needs cross-file state: project rules (accumulators included) observe
        // every unit during analysis, so reusing a cached file's findings without
        // re-running them would corrupt the project-rule output.
        $cacheable   = !$options->noCache && !$this->registry->hasEnabledProjectRules($config);
        $cache       = $cacheable ? ResultCache::forProject($projectRoot) : null;
        $fingerprint = $cacheable ? AnalysisFingerprint::forRun($this->registry, $config, Application::VERSION) : null;

        $this->registry->beginStreaming($ruleContext);
        $findings     = [];
        $parsedCount  = 0;
        $analyseStart = hrtime(true);

        foreach ($discoveryResult->files as $file) {
            $cacheKey = null;
            if ($cache instanceof ResultCache && $fingerprint instanceof AnalysisFingerprint && is_readable($file->absolutePath)) {
                $contents = file_get_contents($file->absolutePath);
                if (is_string($contents)) {
                    $cacheKey       = $fingerprint->forFile($file->displayPath, $contents);
                    $cachedFindings = $cache->get($cacheKey);
                    if ($cachedFindings !== null) {
                        array_push($findings, ...$cachedFindings);
                        $parsedCount++;
                        continue;
                    }
                }
            }

            $unit = $phpFileParser->parse($file);
            if (!$unit->hasParseErrors()) {
                $parsedCount++;
            }
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
            if ($cache instanceof ResultCache && $cacheKey !== null && !$unit->hasParseErrors()) {
                $cache->put($cacheKey, $unitFindings);
            }
            NodeIndex::evictUnit($unit);
            $unit->release();
            unset($unit);
        }

        array_push($findings, ...$this->registry->endStreaming($ruleContext, $ruleRunnerObserver));
        $findings  = $this->registry->finalizeFindings($findings);
        $analyseNs = hrtime(true) - $analyseStart;

        // Streaming never retains parsed units, so report no project-context units alongside the finalised findings.
        return [
            'sources' => new AnalysisSourceSet($discoveryResult, [], $sourceDiagnostics, $parsedCount),
            'findings' => $findings,
            'discoverParseNs' => $discoverParseNs,
            'analyseNs' => $analyseNs,
            'projectContextUnits' => [],
        ];
    }

    /**
     * Legacy load-all-then-analyse pipeline. Still used for diff/review flows
     * that need both the current changed-only set and a base snapshot.
     *
     * @param string                  $projectRoot        Root for discovery and parsing of the changed-only set.
     * @param AnalyseCommandOptions   $options            CLI options forwarded to discovery and the context resolver.
     * @param AnalysisConfig          $config             Effective config supplying ignore patterns and rule selection.
     * @param RuleContext             $ruleContext        Context the whole-project analysis pass runs against.
     * @param DiffResult|null         $reviewDiff         Review diff metadata that drives which base units load.
     * @param list<string>            $analysisPaths      Project-relative paths to load and parse; never null here.
     * @param int                     $discoverStart      Monotonic hrtime start for the discover-and-parse span.
     * @param RuleRunnerObserver|null $ruleRunnerObserver Optional per-rule timing sink, or null to skip timing.
     * @return array{
     *     sources: AnalysisSourceSet,
     *     findings: list<Finding>,
     *     discoverParseNs: int,
     *     analyseNs: int,
     *     projectContextUnits: list<AnalysisUnit>
     * }
     */
    private function runLegacy(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleContext $ruleContext,
        ?DiffResult $reviewDiff,
        array $analysisPaths,
        int $discoverStart,
        ?RuleRunnerObserver $ruleRunnerObserver,
    ): array {
        $sources = (new AnalysisSourceLoader())->load(
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
        $analyseNs = hrtime(true) - $analyseStart;

        // Surface the resolved project-context units too so review flows can diff them against the base snapshot.
        return [
            'sources' => $sources,
            'findings' => $findings,
            'discoverParseNs' => $discoverParseNs,
            'analyseNs' => $analyseNs,
            'projectContextUnits' => $projectContextUnits,
        ];
    }
}
