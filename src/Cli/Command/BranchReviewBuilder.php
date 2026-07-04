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
 * Powers `gruff-php analyse --diff-vs=<ref>`, the branch-review mode that tells a user what their
 * current branch changed against a base ref instead of just listing every finding on disk.
 *
 * It snapshots the base ref with `git archive`, re-runs the rules over that older tree, then diffs
 * the two finding sets so the report can label each issue introduced, removed, or unchanged. It also
 * resolves the extra whole-project files that project-wide rules must see on both sides of the
 * comparison, and quietly bows out (returning null) whenever review mode is off or the base snapshot
 * cannot be built, so a failed comparison never aborts the ordinary scan.
 */
final readonly class BranchReviewBuilder
{
    /**
     * Runs branch-review mode end to end: snapshot the base ref, analyse it, and diff it against the
     * current findings so the report can flag what this branch introduced or removed. Called once per
     * `analyse --diff-vs` run, after the current tree has already been scored.
     *
     * @param string                $projectRoot - Project root the comparison runs from.
     * @param AnalyseCommandOptions $options - Effective CLI analysis options, including the `--diff-vs` base ref.
     * @param AnalysisConfig        $config - Effective rule and path configuration.
     * @param RuleRegistry          $registry - Rule registry used to analyse the base snapshot.
     * @param list<Finding>         $currentFindings - Post-baseline findings for the current tree; empty when this branch is clean.
     * @param float                 $currentScore - Composite score of the current findings.
     * @param DiffResult|null       $reviewDiff - Review diff metadata; null when the diff lookup failed, which turns review mode off.
     * @param list<RunDiagnostic>   $diagnostics - Run diagnostics collected so far; review-mode errors are appended here in place.
     *
     * @return BranchReviewResult|null - The introduced/removed/unchanged comparison; null when review mode is off or the base snapshot could not be built.
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
        // Without a `--diff-vs` ref or a usable diff, there is no base to compare against, so review mode stays off.
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

        // Changed-only mode with no project context and nothing to pull from the base ref means the base side is empty.
        if ($options->isChangedOnly && !$shouldLoadProjectContext && $baseSnapshotPaths === []) {
            $baseScore = (new ScoreCalculator())->calculate([], null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config);

            // Compare against an empty base so every current finding is reported as introduced by this branch.
            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          [],
                baseRef:       $options->diffVs,
                isChangedOnly: true,
                deltaScore:    $currentScore - $baseScore->composite->score,
            );
        }

        // Build the base-ref snapshot and analyse it; a snapshot or diff failure here (DiffException/RuntimeException) is caught and downgraded to a diagnostic rather than aborting the run.
        try {
            $baseRoot     = $gitArchiveSnapshot->create($projectRoot, $options->diffVs, $baseSnapshotPaths);
            $basePaths    = (new AnalysisFindingSupport())->existingSnapshotPaths($baseRoot, $baseAnalysisPaths);
            $baseFindings = [];

            // Only analyse the base snapshot when it actually holds files; an empty set means nothing survived from the base ref to score.
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

            // In changed-only mode the user only cares about files this branch touched, so trim the base findings to those same files.
            if ($options->isChangedOnly) {
                $baseFindings = (new AnalysisFindingSupport())->filterFindingsToChangedFiles($baseFindings, $reviewDiff->changedFiles);
            }

            // A baseline is in force and we are not regenerating it, so hide already-accepted debt from the base side too, keeping the diff symmetric.
            if ($options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null) {
                try {
                    $baseFindings = (new BaselineApplication())->filterExisting($projectRoot, $options->baseline->baselinePath, $baseFindings);
                } catch (BaselineException $exception) {
                    // A broken baseline file shouldn't sink review mode, so note it as a diagnostic and carry on with unfiltered base findings.
                    $diagnostics[] = new RunDiagnostic(
                        type:    'baseline-error',
                        message: $exception->getMessage(),
                        path:    $options->baseline->baselinePath,
                    );
                }
            }

            $baseFindings = (new AnalysisFindingSupport())->normalizeFindingPaths($baseFindings, $options->pathsRelativeTo);
            $baseScore    = (new ScoreCalculator())->calculate($baseFindings, null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config);

            // Diff the current findings against the base ref's own findings so the report tells the user exactly what this branch introduced or removed.
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

            // Drop review mode and return null so the run keeps its normal findings rather than aborting the user's whole scan over a failed base comparison.
            return null;
        } finally {
            // Always delete the temporary base-ref checkout we created, whether the comparison succeeded or threw.
            if ($baseRoot !== null) {
                $gitArchiveSnapshot->remove($baseRoot);
            }
        }
    }

    /**
     * Works out the extra whole-project files project-wide rules must see for this run, so a narrowed
     * scan doesn't blind them to the rest of the tree. Called while assembling the current analysis
     * before the rules execute.
     *
     * @param string                $projectRoot - Project root used for full-tree discovery.
     * @param AnalyseCommandOptions $options - Effective CLI analysis options.
     * @param AnalysisConfig        $config - Effective rule and path configuration.
     * @param RuleRegistry          $registry - Rule registry consulted for enabled project rules.
     * @param DiffResult|null       $reviewDiff - Review diff metadata; null when branch review is inactive for this run.
     * @param AnalysisSourceSet     $analysisSourceSet - Already-loaded sources for the requested paths.
     *
     * @return list<AnalysisUnit> - Files project-wide rules need; the requested-path units alone when no full-tree load is required.
     */
    public function projectContextUnits(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleRegistry $registry,
        ?DiffResult $reviewDiff,
        AnalysisSourceSet $analysisSourceSet,
    ): array {
        // When no project-wide rule needs the whole tree, the sources already loaded for the requested paths are all the context there is.
        if (!$this->shouldLoadProjectContext(
            projectRoot: $projectRoot,
            options:     $options,
            registry:    $registry,
            config:      $config,
            reviewDiff:  $reviewDiff,
        )) {
            return $analysisSourceSet->analysisUnits;
        }

        // A project-wide rule is active under a narrowed scan, so load the whole tree it must see beyond the changed files or changed line ranges.
        return (new AnalysisSourceLoader())->load(
            $projectRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /**
     * Decides which files the base-ref snapshot must contain so the copy from `git archive` is no
     * wider than the comparison actually needs. Drives how much of the base ref gets checked out.
     *
     * @param string                $projectRoot - Project root the requested paths resolve to.
     * @param AnalyseCommandOptions $options - Effective CLI options; sets changed-only scope.
     * @param DiffResult            $reviewDiff - Review diff metadata carrying the changed-file set.
     * @param bool                  $shouldLoadProjectContext - True when project rules force a full snapshot.
     *
     * @return list<string> - Paths to copy from the base ref; empty means snapshot the whole tree, or that nothing needs copying.
     */
    private function baseSnapshotPaths(
        string $projectRoot,
        AnalyseCommandOptions $options,
        DiffResult $reviewDiff,
        bool $shouldLoadProjectContext,
    ): array {
        $support = new AnalysisFindingSupport();

        // Project-wide rules force a full base checkout, so request no specific paths and let the whole tree be snapshotted.
        if ($shouldLoadProjectContext) {
            return [];
        }

        // A normal (not changed-only) run compares the exact paths the user asked to analyse.
        if (!$options->isChangedOnly) {
            return $support->normaliseRequestedPaths($projectRoot, $options->paths);
        }

        // The branch changed nothing versus the base ref, so there is nothing to pull from it.
        if ($reviewDiff->changedFiles === []) {
            return [];
        }

        // A bare changed-only run with no explicit paths puts every changed file in scope.
        if ($options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        $requestedPaths = $support->normaliseRequestedPaths($projectRoot, $options->paths);
        // The user's paths normalised to nothing usable, so the base snapshot needs no files.
        if ($requestedPaths === []) {
            return [];
        }

        // Otherwise keep only the changed files that also fall under the paths the user named.
        $paths = array_values(array_filter(
            $reviewDiff->changedFiles,
            static fn (string $changedFile): bool => $support->matchesRequestedPath($changedFile, $requestedPaths),
        ));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Decides which snapshot files to actually analyse, which can be narrower than the set copied
     * from the base ref: you may check out a file for context yet only score the changed ones.
     *
     * @param string                $projectRoot - Project root the requested paths resolve against.
     * @param AnalyseCommandOptions $options - Effective CLI options; selects changed-only vs requested paths.
     * @param DiffResult            $reviewDiff - Review diff metadata supplying the changed-file set.
     *
     * @return list<string> - Paths to analyse from the base snapshot; empty when no explicit paths were requested for the base side.
     */
    private function baseAnalysisPaths(string $projectRoot, AnalyseCommandOptions $options, DiffResult $reviewDiff): array
    {
        // Changed-only with no explicit paths: analyse exactly the files the diff says this branch touched.
        if ($options->isChangedOnly && $options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        // No paths named outside changed-only mode, so there is nothing narrower to pull from the snapshot.
        if ($options->paths === []) {
            return [];
        }

        // Otherwise analyse just the paths the user requested, resolved against the project root.
        return (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
    }

    /**
     * Loads the full base-ref tree so project-wide rules see the same whole-project context on both
     * sides of the diff, keeping the introduced/removed verdict fair rather than an artefact of scope.
     *
     * @param string                $baseRoot - Snapshot root of the base-ref checkout to walk.
     * @param AnalyseCommandOptions $options - Effective CLI options; supplies the ignored-path inclusion flag.
     * @param AnalysisConfig        $config - Effective rule and path configuration supplying ignore patterns.
     *
     * @return list<AnalysisUnit> - Every base-snapshot file the project-wide rules need for a like-for-like comparison.
     */
    private function baseProjectContextUnits(string $baseRoot, AnalyseCommandOptions $options, AnalysisConfig $config): array
    {
        // Load the entire base snapshot unfiltered - no changed-file narrowing here - so both sides share the same project scope.
        return (new AnalysisSourceLoader())->load(
            $baseRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /**
     * Reports whether a narrowed run still has to load whole-tree context for project-level rules, the
     * single decision that keeps `--changed-only` and `--diff-vs` fast without starving project rules
     * of the files they need. Consulted before deciding how much of the tree to load.
     *
     * @param string                $projectRoot - Project root requested paths resolve against.
     * @param AnalyseCommandOptions $options - Effective CLI options carrying the changed-only and changed-region flags.
     * @param RuleRegistry          $registry - Rule registry consulted for any enabled project-wide rule.
     * @param AnalysisConfig        $config - Effective rule and path config for resolving enabled rules.
     * @param DiffResult|null       $reviewDiff - Review diff metadata; null, or a diff with no changes, means no full context is needed on that account.
     *
     * @return bool - True when a narrowed run still needs the complete tree for project-level rules; false when the loaded scope already suffices.
     */
    private function shouldLoadProjectContext(
        string $projectRoot,
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        AnalysisConfig $config,
        ?DiffResult $reviewDiff,
    ): bool {
        // No project-wide rule is enabled, so a narrowed run never needs the extra whole-tree load.
        if (!$registry->hasEnabledProjectRules($config)) {
            return false;
        }

        // Changed-region mode scores only selected line ranges, so project rules still need the full tree for honest context.
        if ($options->hasChangedRegionMode()) {
            return true;
        }

        // A whole-project request ('.', './', or the root path) covers the same tree a bare invocation
        // does, so it must not trigger the separate full-tree context load that genuinely narrower paths
        // need; otherwise `analyse . --diff-vs=<ref>` reparses the whole tree twice for the same scope.
        $requestedPaths = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        // A genuinely narrower request (a subdirectory or single file) hides the rest of the tree, so pull it all in for project rules.
        if ($requestedPaths !== [] && $requestedPaths !== ['.']) {
            return true;
        }

        // Left with a whole-project or '.' request: only changed-only mode with real changes still needs full context so project rules see files beyond the changed set.
        return $options->isChangedOnly
            && $reviewDiff instanceof DiffResult
            && $reviewDiff->changedFiles !== [];
    }
}
