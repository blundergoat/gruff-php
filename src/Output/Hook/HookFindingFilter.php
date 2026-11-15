<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;

/**
 * Filters hook findings by optional base-identity and changed-region checks; identities absent from the base are treated as new.
 * Active regions require new-only mode for file/project findings; line/symbol findings need a changed file with no ranges or span overlap.
 * Absent or inactive regions skip location checks; any enabled base-identity suppression still applies.
 */
final readonly class HookFindingFilter
{
    /**
     * Applies optional identity and changed-region filters and counts every excluded input finding.
     * Returns identities for the complete input set, including findings that were suppressed.
     *
     * @param list<Finding> $findings - Findings from one analysis pass; empty input yields no kept findings and a zero suppressed count.
     * @param DiffResult|null $changedRegion - Changed files and ranges; null or inactive skips region filtering after the new-only check.
     * @param array<string, true> $baseStableIdentities - Base identities; empty means no input identity is recognized as pre-existing.
     * @param bool $hasNewOnlySource - Enables base-identity suppression and allows new file/project findings in active region mode.
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


        foreach ($findings as $finding) {
            $scope = HookFindingScope::classify($finding);
            $isNew = !isset($baseStableIdentities[$identities[spl_object_id($finding)] ?? HookFindingIdentity::forFinding($finding, $scope)]);


            if ($hasNewOnlySource && !$isNew) {
                $suppressedCount++;
                continue;
            }

            // Without an active region, no location restriction remains after the identity check.
            if (!$changedRegion instanceof DiffResult || !$changedRegion->active) {
                $kept[] = $finding;
                continue;
            }

            // Line overlap cannot establish eligibility for broader scopes; an identity comparison is required.
            if ($scope === HookFindingScope::FILE || $scope === HookFindingScope::PROJECT) {

                if ($hasNewOnlySource) {
                    $kept[] = $finding;
                    continue;
                }


                $suppressedCount++;
                continue;
            }


            if ($this->touchesChangedRegion($finding, $changedRegion)) {
                $kept[] = $finding;
                continue;
            }


            $suppressedCount++;
        }

        return new HookFilterResult($kept, $suppressedCount, $identities);
    }

    /**
     * Checks changed-file membership and overlap with recorded line ranges.
     * Listed files without ranges match even without line metadata; with ranges, a null start line cannot match.
     * A null end line uses the finding's start line.
     *
     * @param Finding    $finding       - The finding under test, whose file and line span are matched against the diff.
     * @param DiffResult $changedRegion - Active changed-region data holding the edited files and their changed line ranges.
     *
     * @return bool - True if the file is listed and either has no ranges or a finding span overlaps a recorded range.
     */
    private function touchesChangedRegion(Finding $finding, DiffResult $changedRegion): bool
    {

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


        foreach ($ranges as $range) {

            if ($range->touches($line, $endLine)) {
                return true;
            }
        }

        return false;
    }
}
