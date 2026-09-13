<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Analysis\SensitiveExclusionFilter;
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
 * Powers `gruff-php analyse --diff-vs=<ref>`, which shows users what their branch changed against a base ref.
 *
 * It scans a git-archive snapshot and compares findings as introduced, removed, or unchanged.
 * Missing review input returns null and a failed comparison becomes a diagnostic, preserving the user's ordinary scan.
 */
final readonly class BranchReviewBuilder
{
    /**
     * Builds the user's branch review by analysing the base ref and comparing it with current findings.
     *
     * The report uses this result to label findings as introduced, removed, or unchanged.
     * It runs once for `analyse --diff-vs`, after the current tree has been scored.
     *
     * @param string                $projectRoot     - Project root the comparison runs from.
     * @param AnalyseCommandOptions $options         - Effective CLI analysis options, including the `--diff-vs` base ref.
     * @param AnalysisConfig        $config          - Effective rule and path configuration.
     * @param RuleRegistry          $registry        - Rule registry used to analyse the base snapshot.
     * @param list<Finding>         $currentFindings - Post-baseline findings for the current tree; empty when this branch is clean.
     * @param ReviewScoreContext    $scoreContext    - Current composite score and the evaluated-file denominator it came from, so the base side is scored the same way.
     * @param DiffResult|null       $reviewDiff      - Review diff metadata; null when the diff lookup failed, which turns review mode off.
     * @param list<RunDiagnostic>   $diagnostics     - Run diagnostics collected so far; review-mode errors are appended here in place.
     *
     * @return BranchReviewResult|null - Introduced/removed/unchanged comparison; null means review mode is off or the base snapshot failed
     */
    public function build(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleRegistry $registry,
        array $currentFindings,
        ReviewScoreContext $scoreContext,
        ?DiffResult $reviewDiff,
        array &$diagnostics,
    ): ?BranchReviewResult {
        // Without a `--diff-vs` ref or a usable diff, there is no base to compare against, so review mode stays off.
        if ($options->changeScope->diffVs === null || $reviewDiff === null) {
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
        $baseSnapshotPaths = $this->baseSnapshotPaths($projectRoot, $options, $reviewDiff, $shouldLoadProjectContext);
        $baseAnalysisPaths = $this->baseAnalysisPaths($projectRoot, $options, $reviewDiff);

        // Changed-only mode with no project context and nothing to pull from the base ref means the base side is empty.
        if ($options->changeScope->isChangedOnly && !$shouldLoadProjectContext && $baseSnapshotPaths === []) {
            // The base is scored on the current run's denominator, so the delta measures the branch's
            // findings rather than the difference between two project sizes.
            $baseScore = (new ScoreCalculator())->calculate([], $scoreContext->evaluatedFiles, null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config);

            // Compare against an empty base so every current finding is reported as introduced by this branch.
            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          [],
                baseRef:       $options->changeScope->diffVs,
                isChangedOnly: true,
                deltaScore:    $this->deltaVersusBase($scoreContext->currentScore, $baseScore->composite?->score),
            );
        }

        // Build and analyze the base-ref snapshot for the user's comparison.
        // Snapshot or diff failures become diagnostics rather than aborting the ordinary scan.
        try {
            $baseRoot     = $gitArchiveSnapshot->create($projectRoot, $options->changeScope->diffVs, $baseSnapshotPaths);
            $basePaths    = (new AnalysisFindingSupport())->existingSnapshotPaths($baseRoot, $baseAnalysisPaths);
            $baseFindings = [];

            // Only analyse the base snapshot when it actually holds files; an empty set means nothing survived from the base ref to score.
            if ($basePaths !== []) {
                $baseSources = (new AnalysisSourceLoader())->load(
                    $baseRoot,
                    $basePaths,
                    $options->shouldIncludeIgnored,
                    $config->ignoredPathPatterns(),
                    $config->deepScanBudget(),
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
            }

            // Reviewed sensitive exclusions hide their findings on the base side too, keeping the comparison
            // symmetric; otherwise an accepted synthetic credential present in both trees reads as removed.
            $baseFindings = (new SensitiveExclusionFilter())->apply($baseFindings, $config->sensitiveExclusions())->findings;

            // In changed-only mode the user only cares about files this branch touched, so trim the base findings to those same files.
            if ($options->changeScope->isChangedOnly) {
                $baseFindings = (new AnalysisFindingSupport())->filterFindingsToChangedFiles($baseFindings, $reviewDiff->changedFiles);
            }

            // An existing baseline hides accepted debt from the base side too, keeping the user's comparison symmetric.
            if ($options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null) {
                try {
                    $baseFindings = (new BaselineApplication())->filterExisting($projectRoot, $options->baseline->baselinePath, $baseFindings);
                } catch (BaselineException $exception) {
                    // A malformed or outdated baseline should not sink review mode; report it and continue with unfiltered base findings.
                    $diagnostics[] = new RunDiagnostic(
                        type:    'baseline-error',
                        message: $exception->getMessage(),
                        path:    $options->baseline->baselinePath,
                    );
                }
            }

            $baseFindings = (new AnalysisFindingSupport())->normalizeFindingPaths($baseFindings, $options->pathsRelativeTo);
            $baseScore    = (new ScoreCalculator())->calculate($baseFindings, $scoreContext->evaluatedFiles, null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config);

            // Compare current and base findings so the report identifies what this branch introduced or removed.
            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          $baseFindings,
                baseRef:       $options->changeScope->diffVs,
                isChangedOnly: $options->changeScope->isChangedOnly,
                deltaScore:    $this->deltaVersusBase($scoreContext->currentScore, $baseScore->composite?->score),
            );
        } catch (DiffException | RuntimeException $exception) {
            // A missing base ref or unreadable git archive can block comparison; keep the ordinary scan and report review mode as unavailable.
            $diagnostics[] = new RunDiagnostic(
                type:    'review-mode-error',
                message: $exception->getMessage(),
            );

            // Return null so a failed base comparison drops review mode but preserves the user's ordinary findings.
            return null;
        } finally {
            // Always delete the temporary base-ref checkout we created, whether the comparison succeeded or threw.
            if ($baseRoot !== null) {
                $gitArchiveSnapshot->remove($baseRoot);
            }
        }
    }

    /**
     * Measures the score movement users see between their branch and its base snapshot.
     * When the current scan has no applicable score, it returns null so the UI does not present a synthetic improvement from an empty run.
     *
     * @param float|null $currentScore - Composite score of the current findings; null when discovery found no files to score.
     * @param float      $baseScore    - Composite score calculated for the base snapshot's findings.
     *
     * @return float|null - Score movement from base to current, or null when the current side has no applicable score.
     */
    private function deltaVersusBase(?float $currentScore, ?float $baseScore): ?float
    {
        // A base that evaluated nothing has no score to subtract, so no delta can be claimed against it.
        return $currentScore === null || $baseScore === null ? null : $currentScore - $baseScore;
    }

    /**
     * Finds the extra whole-project files that project-wide rules need during a narrowed user scan.
     *
     * This keeps changed-file analysis fast without hiding the wider project context from those rules.
     * The analysis command calls it before rules execute.
     *
     * @param string                $projectRoot       - Project root used for full-tree discovery.
     * @param AnalyseCommandOptions $options           - Effective CLI analysis options.
     * @param AnalysisConfig        $config            - Effective rule and path configuration.
     * @param RuleRegistry          $registry          - Rule registry consulted for enabled project rules.
     * @param DiffResult|null       $reviewDiff        - Review diff metadata; null when branch review is inactive for this run.
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
            $config->deepScanBudget(),
        )->analysisUnits;
    }

    /**
     * Decides which files the base-ref snapshot must contain so the copy from `git archive` is no
     * wider than the comparison actually needs. Drives how much of the base ref gets checked out.
     *
     * @param string                $projectRoot              - Project root the requested paths resolve to.
     * @param AnalyseCommandOptions $options                  - Effective CLI options; sets changed-only scope.
     * @param DiffResult            $reviewDiff               - Review diff metadata carrying the changed-file set.
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
        if (!$options->changeScope->isChangedOnly) {
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
     * @param AnalyseCommandOptions $options     - Effective CLI options; selects changed-only vs requested paths.
     * @param DiffResult            $reviewDiff  - Review diff metadata supplying the changed-file set.
     *
     * @return list<string> - Paths to analyse from the base snapshot; empty when no explicit paths were requested for the base side.
     */
    private function baseAnalysisPaths(string $projectRoot, AnalyseCommandOptions $options, DiffResult $reviewDiff): array
    {
        // Changed-only with no explicit paths: analyse exactly the files the diff says this branch touched.
        if ($options->changeScope->isChangedOnly && $options->paths === []) {
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
     * @param AnalyseCommandOptions $options  - Effective CLI options; supplies the ignored-path inclusion flag.
     * @param AnalysisConfig        $config   - Effective rule and path configuration supplying ignore patterns.
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
            $config->deepScanBudget(),
        )->analysisUnits;
    }

    /**
     * Reports whether the user's narrowed run still needs whole-tree context for project-level rules.
     *
     * This decision keeps `--changed-only` and `--diff-vs` fast without starving project rules.
     * The loader consults it before deciding how much of the tree to read.
     *
     * @param string                $projectRoot - Project root requested paths resolve against.
     * @param AnalyseCommandOptions $options     - Effective CLI options carrying the changed-only and changed-region flags.
     * @param RuleRegistry          $registry    - Rule registry consulted for any enabled project-wide rule.
     * @param AnalysisConfig        $config      - Effective rule and path config for resolving enabled rules.
     * @param DiffResult|null       $reviewDiff  - Review diff; null or no changes means that input does not require full project context.
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

        // For a whole-project request, only changed-only mode with real changes still needs extra context for project rules.
        return $options->changeScope->isChangedOnly
            && $reviewDiff instanceof DiffResult
            && $reviewDiff->changedFiles !== [];
    }
}
