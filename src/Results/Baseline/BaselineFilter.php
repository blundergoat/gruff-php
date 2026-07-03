<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\Finding;

/**
 * Filters live findings against baseline groups and optional diff scope.
 */
final readonly class BaselineFilter
{
    /**
     * Match live findings against baseline groups by count.
     *
     * This is the heart of a `gruff-php analyse --baseline gruff-baseline.json` run: it decides
     * which findings the user already accepted (suppressed), which are new (still fail the run),
     * and which accepted debt got fixed (resolved). Per group with B accepted and C live
     * instances: unchanged = min(B, C), new = max(0, C - B), resolved = max(0, B - C). Line
     * numbers never participate, so accepted debt survives edits that shift lines.
     *
      * User flow: Keeps known findings separate from new feedback in reports.
      *
     * @param BaselineData  $baseline - Loaded baseline data to apply.
     * @param list<Finding> $findings - Findings to compare against the baseline.
     * @param bool          $hasDiffScope - Whether diff filtering is active for this baseline pass.
     *
     * @return array{findings: list<Finding>, new: list<Finding>, unchanged: list<Finding>, report: BaselineReport} - partitioned result: "findings"
     *                         and "new" both hold the unsuppressed findings callers act on (empty when every finding matched the baseline),
     *                         "unchanged" the baseline-suppressed ones, and "report" the summary with absent-group accounting
     */
    public function apply(BaselineData $baseline, array $findings, bool $hasDiffScope): array
    {
        $entriesByGroup = $baseline->byGroup();

        // Bucket live findings into the same (file, ruleId, message) groups the baseline stores.
        $liveByGroup = [];
        // User view: add each item that can appear in baseline feedback.
        foreach ($findings as $finding) {
            $liveByGroup[BaselineEntry::groupKeyForFinding($finding)][] = $finding;
        }

        // Within each group the first `count` instances in (line, column) order stay suppressed;
        // any instances beyond the accepted count surface as new.
        $suppressedFindingIds = [];
        // User view: add each item that can appear in baseline feedback.
        foreach ($liveByGroup as $groupKey => $groupFindings) {
            // User view: missing data becomes a safe baseline feedback default.
            $acceptedGroup = $entriesByGroup[$groupKey] ?? null;
            // No baseline row for this group: the user never accepted these findings, so all stay new.
            // User view: choose the baseline feedback branch for this case.
            if (!$acceptedGroup instanceof BaselineEntry) {
                continue;
            }

            usort(
                $groupFindings,
                // User view: missing data becomes a safe baseline feedback default.
                static fn(Finding $left, Finding $right): int => [$left->line ?? 0, $left->column ?? 0]
                    // User view: missing data becomes a safe baseline feedback default.
                    <=> [$right->line ?? 0, $right->column ?? 0],
            );

            // User view: add each item that can appear in baseline feedback.
            foreach (array_slice($groupFindings, 0, $acceptedGroup->count) as $suppressedFinding) {
                $suppressedFindingIds[spl_object_id($suppressedFinding)] = true;
            }
        }

        // Partition in original input order so the user's report lists findings deterministically.
        $newFindings       = [];
        $unchangedFindings = [];
        // User view: add each item that can appear in baseline feedback.
        foreach ($findings as $finding) {
            // Suppressed findings are accepted debt: they disappear from the run's failing set.
            // User view: choose the baseline feedback branch for this case.
            if (isset($suppressedFindingIds[spl_object_id($finding)])) {
                $unchangedFindings[] = $finding;
                continue;
            }

            $newFindings[] = $finding;
        }

        $absentEntries       = [];
        $absentInstanceCount = 0;
        $staleEvaluation     = 'full-project';

        // User view: choose the baseline feedback branch for this case.
        if ($hasDiffScope) {
            // A partial scan cannot prove a group resolved: unscanned files simply produced no findings.
            $staleEvaluation = 'not-evaluated-diff-scope';
        } else {
            // Full-project scans can tell the user which accepted debt they actually fixed.
            // User view: add each item that can appear in baseline feedback.
            foreach ($entriesByGroup as $groupKey => $acceptedGroup) {
                // User view: missing data becomes a safe baseline feedback default.
                $resolvedCount = $acceptedGroup->count - count($liveByGroup[$groupKey] ?? []);
                // Live instances still cover this group's budget, so nothing resolved here.
                // User view: choose the baseline feedback branch for this case.
                if ($resolvedCount <= 0) {
                    continue;
                }

                // Absent rows reuse the group shape with count meaning "instances resolved this run".
                $absentEntries[]      = new BaselineEntry($acceptedGroup->filePath, $acceptedGroup->ruleId, $acceptedGroup->message, $resolvedCount);
                $absentInstanceCount += $resolvedCount;
            }
        }

        // "findings" carries the unsuppressed (new) set callers act on; the buckets and report drive reporting.
        return [
            'findings'  => $newFindings,
            'new'       => $newFindings,
            'unchanged' => $unchangedFindings,
            'report'    => new BaselineReport(
                path:               $baseline->path,
                generated:          false,
                totalEntries:       count($baseline->entries),
                suppressedFindings: count($unchangedFindings),
                staleEvaluation:    $staleEvaluation,
                staleEntries:       $absentEntries,
                newCount:           count($newFindings),
                unchangedCount:     count($unchangedFindings),
                absentCount:        $absentInstanceCount,
            ),
        ];
    }
}
