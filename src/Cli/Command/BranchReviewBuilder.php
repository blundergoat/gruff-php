<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\InlineSuppressionFilter;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Analysis\SensitiveExclusionFilter;
use GruffPhp\Results\Baseline\BaselineApplication;
use GruffPhp\Results\Baseline\BaselineException;
use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Diff\DiffException;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\DeclarationInventory;
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
     * @param AnalyseCommandOptions $options         - Effective CLI analysis options, including the --diff-vs base ref.
     * @param AnalysisConfig        $config          - Effective rule and path configuration.
     * @param RuleRegistry $registry - Registry consulted to decide whether whole-project context is needed.
     * @param list<Finding>         $currentFindings - Post-baseline findings for the current tree; empty when this branch is clean.
     * @param ReviewScoreContext $scoreContext - Current score for the delta and evaluated-file denominator reused for base scoring.
     * @param DiffResult|null       $reviewDiff      - Review diff metadata; null when the diff lookup failed, which turns review mode off.
     * @param list<RunDiagnostic>   $diagnostics     - Run diagnostics collected so far; review-mode errors are appended here in place.
     *
     * @return BranchReviewResult|null - Comparison result; null when review is disabled or a caught diff/runtime error prevents it.
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
        // Without a --diff-vs ref or a usable diff, there is no base to compare against, so review mode stays off.
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
            $baseScorePillars = $registry->enabledStaticPillars($config, $options->profileScorePillars());
            $baseScore        = (new ScoreCalculator())->calculate([], $scoreContext->evaluatedFiles, null, null, scorePillars: $baseScorePillars, analysisConfig: $config);

            // Compare against an empty base so every current finding is reported as introduced by this branch.
            return (new BranchReviewComparator())->compare(
                current:       $currentFindings,
                base:          [],
                baseRef:       $options->changeScope->diffVs,
                isChangedOnly: true,
                deltaScore:    $this->deltaVersusBase($scoreContext->currentScore, $baseScore->composite?->score),
            );
        }


        // Snapshot or diff failures become diagnostics rather than aborting the ordinary scan.
        try {
            $baseRoot     = $gitArchiveSnapshot->create($projectRoot, $options->changeScope->diffVs, $baseSnapshotPaths);
            $basePaths    = (new AnalysisFindingSupport())->existingSnapshotPaths($baseRoot, $baseAnalysisPaths);
            $baseFindings = [];
            $baseFiles    = [];


            // Only parse and analyse the base snapshot when at least one requested path existed at the base ref.
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
                $baseFiles = $baseSources->discovery->files;
            }

            // Apply the same suppression rules to both trees so existing suppressed findings do not appear as removed.

            $baseFindings = (new SensitiveExclusionFilter())->apply(
                $baseFindings,
                $config->sensitiveExclusions(),
                InlineSuppressionFilter::forFiles($baseFiles),
            )->findings;

            // In changed-only mode the user only cares about files this branch touched, so trim the base findings to those same files.
            if ($options->changeScope->isChangedOnly) {
                $baseFindings = (new AnalysisFindingSupport())->filterFindingsToChangedFiles($baseFindings, $reviewDiff->changedFiles);
            }

            // An existing baseline hides accepted debt from the base side too, keeping the user's comparison symmetric.
            if ($options->baseline->baselinePath !== null && $options->baseline->generateBaselinePath === null) {
                try {
                    $baseFindings = (new BaselineApplication())->filterExisting(
                        $projectRoot,
                        $options->baseline->baselinePath,
                        $baseFindings,
                        DeclarationInventory::forFindings($baseRoot, $baseFindings),
                    );
                } catch (BaselineException $exception) {
                    // If baseline filtering fails, report the error and compare the unfiltered base findings.
                    $diagnostics[] = new RunDiagnostic(
                        type:    'baseline-error',
                        message: $exception->getMessage(),
                        path:    $options->baseline->baselinePath,
                    );
                }
            }

            $baseFindings     = (new AnalysisFindingSupport())->normalizeFindingPaths($baseFindings, $options->pathsRelativeTo);
            $baseScorePillars = $registry->enabledStaticPillars($config, $options->profileScorePillars());
            $baseScore        = (new ScoreCalculator())->calculate($baseFindings, $scoreContext->evaluatedFiles, null, null, scorePillars: $baseScorePillars, analysisConfig: $config);


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


            return null;
        } finally {
            // Attempt to remove the temporary base snapshot after both successful and failed comparisons.
            if ($baseRoot !== null) {
                $gitArchiveSnapshot->remove($baseRoot);
            }
        }
    }

    /**
     * Returns the current composite score minus the base score, or null if either score is unavailable.
     *
     * @param float|null $currentScore - Current composite score; null when no applicable score is available.
     * @param float      $baseScore    - Base snapshot score; null means no applicable base score is available.
     *
     * @return float|null - Score movement from base to current, or null when either score is unavailable.
     */
    private function deltaVersusBase(?float $currentScore, ?float $baseScore): ?float
    {

        return $currentScore === null || $baseScore === null ? null : $currentScore - $baseScore;
    }

    /**
     * Supplies analysis units for project-wide rules, loading the full tree when the requested scope needs wider context.
     * Otherwise reuses the source set's existing units.
     *
     * @param string                $projectRoot       - Project root used for full-tree discovery.
     * @param AnalyseCommandOptions $options           - Effective CLI analysis options.
     * @param AnalysisConfig        $config            - Effective rule and path configuration.
     * @param RuleRegistry          $registry          - Rule registry consulted for enabled project rules.
     * @param DiffResult|null $reviewDiff - Inactive for plain scans; null after a failed review diff lookup.
     * @param AnalysisSourceSet     $analysisSourceSet - Already-loaded sources for the requested paths.
     *
     * @return list<AnalysisUnit> - Full-tree context units, or the existing requested-path units when no wider load is needed.
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

        // Load the full tree so project rules can see beyond the requested files or changed regions.
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


        // Without --changed-only, snapshot every requested path from the base ref for a whole-target comparison.
        if (!$options->changeScope->isChangedOnly) {
            return $support->normaliseRequestedPaths($projectRoot, $options->paths);
        }

        // The branch changed nothing versus the base ref, so there is nothing to pull from it.
        if ($reviewDiff->changedFiles === []) {
            return [];
        }

        // When no explicit paths were typed under --changed-only, snapshot every file touched by the branch diff.
        if ($options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        $requestedPaths = $support->normaliseRequestedPaths($projectRoot, $options->paths);
        // The user's paths normalised to nothing usable, so the base snapshot needs no files.
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
     * Selects base-snapshot paths for analysis, independently of any wider snapshot copied for project context.
     *
     * @param string                $projectRoot - Project root the requested paths resolve against.
     * @param AnalyseCommandOptions $options     - Effective CLI options; selects changed-only vs requested paths.
     * @param DiffResult            $reviewDiff  - Review diff metadata supplying the changed-file set.
     *
     * @return list<string> - Changed or normalized requested paths; empty defaults to the snapshot root when resolved by the caller.
     */
    private function baseAnalysisPaths(string $projectRoot, AnalyseCommandOptions $options, DiffResult $reviewDiff): array
    {
        // Under --changed-only with no explicit targets, analyse only the files modified on this branch.
        if ($options->changeScope->isChangedOnly && $options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        // An empty path list is expanded to the snapshot root by existingSnapshotPaths().
        if ($options->paths === []) {
            return [];
        }


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
        // Keep project context independent of changed-file scope while retaining the configured ignore rules.
        return (new AnalysisSourceLoader())->load(
            $baseRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
            $config->deepScanBudget(),
        )->analysisUnits;
    }

    /**
     * Determines whether enabled project rules need a full-tree context load for a narrowed scan.
     * Changed regions, narrow paths, and changed-only runs with changed files can require that load.
     *
     * @param string                $projectRoot - Project root requested paths resolve against.
     * @param AnalyseCommandOptions $options     - Effective CLI options carrying the changed-only and changed-region flags.
     * @param RuleRegistry          $registry    - Rule registry consulted for any enabled project-wide rule.
     * @param AnalysisConfig        $config      - Effective rule and path config for resolving enabled rules.
     * @param DiffResult|null       $reviewDiff  - Review diff; null or no changes means that input does not require full project context.
     *
     * @return bool - True when enabled project rules need a full-tree load beyond the requested scope.
     */
    private function shouldLoadProjectContext(
        string $projectRoot,
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        AnalysisConfig $config,
        ?DiffResult $reviewDiff,
    ): bool {
        // When no project-wide rule is enabled, per-file rules need no whole-project context snapshot.
        if (!$registry->hasEnabledProjectRules($config)) {
            return false;
        }

        // Changed-region mode scores only selected line ranges, so project rules still need the full tree for honest context.
        if ($options->hasChangedRegionMode()) {
            return true;
        }

        // A whole-project request ('.', './', or the root path) covers the same tree as a bare invocation, so skip
        // loading a separate project-context tree; otherwise `analyse . --diff-vs=<ref>` would reparse the repo twice.
        $requestedPaths = (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
        // Narrow paths leave project rules without the rest of the tree, so load that context separately.
        if ($requestedPaths !== [] && $requestedPaths !== ['.']) {
            return true;
        }


        return $options->changeScope->isChangedOnly
            && $reviewDiff instanceof DiffResult
            && $reviewDiff->changedFiles !== [];
    }
}
