<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Results\Diff\DiffFindingFilter;
use GruffPhp\Results\Diff\DiffResult;
use GruffPhp\Results\Finding\Finding;

/**
 * Selects current findings for the JSON returned by `gruff-php hook`.
 *
 * Uses the caller's changed scope to decide whether a finding is inside the edits under review.
 * Findings already present in the Git comparison are omitted from this result.
 */
final readonly class HookFindingFilter
{
    /**
     * Applies the requested review scope and Git comparison before the hook presents or gates its findings.
     * Counts findings excluded from that view so the caller can account for them.
     *
     * @param list<Finding>       $findings             - Current findings before scope filtering. Empty means the hook has no findings to compare.
     * @param DiffResult|null     $changedRegion        - Changed paths and ranges, or null for a full run.
     * @param array<string, true> $baseStableIdentities - Git comparison identities. Empty means no finding is excluded by the comparison.
     * @param bool                $hasNewOnlySource     - Whether a baseline or Git comparison can classify broad findings.
     * @param list<AnalysisUnit>  $analysisUnits        - Parsed units used to recover declaration spans in symbol scope.
     * @param string              $changedScope         - Requested symbol, hunk, or file scope.
     *
     * @return HookFilterResult - Kept findings, excluded count, and identities for all input findings.
     */
    public function apply(
        array $findings,
        ?DiffResult $changedRegion,
        array $baseStableIdentities,
        bool $hasNewOnlySource,
        array $analysisUnits = [],
        string $changedScope = DiffFindingFilter::SCOPE_SYMBOL,
    ): HookFilterResult {
        $identities                = HookFindingIdentity::forFindings($findings);
        $kept                      = [];
        $findingIdsInChangedRegion = [];
        $changedFileLookup         = [];

        // Build a file lookup only when the caller has asked to restrict the hook to a changed region.
        if ($changedRegion instanceof DiffResult && $changedRegion->active) {
            $changedFileLookup = array_fill_keys($changedRegion->changedFiles, true);
            // Symbol and hunk scope need overlap checks. File scope accepts the whole changed file.
            if ($changedScope !== DiffFindingFilter::SCOPE_FILE) {
                $regionMatches = (new DiffFindingFilter())->apply($findings, $changedRegion, $analysisUnits, $changedScope);
                // Remember the exact findings inside the selected region so same-line results stay distinct.
                foreach ($regionMatches->findings as $finding) {
                    $findingIdsInChangedRegion[spl_object_id($finding)] = true;
                }
            }
        }

        // Decide which current findings belong in the hook report after checking scope and comparison history.
        foreach ($findings as $finding) {
            $findingObjectId = spl_object_id($finding);
            $scope           = HookFindingScope::classify($finding);
            // A finding already in the chosen Git comparison should not count as a newly introduced issue.
            if (isset($baseStableIdentities[$identities[$findingObjectId] ?? HookFindingIdentity::forFinding($finding, $scope)])) {
                continue;
            }

            // A full scan keeps current findings that were not matched to the comparison.
            if (!$changedRegion instanceof DiffResult || !$changedRegion->active) {
                $kept[] = $finding;
                continue;
            }

            $isChangedFile = isset($changedFileLookup[$finding->filePath]);
            $isInScope     = match (true) {
                $scope === HookFindingScope::PROJECT => $hasNewOnlySource,
                $changedScope === DiffFindingFilter::SCOPE_FILE => $isChangedFile,
                $scope === HookFindingScope::FILE => $isChangedFile && $hasNewOnlySource,
                default => isset($findingIdsInChangedRegion[$findingObjectId]),
            };
            // Publish only findings covered by the requested scope or eligible as new project-wide findings.
            if ($isInScope) {
                $kept[] = $finding;
            }
        }

        return new HookFilterResult($kept, count($findings) - count($kept), $identities);
    }
}
