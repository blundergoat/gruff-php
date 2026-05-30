<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

use GruffPhp\Finding\Finding;

/**
 * Filters live findings against baseline entries and optional diff scope.
 */
final readonly class BaselineFilter
{
    /**
     * @param BaselineData  $baseline     Loaded baseline data to apply.
     * @param list<Finding> $findings     Findings to compare against the baseline.
     * @param bool          $hasDiffScope Whether diff filtering is active for this baseline pass.
     * @return array{findings: list<Finding>, new: list<Finding>, unchanged: list<Finding>, report: BaselineReport}
     */
    public function apply(BaselineData $baseline, array $findings, bool $hasDiffScope): array
    {
        $entriesByFingerprint = $baseline->byFingerprint();
        $matchedFingerprints  = [];
        $newFindings          = [];
        $unchangedFindings    = [];

        foreach ($findings as $finding) {
            $fingerprint = $finding->fingerprint();
            $entry       = $entriesByFingerprint[$fingerprint] ?? null;

            if ($entry instanceof BaselineEntry
                && $entry->ruleId === $finding->ruleId
                && $entry->filePath === $finding->filePath
            ) {
                $matchedFingerprints[$fingerprint] = true;
                $unchangedFindings[]               = $finding;
                continue;
            }

            $newFindings[] = $finding;
        }

        $absentEntries   = [];
        $staleEvaluation = 'full-project';

        if ($hasDiffScope) {
            $staleEvaluation = 'not-evaluated-diff-scope';
        } else {
            foreach ($baseline->entries as $entry) {
                if (!isset($matchedFingerprints[$entry->fingerprint])) {
                    $absentEntries[] = $entry;
                }
            }
        }

        return [
            'findings'  => $newFindings,
            'new'       => $newFindings,
            'unchanged' => $unchangedFindings,
            'report' => new BaselineReport(
                path:               $baseline->path,
                generated:          false,
                totalEntries:       count($baseline->entries),
                suppressedFindings: count($unchangedFindings),
                staleEvaluation:    $staleEvaluation,
                staleEntries:       $absentEntries,
                newCount:           count($newFindings),
                unchangedCount:     count($unchangedFindings),
                absentCount:        count($absentEntries),
            ),
        ];
    }
}
