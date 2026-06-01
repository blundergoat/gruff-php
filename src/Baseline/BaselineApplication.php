<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Finding;

/**
 * Applies baseline suppression to findings and returns the filtered report data.
 */
final readonly class BaselineApplication
{
    /**
     * Apply an existing baseline file without building report metadata.
     *
     * @param string        $projectRoot  Project root used to resolve the baseline path.
     * @param string        $baselinePath Baseline path to read.
     * @param list<Finding> $findings     Findings to filter.
     * @throws BaselineException When the baseline cannot be read or validated.
     * @return list<Finding> Filtered findings.
     */
    public function filterExisting(string $projectRoot, string $baselinePath, array $findings): array
    {
        $baseline = (new BaselineStore($projectRoot))->read($baselinePath);

        // Caller only wants the surviving findings here, so drop the report metadata the filter also returns.
        return (new BaselineFilter())->apply($baseline, $findings, false)['findings'];
    }

    /**
     * @param string                     $projectRoot Project root used to resolve baseline paths.
     * @param BaselineApplicationOptions $options     Baseline application options selected for this run.
     * @param list<Finding>              $findings    Findings to generate from or filter in place.
     * @param DiffResult|null            $diff        Diff scope used to preserve changed-line findings when present.
     * @param list<RunDiagnostic>        $diagnostics Diagnostics collected during baseline handling.
     * @return BaselineReport|null Baseline report when a baseline was generated or applied.
     */
    public function apply(
        string $projectRoot,
        BaselineApplicationOptions $options,
        array &$findings,
        ?DiffResult $diff,
        array &$diagnostics,
    ): ?BaselineReport {
        $baselineStore = new BaselineStore($projectRoot);

        if ($options->generateBaselinePath !== null) {
            // Generate mode takes precedence: write a fresh baseline rather than filter against one.
            return $this->generate($baselineStore, $options->generateBaselinePath, $findings, $diagnostics);
        }

        if ($options->baselinePath === null) {
            // No baseline configured, so the run carries no baseline report.
            return null;
        }

        // Otherwise apply the configured baseline, suppressing matched findings in place.
        return $this->applyExistingBaseline(
            store:       $baselineStore,
            options:     $options,
            findings:    $findings,
            diff:        $diff,
            diagnostics: $diagnostics,
        );
    }

    /**
     * @param BaselineStore       $store                Store that writes and locates the baseline file.
     * @param string              $generateBaselinePath Destination path to write the new baseline to.
     * @param list<Finding>       $findings             Findings to record as the new baseline snapshot.
     * @param list<RunDiagnostic> $diagnostics          Accumulator; a write failure appends a baseline-error entry.
     * @return BaselineReport|null Generated baseline report, or null when writing fails.
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

            // Write failed; the error is recorded as a diagnostic, so signal "no baseline report".
            return null;
        }

        // Baseline written: report it as generated, with no findings suppressed on a generate run.
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
     * @param BaselineStore              $store       Store that reads the baseline file from disk.
     * @param BaselineApplicationOptions $options     Baseline path plus whether it was explicitly set or defaulted.
     * @param list<Finding>              $findings    Filtered in place; replaced with the surviving (unmatched) set.
     * @param DiffResult|null            $diff        Changed-line findings stay unsuppressed; null disables diff scope.
     * @param list<RunDiagnostic>        $diagnostics Accumulator; a read failure appends a baseline-error entry.
     * @return BaselineReport|null Applied baseline report, or null when reading fails.
     */
    private function applyExistingBaseline(
        BaselineStore $store,
        BaselineApplicationOptions $options,
        array &$findings,
        ?DiffResult $diff,
        array &$diagnostics,
    ): ?BaselineReport {
        try {
            $baseline    = $store->read($options->baselinePath ?? '');
            $application = (new BaselineFilter())->apply($baseline, $findings, $diff instanceof DiffResult && $diff->active);
        } catch (BaselineException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type:    'baseline-error',
                message: $exception->getMessage(),
                path:    $options->baselinePath,
            );

            // Read/parse failed; the error is recorded as a diagnostic, so signal "no baseline report".
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
