<?php

declare(strict_types=1);

namespace GruffPhp\Hook;

use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Finding;

/**
 * Applies the agent-hook changed-region and new-only contract.
 */
final readonly class HookFindingFilter
{
    /**
     * Filter findings for hook output.
     *
     * @param list<Finding>       $findings             - Current findings from a native analysis pass.
     * @param DiffResult|null     $changedRegion        - Changed-region data, or null/inactive for full-scan hook output.
     * @param array<string, true> $baseStableIdentities - Stable identities present in the baseline/base ref.
     * @param bool                $hasNewOnlySource     - Whether --baseline or a comparable --diff base was supplied.
     *
     * @return HookFilterResult - kept findings and suppression count.
     */
    public function apply(
        array $findings,
        ?DiffResult $changedRegion,
        array $baseStableIdentities,
        bool $hasNewOnlySource,
    ): HookFilterResult {
        $kept            = [];
        $suppressedCount = 0;

        foreach ($findings as $finding) {
            $scope = HookFindingScope::classify($finding);
            $isNew = !isset($baseStableIdentities[HookFindingIdentity::forFinding($finding, $scope)]);

            if ($hasNewOnlySource && !$isNew) {
                $suppressedCount++;
                continue;
            }

            if (!$changedRegion instanceof DiffResult || !$changedRegion->active) {
                $kept[] = $finding;
                continue;
            }

            if ($scope === HookFindingScope::FILE || $scope === HookFindingScope::PROJECT) {
                if ($hasNewOnlySource) {
                    $kept[] = $finding;
                    continue;
                }

                $suppressedCount++;
                continue;
            }

            if ($this->intersectsChangedRegion($finding, $changedRegion)) {
                $kept[] = $finding;
                continue;
            }

            $suppressedCount++;
        }

        return new HookFilterResult($kept, $suppressedCount);
    }

    /**
     * Check whether a line/symbol finding intersects a changed region.
     *
     * @param Finding    $finding       - Native finding.
     * @param DiffResult $changedRegion - Changed-region data.
     *
     * @return bool - True when the finding is attributable to the changed region.
     */
    private function intersectsChangedRegion(Finding $finding, DiffResult $changedRegion): bool
    {
        if (!in_array($finding->filePath, $changedRegion->changedFiles, true)) {
            return false;
        }

        $ranges = $changedRegion->rangesFor($finding->filePath);
        if ($ranges === []) {
            return true;
        }

        $line = $finding->line;
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
