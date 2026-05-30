<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Config\AnalysisConfig;
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
            return [
                'sources' => new AnalysisSourceSet(new SourceDiscoveryResult([], [], []), [], []),
                'findings' => [],
                'discoverParseNs' => hrtime(true) - $discoverStart,
                'analyseNs' => 0,
                'projectContextUnits' => [],
            ];
        }

        if ($this->canStream($options, $reviewDiff, $ruleContext)) {
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
     * @return bool True when each unit can be released immediately after analysis.
     */
    private function canStream(
        AnalyseCommandOptions $options,
        ?DiffResult $reviewDiff,
        RuleContext $ruleContext,
    ): bool {
        return ($reviewDiff === null || !$reviewDiff->active)
            && !$options->hasChangedRegionMode()
            && $options->diffVs === null
            && $this->registry->supportsStreaming($ruleContext);
    }

    /**
     * Streaming pipeline: each unit's AST is freed as soon as its per-unit
     * and accumulator passes complete, keeping peak memory near one file.
     *
     * @param list<string> $analysisPaths
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

        $this->registry->beginStreaming($ruleContext);
        $findings     = [];
        $parsedCount  = 0;
        $analyseStart = hrtime(true);

        foreach ($discoveryResult->files as $file) {
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

            array_push($findings, ...$this->registry->analyseUnit($unit, $ruleContext, $ruleRunnerObserver));
            NodeIndex::evictUnit($unit);
            $unit->release();
            unset($unit);
        }

        array_push($findings, ...$this->registry->endStreaming($ruleContext, $ruleRunnerObserver));
        $findings  = $this->registry->finalizeFindings($findings);
        $analyseNs = hrtime(true) - $analyseStart;

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
     * @param list<string> $analysisPaths
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

        return [
            'sources' => $sources,
            'findings' => $findings,
            'discoverParseNs' => $discoverParseNs,
            'analyseNs' => $analyseNs,
            'projectContextUnits' => $projectContextUnits,
        ];
    }
}
