<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;

/**
 * Applies the agent-hook changed-region and new-only contract.
 */
final readonly class HookFindingFilter
{
    /**
     * Filter findings for hook output.
     *
      * User flow: Shapes hook feedback before a developer continues their workflow.
      *
     * @param list<Finding>       $findings             - Current findings from a native analysis pass.
     * @param DiffResult|null     $changedRegion        - Changed-region data, or null/inactive for full-scan hook output.
     * @param array<string, true> $baseStableIdentities - Stable identities present in the baseline/base ref.
     * @param bool                $hasNewOnlySource     - Whether --baseline or a comparable --diff base was supplied.
     *
     * @return HookFilterResult - kept findings, suppression count, and disambiguated identities for the input set.
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

        // User view: add each item that can appear in hook output.
        foreach ($findings as $finding) {
            $scope = HookFindingScope::classify($finding);
            // User view: missing data becomes a safe hook output default.
            $isNew = !isset($baseStableIdentities[$identities[spl_object_id($finding)] ?? HookFindingIdentity::forFinding($finding, $scope)]);

            // User view: choose the hook output branch for this case.
            if ($hasNewOnlySource && !$isNew) {
                $suppressedCount++;
                continue;
            }

            // User view: choose the hook output branch for this case.
            if (!$changedRegion instanceof DiffResult || !$changedRegion->active) {
                $kept[] = $finding;
                continue;
            }

            // User view: choose the hook output branch for this case.
            if ($scope === HookFindingScope::FILE || $scope === HookFindingScope::PROJECT) {
                // User view: choose the hook output branch for this case.
                if ($hasNewOnlySource) {
                    $kept[] = $finding;
                    continue;
                }

                $suppressedCount++;
                continue;
            }

            // User view: choose the hook output branch for this case.
            if ($this->touchesChangedRegion($finding, $changedRegion)) {
                $kept[] = $finding;
                continue;
            }

            $suppressedCount++;
        }

        return new HookFilterResult($kept, $suppressedCount, $identities);
    }

    /**
     * Check whether a line/symbol finding intersects a changed region.
     *
      * User flow: Shapes hook feedback before a developer continues their workflow.
      *
     * @param Finding    $finding       - Native finding.
     * @param DiffResult $changedRegion - Changed-region data.
     *
     * @return bool - True when the finding is attributable to the changed region.
     */
    private function touchesChangedRegion(Finding $finding, DiffResult $changedRegion): bool
    {
        // User view: choose the hook output branch for this case.
        if (!in_array($finding->filePath, $changedRegion->changedFiles, true)) {
            return false;
        }

        $ranges = $changedRegion->rangesFor($finding->filePath);
        // User view: choose the hook output branch for this case.
        // User view: an empty value becomes a clear hook output fallback.
        if ($ranges === []) {
            return true;
        }

        $line = $finding->line;
        // User view: choose the hook output branch for this case.
        // User view: missing data becomes the expected hook output state.
        if ($line === null) {
            return false;
        }

        // User view: missing data becomes a safe hook output default.
        $endLine = $finding->endLine ?? $line;

        // User view: add each item that can appear in hook output.
        foreach ($ranges as $range) {
            // User view: choose the hook output branch for this case.
            if ($range->touches($line, $endLine)) {
                return true;
            }
        }

        return false;
    }
}
