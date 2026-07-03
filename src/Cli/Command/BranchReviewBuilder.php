<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Baseline\BaselineApplication;
use GruffPhp\Results\Baseline\BaselineException;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Results\Review\BranchReviewComparator;
use GruffPhp\Results\Review\BranchReviewResult;
use GruffPhp\Results\Review\GitArchiveSnapshot;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Results\Scoring\ScoreCalculator;
use RuntimeException;

/**
 * Builds the --diff-vs branch-review comparison and resolves project context units for AnalyseCommand.
 */
final readonly class BranchReviewBuilder
{
    /**
     * Compare current findings against a base ref snapshot and report introduced/removed/unchanged findings.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string              $projectRoot - Project root the comparison runs from.
     * @param AnalyseCommandOptions $options - Effective CLI analysis options.
     * @param AnalysisConfig      $config - Effective rule and path configuration.
     * @param RuleRegistry        $registry - Rule registry used to analyse the base snapshot.
     * @param list<Finding>       $currentFindings - Post-baseline findings for the current tree.
     * @param float               $currentScore - Composite score of the current findings.
     * @param DiffResult|null     $reviewDiff - Review diff metadata, or null when diff lookup failed.
     * @param list<RunDiagnostic> $diagnostics - Run diagnostics; review-mode errors are appended in place.
     *
     * @return BranchReviewResult|null - Review comparison, or null when disabled/unavailable.
     */
    public function build(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleRegistry $registry,
        array $currentFindings,
        float $currentScore,
        ?DiffResult $reviewDiff,
        array &$diagnostics,
    ): ?BranchReviewResult {
        // User view: choose the terminal output branch for this case.
        // User view: missing data becomes the expected terminal output state.
        if ($options->diffVs === null || $reviewDiff === null) {
            return null;
        }

        $gitArchiveSnapshot       = new GitArchiveSnapshot();
        $baseRoot                 = null;
        $shouldLoadProjectContext = $this->shouldLoadProjectContext(
            projectRoot: $projectRoot,
            options:     $options,
            registry:    $registry,
            config:      $config,
            reviewDiff:  $reviewDiff,
        );
        $baseSnapshotPaths        = $this->baseSnapshotPaths($projectRoot, $options, $reviewDiff, $shouldLoadProjectContext);
        $baseAnalysisPaths        = $this->baseAnalysisPaths($projectRoot, $options, $reviewDiff);

        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($options->isChangedOnly && !$shouldLoadProjectContext && $baseSnapshotPaths === []) {
            $baseScore = (new ScoreCalculator())->calculate([], null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config);

            // Changed-only with nothing to pull from the base ref: compare against an empty base
            // so every current finding reads as introduced.
            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          [],
                baseRef:       $options->diffVs,
                isChangedOnly: true,
                deltaScore:    $currentScore - $baseScore->composite->score,
            );
        }

        try {
            $baseRoot     = $gitArchiveSnapshot->create($projectRoot, $options->diffVs, $baseSnapshotPaths);
            $basePaths    = (new AnalysisFindingSupport())->existingSnapshotPaths($baseRoot, $baseAnalysisPaths);
            $baseFindings = [];

            // User view: choose the terminal output branch for this case.
            // User view: an empty value becomes a clear terminal output fallback.
            if ($basePaths !== []) {
                $baseSources = (new AnalysisSourceLoader())->load(
                    $baseRoot,
                    $basePaths,
                    $options->shouldIncludeIgnored,
                    $config->ignoredPathPatterns(),
                );
                $baseRegistry            = RuleRegistry::defaults();
                $baseProjectContextUnits = $shouldLoadProjectContext
                    ? $this->baseProjectContextUnits($baseRoot, $options, $config)
                    : $baseSources->analysisUnits;
                $baseFindings = $baseRegistry->analyse($baseSources->analysisUnits, new RuleContext($baseRoot, $config), $baseProjectContextUnits);
                $baseFindings = (new AnalysisFindingSupport())->filterProjectRuleFindingsToFiles(
                    $baseFindings,
                    $baseRegistry->enabledProjectRuleIds($config),
                    $baseSources->displayPaths(),
                );
                $baseFindings = (new AnalysisFindingSupport())->filterAllowedSecretPreviews($baseFindings, $config);
            }

            // User view: choose the terminal output branch for this case.
            if ($options->isChangedOnly) {
                $baseFindings = (new AnalysisFindingSupport())->filterFindingsToChangedFiles($baseFindings, $reviewDiff->changedFiles);
            }

            // User view: choose the terminal output branch for this case.
            // User view: missing data becomes the expected terminal output state.
            if ($options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null) {
                try {
                    $baseFindings = (new BaselineApplication())->filterExisting($projectRoot, $options->baseline->baselinePath, $baseFindings);
                } catch (BaselineException $exception) {
                    $diagnostics[] = new RunDiagnostic(
                        type:    'baseline-error',
                        message: $exception->getMessage(),
                        path:    $options->baseline->baselinePath,
                    );
                }
            }

            $baseFindings = (new AnalysisFindingSupport())->normalizeFindingPaths($baseFindings, $options->pathsRelativeTo);
            $baseScore    = (new ScoreCalculator())->calculate($baseFindings, null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config);

            // Diff the current findings against the base ref's own findings so the result reports
            // what this branch introduced or removed.
            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          $baseFindings,
                baseRef:       $options->diffVs,
                isChangedOnly: $options->isChangedOnly,
                deltaScore:    $currentScore - $baseScore->composite->score,
            );
        } catch (DiffException | RuntimeException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'review-mode-error',
                message: $exception->getMessage(),
            );

            // The base snapshot or its analysis failed; record the diagnostic and drop review mode
            // rather than aborting the run.
            return null;
        } finally {
            // User view: choose the terminal output branch for this case.
            // User view: missing data becomes the expected terminal output state.
            if ($baseRoot !== null) {
                $gitArchiveSnapshot->remove($baseRoot);
            }
        }
    }

    /**
     * Resolve the project files project-wide rules need for the current analyse run.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string                $projectRoot - Project root used for full-tree discovery.
     * @param AnalyseCommandOptions $options - Effective CLI analysis options.
     * @param AnalysisConfig        $config - Effective rule and path configuration.
     * @param RuleRegistry          $registry - Rule registry consulted for enabled project rules.
     * @param DiffResult|null       $reviewDiff - Review diff metadata when branch review is active.
     * @param AnalysisSourceSet     $analysisSourceSet - Already-loaded sources for the requested paths.
     *
     * @return list<AnalysisUnit> - Project files needed by project-wide rules.
     */
    public function projectContextUnits(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleRegistry $registry,
        ?DiffResult $reviewDiff,
        AnalysisSourceSet $analysisSourceSet,
    ): array {
        // User view: choose the terminal output branch for this case.
        if (!$this->shouldLoadProjectContext(
            projectRoot: $projectRoot,
            options:     $options,
            registry:    $registry,
            config:      $config,
            reviewDiff:  $reviewDiff,
        )) {
            return $analysisSourceSet->analysisUnits;
        }

        // A project-wide rule is active under narrowed analysis, so load the whole tree it must see
        // beyond the changed files or changed line ranges.
        return (new AnalysisSourceLoader())->load(
            $projectRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /**
     * Decide which files the base-ref snapshot must contain for this run.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string                $projectRoot - Project root the requested paths resolve to.
     * @param AnalyseCommandOptions $options - Effective CLI options; sets changed-only scope.
     * @param DiffResult            $reviewDiff - Review diff metadata; the changed-file set.
     * @param bool                  $shouldLoadProjectContext - True when project rules force a full snapshot.
     *
     * @return list<string> - Paths that need to be copied from the base ref.
     */
    private function baseSnapshotPaths(
        string $projectRoot,
        AnalyseCommandOptions $options,
        DiffResult $reviewDiff,
        bool $shouldLoadProjectContext,
    ): array {
        $support = new AnalysisFindingSupport();

        // User view: choose the terminal output branch for this case.
        if ($shouldLoadProjectContext) {
            return [];
        }

        // User view: choose the terminal output branch for this case.
        if (!$options->isChangedOnly) {
            return $support->normaliseRequestedPaths($projectRoot, $options->paths);
        }

        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($reviewDiff->changedFiles === []) {
            return [];
        }

        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        $requestedPaths = $support->normaliseRequestedPaths($projectRoot, $options->paths);
        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($requestedPaths === []) {
            return [];
        }

        $paths = array_values(array_filter(
            $reviewDiff->changedFiles,
            static fn (string $changedFile): bool => $support->matchesRequestedPath($changedFile, $requestedPaths),
        ));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Decide which snapshot files to actually analyse, which can be narrower than the copied set.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string                $projectRoot - Project root the requested paths resolve against.
     * @param AnalyseCommandOptions $options - Effective CLI options; selects changed-only vs requested.
     * @param DiffResult            $reviewDiff - Review diff metadata supplying the changed-file set.
     *
     * @return list<string> - Paths that should be analysed from the base snapshot.
     */
    private function baseAnalysisPaths(string $projectRoot, AnalyseCommandOptions $options, DiffResult $reviewDiff): array
    {
        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($options->isChangedOnly && $options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($options->paths === []) {
            return [];
        }

        return (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
    }

    /**
     * Load the full base-ref tree so project-wide rules see the same context on both sides of the diff.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string                $baseRoot - Snapshot root of the base ref checkout to walk.
     * @param AnalyseCommandOptions $options - Effective CLI options; supplies the ignored-path inclusion flag.
     * @param AnalysisConfig        $config - Effective rule and path configuration supplying ignore patterns.
     *
     * @return list<AnalysisUnit> - Base-snapshot files needed for branch-review comparison.
     */
    private function baseProjectContextUnits(string $baseRoot, AnalyseCommandOptions $options, AnalysisConfig $config): array
    {
        // Project-wide rules need the entire base tree, not just the changed files, to compare like for like.
        return (new AnalysisSourceLoader())->load(
            $baseRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /**
     * Report whether narrowed analysis still has to load whole-tree context for project-level rules.
     *
      * User flow: Supports the terminal command path and the feedback it prints.
      *
     * @param string                $projectRoot - Project root requested paths resolve against.
     * @param AnalyseCommandOptions $options - Effective CLI options carrying changed-only and changed-region flags.
     * @param RuleRegistry          $registry - Rule registry consulted for any enabled project-wide rule.
     * @param AnalysisConfig        $config - Effective rule and path config for resolving enabled rules.
     * @param DiffResult|null       $reviewDiff - Review diff metadata; null or no changes means no context.
     *
     * @return bool - True when a narrowed run still needs complete context for project-level rules.
     */
    private function shouldLoadProjectContext(
        string $projectRoot,
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        AnalysisConfig $config,
        ?DiffResult $reviewDiff,
    ): bool {
        // User view: choose the terminal output branch for this case.
        if (!$registry->hasEnabledProjectRules($config)) {
            return false;
        }

        // User view: choose the terminal output branch for this case.
        if ($options->hasChangedRegionMode()) {
            return true;
        }

        // A whole-project request ('.', './', or the root path) covers the same tree a bare invocation
        // does, so it must not trigger the separate full-tree context load that genuinely narrower paths
        // need; otherwise `analyse . --diff-vs=<ref>` reparses the whole tree twice for the same scope.
        $requestedPaths = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        // User view: choose the terminal output branch for this case.
        // User view: an empty value becomes a clear terminal output fallback.
        if ($requestedPaths !== [] && $requestedPaths !== ['.']) {
            return true;
        }

        return $options->isChangedOnly
            && $reviewDiff instanceof DiffResult
            // User view: an empty value becomes a clear terminal output fallback.
            && $reviewDiff->changedFiles !== [];
    }
}
