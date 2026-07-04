<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;

/**
 * Decides which findings an AI coding agent sees after it edits code, so a `gruff-php hook` run
 * reports the problems the edit just introduced rather than the whole project's pre-existing debt.
 *
 * Two filters combine here. The new-only filter drops findings already present in the baseline or
 * base ref, leaving only what this edit created. The changed-region filter keeps a line- or
 * symbol-scoped finding only when it lands on a line the diff touched; a whole-file or whole-project
 * finding, which has no single line to match against the diff, survives only when the new-only filter
 * has already proven it new. The user reaches this path by running the hook with `--diff`, `--since`,
 * `--changed-ranges`, or `--baseline`; without any of those the run stays a full scan and every finding flows through.
 */
final readonly class HookFindingFilter
{
    /**
     * Runs both hook filters over one analysis pass and returns the findings worth showing the agent,
     * counting the rest as suppressed. Called once per `gruff-php hook` invocation, right after the
     * rules run and just before the kept findings are rendered back to the agent.
     *
     * @param list<Finding>       $findings             - Findings from this analysis pass; empty when the run found nothing, giving an empty kept set.
     * @param DiffResult|null     $changedRegion        - Changed-line ranges for the run; null or inactive when no `--diff`/`--since`/`--changed-ranges` was given, which skips changed-region filtering and keeps every finding that clears the new-only check.
     * @param array<string, true> $baseStableIdentities - Stable identities already seen in the baseline or base ref; empty when there is no such source, so nothing counts as pre-existing.
     * @param bool                $hasNewOnlySource     - Whether a `--baseline` or comparable `--diff`/`--since` base was supplied; true switches on new-only suppression of pre-existing findings.
     *
     * @return HookFilterResult - The kept findings, the suppressed-finding count, and the disambiguated identities for the whole input set.
     */
    public function apply(
        array $findings,
        ?DiffResult $changedRegion,
        array $baseStableIdentities,
        bool $hasNewOnlySource,
    ): HookFilterResult {
        $identities      = HookFindingIdentity::forFindings($findings);
        $kept            = [];
        $suppressedCount = 0;

        // Walk every finding this run produced and decide, one at a time, whether it belongs in the agent's feedback or is just noise from code the edit never touched.
        foreach ($findings as $finding) {
            $scope = HookFindingScope::classify($finding);
            $isNew = !isset($baseStableIdentities[$identities[spl_object_id($finding)] ?? HookFindingIdentity::forFinding($finding, $scope)]);

            // The run has a baseline to compare against and this exact issue already existed there, so it is not the agent's doing; drop it and only tally it as suppressed.
            if ($hasNewOnlySource && !$isNew) {
                $suppressedCount++;
                continue;
            }

            // No active diff was supplied (a plain `gruff-php hook` with no `--diff`), so there is no edited region to line up against; keep whatever cleared the new-only check.
            if (!$changedRegion instanceof DiffResult || !$changedRegion->active) {
                $kept[] = $finding;
                continue;
            }

            // This finding describes a whole file or the whole project, so it has no single line to match against the changed region.
            if ($scope === HookFindingScope::FILE || $scope === HookFindingScope::PROJECT) {
                // New-only mode already proved this whole-file or whole-project issue is freshly introduced, so surface it even though no line pins it to the diff.
                if ($hasNewOnlySource) {
                    $kept[] = $finding;
                    continue;
                }

                // Without a baseline we cannot tell whether this file- or project-wide issue is new, and it maps to no changed line, so hold it back as probable pre-existing noise.
                $suppressedCount++;
                continue;
            }

            // A line- or symbol-scoped finding: keep it when it sits on one of the lines the diff changed, since that is code the agent just wrote.
            if ($this->touchesChangedRegion($finding, $changedRegion)) {
                $kept[] = $finding;
                continue;
            }

            // It doesn't sit on a changed line - its file wasn't in the diff, or the finding is off the edited lines - so the agent didn't write it this pass; leave it out.
            $suppressedCount++;
        }

        return new HookFilterResult($kept, $suppressedCount, $identities);
    }

    /**
     * Tests whether one line- or symbol-scoped finding overlaps the edited lines, which is what lets
     * the changed-region filter keep only the findings sitting on code the agent actually touched.
     *
     * @param Finding    $finding       - The finding under test, whose file and line span are matched against the diff.
     * @param DiffResult $changedRegion - Active changed-region data holding the edited files and their changed line ranges.
     *
     * @return bool - True when the finding falls on a changed file and line, so it is attributed to the edit; false leaves it suppressed.
     */
    private function touchesChangedRegion(Finding $finding, DiffResult $changedRegion): bool
    {
        // The finding's file was not among the ones the edit changed, so it cannot belong to this diff; reject it outright.
        if (!in_array($finding->filePath, $changedRegion->changedFiles, true)) {
            return false;
        }

        $ranges = $changedRegion->rangesFor($finding->filePath);
        // The file changed but carries no specific line ranges (a whole-file change), so treat every line as in scope and keep the finding.
        if ($ranges === []) {
            return true;
        }

        $line = $finding->line;
        // We have concrete ranges but the finding carries no line to test against them, so it cannot be pinned to the edit; leave it out.
        if ($line === null) {
            return false;
        }

        $endLine = $finding->endLine ?? $line;

        // Compare the finding's line span against each changed range in the file, looking for any overlap.
        foreach ($ranges as $range) {
            // The finding's span meets this edited range, so it sits on code the agent just changed; keep it.
            if ($range->touches($line, $endLine)) {
                return true;
            }
        }

        return false;
    }
}
