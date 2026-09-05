<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;

/**
 * Decides how one `gruff-php analyse` run meets the project baseline, the recorded set of findings a user already reviewed.
 *
 * The run branches four ways:
 * - `--generate-baseline` writes a fresh baseline from the current findings;
 * - `--migrate-baseline` with `--generate-baseline` carries a 0.5 baseline's reviews into a new file, leaving the original untouched;
 * - `--baseline` (or a discovered `gruff-baseline.json`) hides reviewed findings so only new problems surface;
 * - no baseline, or `--no-baseline`, reports every finding.
 *
 * Generate, migrate, and apply return a `BaselineReport` describing what was written or hidden; skipping, or a read/write failure, returns null.
 */
final readonly class BaselineApplication
{
    /**
     * Reads a baseline and hands back only the findings it does not already cover, with none of the report accounting.
     *
     * @param string        $projectRoot - Project root used to resolve the baseline path.
     * @param string        $baselinePath - Baseline path to read, relative to the project root when not absolute.
     * @param list<Finding> $findings - Live findings to test against the baseline before reporting.
     *
     * @return list<Finding> - The gated findings; empty when the baseline covered every one of them.
     * @throws BaselineException When the baseline cannot be read, validated, or was written by another port.
     */
    public function filterExisting(string $projectRoot, string $baselinePath, array $findings): array
    {
        $baseline = (new BaselineStore($projectRoot))->read($baselinePath);

        return (new BaselineFilter())->apply($baseline, $findings, false)['findings'];
    }

    /**
     * Picks the one baseline action the user's flags asked for and reshapes the findings list to match before reporting.
     *
     * @param string                     $projectRoot - Project root used to resolve baseline paths.
     * @param BaselineApplicationOptions $options - Baseline flags for this run: generate, migrate, apply, or skip, plus the paths.
     * @param list<Finding>              $findings - Findings for this run; replaced with the gated set when an existing baseline is applied.
     * @param DiffResult|null            $diff - An active diff limits the run to changed lines, so reviewed debt is not marked resolved; null means no diff scope.
     * @param list<RunDiagnostic>        $diagnostics - Accumulator; a read or write failure appends a baseline-error entry, and each collision appends a warning.
     * @param bool                       $hasPartialScope - Whether the run scanned only part of the project, so absent rows are not evaluated.
     *
     * @return BaselineReport|null - Report for the generated, migrated, or applied baseline; null when none was configured or the action failed and a diagnostic was recorded.
     */
    public function apply(
        string $projectRoot,
        BaselineApplicationOptions $options,
        array &$findings,
        ?DiffResult $diff,
        array &$diagnostics,
        bool $hasPartialScope = false,
    ): ?BaselineReport {
        $baselineStore = new BaselineStore($projectRoot);

        // The user asked to capture the current state, so write a fresh baseline instead of comparing against one; generation always wins.
        if ($options->generateBaselinePath !== null) {
            return $this->generate($baselineStore, $options, $findings, $diagnostics);
        }

        // No baseline in play, so every finding stands and the run carries no baseline section at all.
        if ($options->baselinePath === null) {
            return null;
        }

        return $this->applyExistingBaseline(
            store:           $baselineStore,
            options:         $options,
            findings:        $findings,
            diff:            $diff,
            diagnostics:     $diagnostics,
            hasPartialScope: $hasPartialScope,
        );
    }

    /**
     * Writes a new baseline, from the current findings alone or carried across from a 0.5 file the user is migrating.
     *
     * @param BaselineStore              $store - Store that writes and locates the baseline file.
     * @param BaselineApplicationOptions $options - Carries the output path and, for a migration, the 0.5 input path.
     * @param list<Finding>              $findings - Findings to record; on a migration only those the 0.5 rows covered are kept.
     * @param list<RunDiagnostic>        $diagnostics - Accumulator; a write or migration failure appends a baseline-error entry the user sees.
     *
     * @return BaselineReport|null - Report for the freshly written baseline; null when the write failed and a diagnostic was logged instead.
     */
    private function generate(
        BaselineStore $store,
        BaselineApplicationOptions $options,
        array $findings,
        array &$diagnostics,
    ): ?BaselineReport {
        $outputPath = $options->generateBaselinePath ?? BaselineStore::DEFAULT_FILENAME;

        try {
            // A generate at the shared default path never destroys a 0.5 baseline by accident; --force is the way to mean it.
            $store->requireOverwritableDefaultPath($outputPath, $options->shouldForceBaselineOverwrite);

            // A migration re-identifies the 0.5 reviews from this scan and writes them beside the original, which stays byte-identical.
            if ($options->migrateBaselinePath !== null) {
                $migration       = $store->migrate($options->migrateBaselinePath, $outputPath, $findings);
                $baseline        = $migration->writtenBaseline;
                $staleEvaluation = 'migrated';
            } else {
                $baseline        = $store->write($outputPath, $findings);
                $staleEvaluation = 'generated';
            }
        } catch (BaselineException $exception) {
            // Writing failed (permissions, a bad path, or a migration target that was the input); the reason is now a diagnostic the user reads.
            $diagnostics[] = new RunDiagnostic(
                type:    'baseline-error',
                message: $exception->getMessage(),
                path:    $outputPath,
            );

            return null;
        }

        // The conventional `gruff-baseline.json` counts as the default source, anything else as an explicit destination the user typed.
        return new BaselineReport(
            path:               $baseline->path,
            generated:          true,
            totalEntries:       count($baseline->entries),
            suppressedFindings: 0,
            staleEvaluation:    $staleEvaluation,
            source:             $outputPath === BaselineStore::DEFAULT_FILENAME ? BaselineReport::SOURCE_DEFAULT : BaselineReport::SOURCE_EXPLICIT,
            sensitiveCounted:   $baseline->sensitiveTotal(),
        );
    }

    /**
     * Applies an already-recorded baseline: reads it, hides the findings the user previously reviewed, and reports what moved.
     *
     * @param BaselineStore              $store - Store that reads the baseline file from disk.
     * @param BaselineApplicationOptions $options - Baseline path plus whether it was explicitly set or defaulted.
     * @param list<Finding>              $findings - Replaced with the gated set the user must act on.
     * @param DiffResult|null            $diff - When active, its diff scope stops resolved debt from being counted; null disables diff scope.
     * @param list<RunDiagnostic>        $diagnostics - Accumulator; a read failure appends a baseline-error entry, and each collision a warning.
     * @param bool                       $hasPartialScope - Whether files outside the scan scope cannot be marked resolved.
     *
     * @return BaselineReport|null - Report for the applied baseline; null when the file could not be read or applied and a diagnostic was logged instead.
     */
    private function applyExistingBaseline(
        BaselineStore $store,
        BaselineApplicationOptions $options,
        array &$findings,
        ?DiffResult $diff,
        array &$diagnostics,
        bool $hasPartialScope,
    ): ?BaselineReport {
        try {
            $baseline    = $store->read($options->baselinePath ?? '');
            $application = (new BaselineFilter())->apply($baseline, $findings, $hasPartialScope || ($diff instanceof DiffResult && $diff->active));
        } catch (BaselineException $exception) {
            // The file could not be read, validated, or applied (missing, malformed, 0.5 layout, written by another port); the reason is now a diagnostic.
            $diagnostics[] = new RunDiagnostic(
                type:    'baseline-error',
                message: $exception->getMessage(),
                path:    $options->baselinePath,
            );

            return null;
        }

        // Every collision is reported by name so the user can see which identity could not separate two declarations; none is hidden.
        foreach ($application['collisions'] as $collision) {
            $declarationCount = count($collision['subjects']);
            $collidingSubjects      = implode(', ', $collision['subjects']);

            $diagnostics[] = new RunDiagnostic(
                type:     'baseline-collision',
                message:  "collision: identity {$collision['identity']} covers {$declarationCount} declarations of {$collidingSubjects}"
                    . " for rule {$collision['ruleId']} in {$collision['path']}; none is suppressed",
                filePath: $collision['path'],
                isFatal:  false,
            );
        }

        $findings = $application['findings'];
        $report   = $application['report'];

        // Re-stamp the filter's report with this run's source classification (explicit vs default).
        return new BaselineReport(
            path:               $report->path,
            generated:          $report->generated,
            totalEntries:       $report->totalEntries,
            suppressedFindings: $report->suppressedFindings,
            staleEvaluation:    $report->staleEvaluation,
            staleEntries:       $report->staleEntries,
            source:             $options->isBaselineExplicit ? BaselineReport::SOURCE_EXPLICIT : BaselineReport::SOURCE_DEFAULT,
            newCount:           $report->newCount,
            unchangedCount:     $report->unchangedCount,
            absentCount:        $report->absentCount,
            collisionCount:     $report->collisionCount,
            notEligibleCount:   $report->notEligibleCount,
        );
    }
}
