<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Finding;

final readonly class BaselineApplication
{
    /**
     * @param list<Finding> $findings
     * @param list<RunDiagnostic> $diagnostics
     * @return BaselineReport|null Baseline report when a baseline was generated or applied.
     */
    public function apply(
        string $projectRoot,
        BaselineApplicationOptions $options,
        array &$findings,
        ?DiffResult $diff,
        array &$diagnostics,
    ): ?BaselineReport {
        $store = new BaselineStore($projectRoot);

        if ($options->generateBaselinePath !== null) {
            return $this->generate($store, $options->generateBaselinePath, $findings, $diagnostics);
        }

        if ($options->baselinePath === null) {
            return null;
        }

        return $this->applyExistingBaseline($store, $options, $findings, $diff, $diagnostics);
    }

    /**
     * @param list<Finding> $findings
     * @param list<RunDiagnostic> $diagnostics
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
                type: 'baseline-error',
                message: $exception->getMessage(),
                path: $generateBaselinePath,
            );

            return null;
        }

        return new BaselineReport(
            path: $baseline->path,
            generated: true,
            totalEntries: count($baseline->entries),
            suppressedFindings: 0,
            staleEvaluation: 'generated',
            source: $generateBaselinePath === BaselineStore::DEFAULT_FILENAME
                ? BaselineReport::SOURCE_DEFAULT
                : BaselineReport::SOURCE_EXPLICIT,
        );
    }

    /**
     * @param list<Finding> $findings
     * @param list<RunDiagnostic> $diagnostics
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
            $baseline = $store->read($options->baselinePath ?? '');
            $application = (new BaselineFilter())->apply($baseline, $findings, $diff instanceof DiffResult && $diff->active);
        } catch (BaselineException $exception) {
            $diagnostics[] = new RunDiagnostic(
                type: 'baseline-error',
                message: $exception->getMessage(),
                path: $options->baselinePath,
            );

            return null;
        }

        $findings = $application['findings'];
        $report = $application['report'];

        return new BaselineReport(
            path: $report->path,
            generated: $report->generated,
            totalEntries: $report->totalEntries,
            suppressedFindings: $report->suppressedFindings,
            staleEvaluation: $report->staleEvaluation,
            staleEntries: $report->staleEntries,
            source: $options->baselineExplicit ? BaselineReport::SOURCE_EXPLICIT : BaselineReport::SOURCE_DEFAULT,
        );
    }
}
