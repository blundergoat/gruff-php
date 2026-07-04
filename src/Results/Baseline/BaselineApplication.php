<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;

/**
 * Decides how one analysis run meets the project baseline - the recorded set of findings a user has
 * already reviewed and accepted as known debt. When someone runs `gruff-php analyse`, this step
 * branches three ways: write a fresh baseline (`--generate-baseline`), skip baselining so every
 * finding is reported, or apply an existing `gruff-baseline.json` and suppress the already-accepted
 * findings so only new or changed problems surface. Hiding signed-off findings keeps the run focused
 * on what actually moved; the generate and apply paths return a `BaselineReport` describing what was written or suppressed, while skipping (or a read/write failure) returns null.
 */
final readonly class BaselineApplication
{
    /**
     * Reads a baseline and hands back only the findings it does not already cover - the shortcut
     * callers use when they want the accepted findings dropped but none of the report accounting.
     *
     * @param string        $projectRoot - Project root used to resolve the baseline path.
     * @param string        $baselinePath - Baseline path to read, relative to the project root when not absolute.
     * @param list<Finding> $findings - Live findings to test against the baseline before reporting.
     * @throws BaselineException When the baseline cannot be read or validated, so the caller can surface a config error.
     *
     * @return list<Finding> - The findings not already accepted; empty when the baseline covered every one of them.
     */
    public function filterExisting(string $projectRoot, string $baselinePath, array $findings): array
    {
        $baseline = (new BaselineStore($projectRoot))->read($baselinePath);

        return (new BaselineFilter())->apply($baseline, $findings, false)['findings'];
    }

    /**
     * The entry point every analyse run funnels through: pick the one baseline action the user's flags
     * asked for - generate, skip, or apply - and reshape the findings list to match before reporting.
     *
     * @param string                     $projectRoot - Project root used to resolve baseline paths.
     * @param BaselineApplicationOptions $options - Baseline flags for this run: generate, apply, or skip, plus the path.
     * @param list<Finding>              $findings - Findings for this run; filtered in place when an existing baseline is applied.
     * @param DiffResult|null            $diff - An active diff limits the run to changed lines, so accepted debt is not marked resolved; null means no diff scope, though whether absent entries get resolved still depends on `$hasPartialScope`.
     * @param list<RunDiagnostic>        $diagnostics - Accumulator; a read or write failure appends a baseline-error entry the user sees.
     * @param bool                       $hasPartialScope - Whether the run scanned only part of the project, so absent baseline entries are not evaluated.
     *
     * @return BaselineReport|null - Report describing the generated or applied baseline; null when no baseline was configured, or when a generate/apply hit a read or write failure (a baseline-error diagnostic is recorded).
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

        // The user asked to capture the current state (`gruff-php analyse --generate-baseline`), so
        // write a fresh baseline instead of comparing against one - generation always wins.
        if ($options->generateBaselinePath !== null) {
            return $this->generate($baselineStore, $options->generateBaselinePath, $findings, $diagnostics);
        }

        // No baseline in play (none configured, or the user passed `--no-baseline`), so every finding
        // stands and the run carries no baseline section at all.
        if ($options->baselinePath === null) {
            return null;
        }

        // A baseline is configured (e.g. `gruff-php analyse --baseline gruff-baseline.json`), so apply
        // it and drop the findings the user already accepted from this run's failing set.
        return $this->applyExistingBaseline(
            store:       $baselineStore,
            options:     $options,
            findings:    $findings,
            diff:        $diff,
            diagnostics: $diagnostics,
            hasPartialScope: $hasPartialScope,
        );
    }

    /**
     * Snapshots the current findings to a new baseline file so the user can accept today's results as
     * the agreed starting point - the `--generate-baseline` path, where nothing is suppressed yet.
     *
     * @param BaselineStore       $store - Store that writes and locates the baseline file.
     * @param string              $generateBaselinePath - Destination path to write the new baseline to.
     * @param list<Finding>       $findings - Findings to record as the new baseline snapshot.
     * @param list<RunDiagnostic> $diagnostics - Accumulator; a write failure appends a baseline-error entry the user sees.
     *
     * @return BaselineReport|null - Report for the freshly written baseline; null when the write failed and a diagnostic was logged instead.
     */
    private function generate(
        BaselineStore $store,
        string $generateBaselinePath,
        array $findings,
        array &$diagnostics,
    ): ?BaselineReport {
        try {
            $baseline = $store->write($generateBaselinePath, $findings);
        } catch (BaselineException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'baseline-error',
                message: $exception->getMessage(),
                path:    $generateBaselinePath,
            );

            // Writing the file failed (permissions, a bad path); the reason is now a diagnostic the
            // user will read, so report no baseline rather than a half-written one.
            return null;
        }

        // Tag the new baseline's source by its path: the conventional `gruff-baseline.json` counts as
        // the default, anything else as an explicit destination the user typed.
        return new BaselineReport(
            path:               $baseline->path,
            generated:          true,
            totalEntries:       count($baseline->entries),
            suppressedFindings: 0,
            staleEvaluation:    'generated',
            source:             $generateBaselinePath === BaselineStore::DEFAULT_FILENAME
                ? BaselineReport::SOURCE_DEFAULT
                : BaselineReport::SOURCE_EXPLICIT,
        );
    }

    /**
     * Applies an already-recorded baseline: reads it, drops the findings the user previously accepted,
     * and reports what stayed new, unchanged, or resolved since the baseline was captured.
     *
     * @param BaselineStore              $store - Store that reads the baseline file from disk.
     * @param BaselineApplicationOptions $options - Baseline path plus whether it was explicitly set or defaulted.
     * @param list<Finding>              $findings - Filtered in place; replaced with the surviving (still-failing) set the user must act on.
     * @param DiffResult|null            $diff - When active, its diff scope stops resolved debt from being counted; null disables diff scope, though full-project resolution still hinges on `$hasPartialScope`.
     * @param list<RunDiagnostic>        $diagnostics - Accumulator; a read failure appends a baseline-error entry the user sees.
     * @param bool                       $hasPartialScope - Whether files outside the scan scope cannot be marked absent/resolved.
     *
     * @return BaselineReport|null - Report for the applied baseline; null when the file could not be read and a diagnostic was logged instead.
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
            $diagnostics[] = new RunDiagnostic(
                type:    'baseline-error',
                message: $exception->getMessage(),
                path:    $options->baselinePath,
            );

            // The baseline file could not be read or validated (missing, malformed, wrong schema); the
            // reason is now a diagnostic, so report no baseline rather than silently suppressing nothing.
            return null;
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
        );
    }
}
