<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\DeclarationInventory;
use GruffPhp\Results\Finding\Finding;

/**
 * Coordinates baseline generation, migration, and suppression for one analysis run.
 *
 * Generation records the current findings; migration carries matching 0.5 reviews into a separate file.
 * Applying an existing baseline removes covered findings; no configured path leaves the findings unchanged.
 *
 * `apply()` returns a report, or null when skipped or after recording a baseline-error diagnostic.
 * `filterExisting()` returns unsuppressed findings and lets baseline errors propagate.
 */
final readonly class BaselineApplication
{
    /**
     * Reads a baseline and hands back only the findings it does not already cover, with none of the report accounting.
     *
     * @param string        $projectRoot - Project root used to resolve the baseline path.
     * @param string        $baselinePath - Baseline path to read, relative to the project root when not absolute.
     * @param list<Finding> $findings - Live findings to test against the baseline before reporting.
     * @param DeclarationInventory|null $inventory - Scanned occurrences used for ordinals; null ranks only the supplied findings.
     *
     * @return list<Finding> - The gated findings; empty when the baseline covered every one of them.
     * @throws BaselineException - If the file is unreadable or invalid, belongs to another port, or a finding cannot be identified.
     */
    public function filterExisting(string $projectRoot, string $baselinePath, array $findings, ?DeclarationInventory $inventory = null): array
    {
        $baseline = (new BaselineStore($projectRoot))->read($baselinePath);

        return (new BaselineFilter())->apply($baseline, $findings, false, $inventory)['findings'];
    }

    /**
     * Picks the one baseline action the user's flags asked for and reshapes the findings list to match before reporting.
     *
     * @param string $projectRoot - Root used to resolve baseline paths.
     * @param BaselineApplicationOptions $options - Generation, migration, and application options; generation takes precedence.
     * @param list<Finding> $findings - Replaced with unsuppressed findings when an existing baseline is applied.
     * @param DiffResult|null $diff - Active diff skips resolved-entry checks; null or inactive does not.
     * @param list<RunDiagnostic> $diagnostics - Receives baseline errors and collision warnings.
     * @param bool $hasPartialScope - True to skip resolved-entry checks for an incomplete scan.
     *
     * @return BaselineReport|null - Report for a successful action; null if skipped or if a baseline error is recorded.
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
            return $this->generate($baselineStore, $options, $findings, $diagnostics, DeclarationInventory::forFindings($projectRoot, $findings));
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
            inventory:       DeclarationInventory::forFindings($projectRoot, $findings),
        );
    }

    /**
     * Writes a new baseline, from the current findings alone or carried across from a 0.5 file the user is migrating.
     *
     * @param BaselineStore $store - Store that writes and locates the baseline file.
     * @param BaselineApplicationOptions $options - Output path and optional 0.5 migration input.
     * @param list<Finding> $findings - Findings to record; migration retains only those covered by the 0.5 rows.
     * @param list<RunDiagnostic> $diagnostics - Receives a baseline-error entry when validation, migration, or writing fails.
     * @param DeclarationInventory|null $inventory - Scanned occurrences used for ordinals; null ranks only the supplied findings.
     *
     * @return BaselineReport|null - Written-baseline report; null when a caught baseline error is recorded as a diagnostic.
     */
    private function generate(
        BaselineStore $store,
        BaselineApplicationOptions $options,
        array $findings,
        array &$diagnostics,
        ?DeclarationInventory $inventory,
    ): ?BaselineReport {
        $outputPath = $options->generateBaselinePath ?? BaselineStore::DEFAULT_FILENAME;

        try {
            // Generation protects older schemas at the default filename unless --force authorizes overwriting them.
            $store->requireOverwritableDefaultPath($outputPath, $options->shouldForceBaselineOverwrite);

            // A migration re-identifies the 0.5 reviews from this scan and writes them beside the original, which stays byte-identical.
            if ($options->migrateBaselinePath !== null) {
                $migration       = $store->migrate($options->migrateBaselinePath, $outputPath, $findings, $inventory);
                $baseline        = $migration->writtenBaseline;
                $staleEvaluation = 'migrated';
            } else {
                $baseline        = $store->write($outputPath, $findings, $inventory);
                $staleEvaluation = 'generated';
            }
        } catch (BaselineException $exception) {
            // A refused destination or failed migration/write becomes a baseline-error diagnostic instead of a report.
            $diagnostics[] = new RunDiagnostic(
                type:    'baseline-error',
                message: $exception->getMessage(),
                path:    $outputPath,
            );

            return null;
        }

        // The conventional "gruff-baseline.json" counts as the default source, anything else as an explicit destination the user typed.
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
     * @param BaselineStore $store - Store that reads the baseline file from disk.
     * @param BaselineApplicationOptions $options - Baseline path and its explicit or default source classification.
     * @param list<Finding> $findings - Replaced with the unsuppressed findings after successful application.
     * @param DiffResult|null $diff - Active diff skips resolved-entry checks; null or inactive does not.
     * @param list<RunDiagnostic> $diagnostics - Receives baseline read/matching errors and collision warnings.
     * @param bool $hasPartialScope - True to skip resolved-entry checks for an incomplete scan.
     * @param DeclarationInventory|null $inventory - Scanned occurrences used for ordinals; null ranks only the supplied findings.
     *
     * @return BaselineReport|null - Applied-baseline report; null when a caught baseline error is recorded as a diagnostic.
     */
    private function applyExistingBaseline(
        BaselineStore $store,
        BaselineApplicationOptions $options,
        array &$findings,
        ?DiffResult $diff,
        array &$diagnostics,
        bool $hasPartialScope,
        ?DeclarationInventory $inventory,
    ): ?BaselineReport {
        try {
            $baseline    = $store->read($options->baselinePath ?? '');
            $application = (new BaselineFilter())->apply($baseline, $findings, $hasPartialScope || ($diff instanceof DiffResult && $diff->active), $inventory);
        } catch (BaselineException $exception) {
            // A baseline read, validation, or matching failure becomes a baseline-error diagnostic instead of escaping this boundary.
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
