<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\Finding;

/**
 * Reconciles a fresh scan against the debt the user already accepted, so only genuinely new problems stop the run.
 *
 * This is the engine behind `gruff-php analyse --baseline gruff-baseline.json`: it keys every live
 * finding by (file, rule, message), matches each group against the counts the user previously signed
 * off, and splits the run into accepted debt (kept quiet), problems that are actually new (still
 * fail), and - on a full-project scan - accepted debt that has since been fixed. Matching is purely
 * count-based and ignores line numbers, so reformatting or moving code never resurrects debt the user
 * already accepted.
 */
final readonly class BaselineFilter
{
    /**
     * Sorts one scan's findings into accepted debt, genuinely new problems, and (on a full scan) debt
     * the user has since fixed. This is the per-group work every `gruff-php analyse --baseline
     * gruff-baseline.json` run does so the user is only stopped by findings they have not signed off.
     *
     * @param BaselineData  $baseline - The debt the user previously accepted, grouped by file, rule, and message.
     * @param list<Finding> $findings - This run's live findings to reconcile; empty means this run found nothing to match, so the findings/new/unchanged lists come back empty - though on a full scan the report then marks every accepted group resolved.
     * @param bool          $hasDiffScope - True when the scan covered only changed files, which turns off fixed-debt detection because unscanned files would look falsely resolved.
     *
     * @return array{findings: list<Finding>, new: list<Finding>, unchanged: list<Finding>, report: BaselineReport} - partitioned result: "findings"
     *                         and "new" both hold the unsuppressed findings the user must still fix (both empty when every finding matched accepted debt),
     *                         "unchanged" the baseline-suppressed ones, and "report" the summary with fixed-debt accounting.
     */
    public function apply(BaselineData $baseline, array $findings, bool $hasDiffScope): array
    {
        $entriesByGroup = $baseline->byGroup();

        $liveByGroup = [];
        // Group every live finding by the (file, rule, message) key the baseline uses, so each group can be matched by count alone.
        foreach ($findings as $finding) {
            $liveByGroup[BaselineEntry::groupKeyForFinding($finding)][] = $finding;
        }

        $suppressedFindingIds = [];
        // Within each group, keep the first `count` findings the user accepted (the smaller of accepted vs live) suppressed;
        // anything past that accepted budget is new debt that still fails the run.
        foreach ($liveByGroup as $groupKey => $groupFindings) {
            $acceptedGroup = $entriesByGroup[$groupKey] ?? null;
            // No baseline row for this group: the user never accepted these findings, so all stay new.
            if (!$acceptedGroup instanceof BaselineEntry) {
                continue;
            }

            usort(
                $groupFindings,
                static fn(Finding $left, Finding $right): int => [$left->line ?? 0, $left->column ?? 0]
                    <=> [$right->line ?? 0, $right->column ?? 0],
            );

            // Slice off that group's first `count` findings (or all of them, if fewer turned up) and mark them suppressed; anything past that accepted budget stays new.
            foreach (array_slice($groupFindings, 0, $acceptedGroup->count) as $suppressedFinding) {
                $suppressedFindingIds[spl_object_id($suppressedFinding)] = true;
            }
        }

        $newFindings       = [];
        $unchangedFindings = [];
        // Partition in original input order so the user's report lists findings deterministically.
        foreach ($findings as $finding) {
            // Suppressed findings are accepted debt: they disappear from the run's failing set.
            if (isset($suppressedFindingIds[spl_object_id($finding)])) {
                $unchangedFindings[] = $finding;
                continue;
            }

            $newFindings[] = $finding;
        }

        $absentEntries       = [];
        $absentInstanceCount = 0;
        $staleEvaluation     = 'full-project';

        // Only a full-project scan can prove accepted debt was fixed; a diff-scoped run (e.g. `analyse --baseline gruff-baseline.json --diff`) never opened the unchanged files, so it records the fixed-debt check as skipped rather than guess a resolution.
        if ($hasDiffScope) {
            $staleEvaluation = 'not-evaluated-diff-scope';
        } else {
            // A full-project scan saw every file, so walk each accepted group to find debt the user has since cleared.
            foreach ($entriesByGroup as $groupKey => $acceptedGroup) {
                $resolvedCount = $acceptedGroup->count - count($liveByGroup[$groupKey] ?? []);
                // Resolved is accepted-count minus still-live count; when live instances still fill the accepted budget the user fixed nothing here, so skip the group.
                if ($resolvedCount <= 0) {
                    continue;
                }

                // Record the cleared debt as an absent row - same group shape, but its count now means "instances the user resolved this run".
                $absentEntries[]      = new BaselineEntry($acceptedGroup->filePath, $acceptedGroup->ruleId, $acceptedGroup->message, $resolvedCount);
                $absentInstanceCount += $resolvedCount;
            }
        }

        // Hand back the new set the user still has to fix, both partition buckets, and the report that drives the reporters' multi-line Baseline block.
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
