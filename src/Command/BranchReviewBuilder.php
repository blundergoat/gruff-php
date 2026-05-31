<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Baseline\BaselineApplication;
use GruffPhp\Baseline\BaselineException;
use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Diff\DiffException;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Review\BranchReviewComparator;
use GruffPhp\Review\BranchReviewResult;
use GruffPhp\Review\GitArchiveSnapshot;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Scoring\CompositeFindingFactory;
use GruffPhp\Scoring\ScoreCalculator;
use RuntimeException;

/**
 * Builds the --diff-vs branch-review comparison and resolves project context units for AnalyseCommand.
 */
final readonly class BranchReviewBuilder
{
    /**
     * Compare current findings against a base ref snapshot and report introduced/removed/unchanged findings.
     *
     * @param string              $projectRoot     Project root the comparison runs from.
     * @param AnalyseCommandOptions $options       Effective CLI analysis options.
     * @param AnalysisConfig      $config          Effective rule and path configuration.
     * @param RuleRegistry        $registry        Rule registry used to analyse the base snapshot.
     * @param list<Finding>       $currentFindings Post-baseline findings for the current tree.
     * @param float               $currentScore    Composite score of the current findings.
     * @param DiffResult|null     $reviewDiff      Review diff metadata, or null when diff lookup failed.
     * @param list<RunDiagnostic> $diagnostics     Run diagnostics; review-mode errors are appended in place.
     * @return BranchReviewResult|null Review comparison, or null when disabled/unavailable.
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
        if ($options->diffVs === null || $reviewDiff === null) {
            return null;
        }

        $gitArchiveSnapshot       = new GitArchiveSnapshot();
        $baseRoot                 = null;
        $shouldLoadProjectContext = $this->shouldLoadChangedOnlyProjectContext($options, $registry, $config, $reviewDiff);
        $baseSnapshotPaths        = $this->baseSnapshotPaths($projectRoot, $options, $reviewDiff, $shouldLoadProjectContext);
        $baseAnalysisPaths        = $this->baseAnalysisPaths($projectRoot, $options, $reviewDiff);

        if ($options->isChangedOnly && !$shouldLoadProjectContext && $baseSnapshotPaths === []) {
            $baseScore = (new ScoreCalculator())->calculate([], null, null, scorePillars: $options->profileScorePillars(), analysisConfig: $config);

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
                $baseFindings = array_merge($baseFindings, (new CompositeFindingFactory())->build($baseFindings));
                $baseFindings = (new AnalysisFindingSupport())->filterAllowedSecretPreviews($baseFindings, $config);
            }

            if ($options->isChangedOnly) {
                $baseFindings = (new AnalysisFindingSupport())->filterFindingsToChangedFiles($baseFindings, $reviewDiff->changedFiles);
            }

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

            return null;
        } finally {
            if ($baseRoot !== null) {
                $gitArchiveSnapshot->remove($baseRoot);
            }
        }
    }

    /**
     * Resolve the project files project-wide rules need for the current analyse run.
     *
     * @param string                $projectRoot       Project root used for full-tree discovery.
     * @param AnalyseCommandOptions $options           Effective CLI analysis options.
     * @param AnalysisConfig        $config            Effective rule and path configuration.
     * @param RuleRegistry          $registry          Rule registry consulted for enabled project rules.
     * @param DiffResult|null       $reviewDiff        Review diff metadata when branch review is active.
     * @param AnalysisSourceSet     $analysisSourceSet Already-loaded sources for the requested paths.
     * @return list<AnalysisUnit> Project files needed by project-wide rules.
     */
    public function projectContextUnits(
        string $projectRoot,
        AnalyseCommandOptions $options,
        AnalysisConfig $config,
        RuleRegistry $registry,
        ?DiffResult $reviewDiff,
        AnalysisSourceSet $analysisSourceSet,
    ): array {
        if (!$this->shouldLoadChangedOnlyProjectContext($options, $registry, $config, $reviewDiff)) {
            return $analysisSourceSet->analysisUnits;
        }

        return (new AnalysisSourceLoader())->load(
            $projectRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /** @return list<string> Paths that need to be copied from the base ref. */
    private function baseSnapshotPaths(
        string $projectRoot,
        AnalyseCommandOptions $options,
        DiffResult $reviewDiff,
        bool $shouldLoadProjectContext,
    ): array {
        $support = new AnalysisFindingSupport();

        if (!$options->isChangedOnly) {
            return $support->normaliseRequestedPaths($projectRoot, $options->paths);
        }

        if ($shouldLoadProjectContext) {
            return [];
        }

        if ($reviewDiff->changedFiles === []) {
            return [];
        }

        if ($options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        $requestedPaths = $support->normaliseRequestedPaths($projectRoot, $options->paths);
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

    /** @return list<string> Paths that should be analysed from the base snapshot. */
    private function baseAnalysisPaths(string $projectRoot, AnalyseCommandOptions $options, DiffResult $reviewDiff): array
    {
        if ($options->isChangedOnly && $options->paths === []) {
            return $reviewDiff->changedFiles;
        }

        if ($options->paths === []) {
            return [];
        }

        return (new AnalysisFindingSupport())->normaliseRequestedPaths($projectRoot, $options->paths);
    }

    /** @return list<AnalysisUnit> Base-snapshot files needed for branch-review comparison. */
    private function baseProjectContextUnits(string $baseRoot, AnalyseCommandOptions $options, AnalysisConfig $config): array
    {
        return (new AnalysisSourceLoader())->load(
            $baseRoot,
            [],
            $options->shouldIncludeIgnored,
            $config->ignoredPathPatterns(),
        )->analysisUnits;
    }

    /** @return bool True when changed-only mode still needs complete context for project-level rules. */
    private function shouldLoadChangedOnlyProjectContext(
        AnalyseCommandOptions $options,
        RuleRegistry $registry,
        AnalysisConfig $config,
        ?DiffResult $reviewDiff,
    ): bool {
        return $options->isChangedOnly
            && $reviewDiff instanceof DiffResult
            && $reviewDiff->changedFiles !== []
            && $registry->hasEnabledProjectRules($config);
    }
}
