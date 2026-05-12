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
     * @param list<Finding> $findings
     * @return array{findings: list<Finding>, report: BaselineReport}
     */
    public function apply(BaselineData $baseline, array $findings, bool $diffScope): array
    {
        $entriesByFingerprint = $baseline->byFingerprint();
        $matchedFingerprints = [];
        $filtered = [];
        $suppressed = 0;

        foreach ($findings as $finding) {
            $fingerprint = $finding->fingerprint();
            $entry = $entriesByFingerprint[$fingerprint] ?? null;

            if ($entry instanceof BaselineEntry
                && $entry->ruleId === $finding->ruleId
                && $entry->filePath === $finding->filePath
            ) {
                $matchedFingerprints[$fingerprint] = true;
                $suppressed++;
                continue;
            }

            $filtered[] = $finding;
        }

        $staleEntries = [];
        $staleEvaluation = 'full-project';

        if ($diffScope) {
            $staleEvaluation = 'not-evaluated-diff-scope';
        } else {
            foreach ($baseline->entries as $entry) {
                if (!isset($matchedFingerprints[$entry->fingerprint])) {
                    $staleEntries[] = $entry;
                }
            }
        }

        return [
            'findings' => $filtered,
            'report' => new BaselineReport(
                path: $baseline->path,
                generated: false,
                totalEntries: count($baseline->entries),
                suppressedFindings: $suppressed,
                staleEvaluation: $staleEvaluation,
                staleEntries: $staleEntries,
            ),
        ];
    }
}
