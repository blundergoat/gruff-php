<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Finding\Finding;

/**
 * Compares current and base findings by a review identity that excludes the line field.
 * Duplicate identities are matched by count, with excess entries reported as introduced or removed.
 *
 * The result retains the supplied base ref, scope flag, and score delta.
 */
final readonly class BranchReviewComparator
{
    /**
     * Partitions findings by review identity and occurrence count across the current and base runs.
     *
     * @param list<Finding> $current - Current-run findings; extra occurrences of an identity are introduced.
     * @param list<Finding> $base - Base-run findings; extra occurrences of an identity are removed.
     * @param string $baseRef - Base ref copied into the comparison result.
     * @param bool $isChangedOnly - Scope flag copied into the result; it does not filter these inputs.
     * @param float|null $deltaScore - Caller-supplied score change copied into the result; null remains unavailable.
     *
     * @return BranchReviewResult - Matched current entries, excess current/base entries, and the supplied comparison metadata.
     */
    public function compare(
        array  $current,
        array  $base,
        string $baseRef,
        bool   $isChangedOnly,
        ?float $deltaScore,
    ): BranchReviewResult {
        $findingReviewIdentity = new FindingReviewIdentity();
        $currentByKey          = $this->index($current, $findingReviewIdentity);
        $baseByKey             = $this->index($base, $findingReviewIdentity);
        $introduced            = [];
        $unchanged             = [];
        $removed               = [];


        foreach ($currentByKey as $key => $currentFindings) {

            $baseFindings = $baseByKey[$key] ?? [];
            $matched      = min(count($currentFindings), count($baseFindings));


            for ($i = 0; $i < $matched; $i++) {
                $unchanged[] = $currentFindings[$i];
            }


            for ($i = $matched, $end = count($currentFindings); $i < $end; $i++) {
                $introduced[] = $currentFindings[$i];
            }
        }


        foreach ($baseByKey as $key => $baseFindings) {

            $currentFindings = $currentByKey[$key] ?? [];
            $matched         = min(count($baseFindings), count($currentFindings));


            for ($i = $matched, $end = count($baseFindings); $i < $end; $i++) {
                $removed[] = $baseFindings[$i];
            }
        }

        return new BranchReviewResult($baseRef, $isChangedOnly, $introduced, $removed, $unchanged, $deltaScore);
    }

    /**
     * Groups findings by line-insensitive identity while preserving their order within each group.
     *
     * @param list<Finding> $findings - Findings to group; repeated identities remain separate entries.
     * @param FindingReviewIdentity $identity - Builds the comparison key for each finding.
     *
     * @return array<string, list<Finding>> - Groups sorted by key; empty for an empty input.
     */
    private function index(array $findings, FindingReviewIdentity $identity): array
    {
        $indexed = [];


        foreach ($findings as $finding) {
            $indexed[$identity->key($finding)][] = $finding;
        }

        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
