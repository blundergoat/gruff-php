<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Finding\Finding;

/**
 * Works out what a branch changed about a codebase's findings by comparing this run against a snapshot
 * of the base branch.
 *
 * This backs `gruff-php analyse --diff-vs <base>`: given the current findings and the base branch's
 * findings, it sorts every issue into introduced (new on this branch), removed (fixed on this branch),
 * or unchanged (present in both). Matching is line-insensitive, so shuffling code around doesn't make
 * an old finding look new - the user sees only what their branch genuinely added or resolved.
 */
final readonly class BranchReviewComparator
{
    /**
     * Sorts the current and base findings into introduced, removed, and unchanged, so the user sees
     * exactly what their branch changed rather than a flat re-listing of everything.
     *
     * @param list<Finding> $current - Findings from this branch's run.
     * @param list<Finding> $base - Findings from the base branch's run to compare against; empty means every current finding counts as introduced.
     * @param string        $baseRef - Base ref the comparison was made against (for example `origin/main`), echoed back in the review.
     * @param bool          $isChangedOnly - True when the review was restricted to changed files, recorded so the report can say the scope was narrowed.
     * @param float|null    $deltaScore - Score change from base to current; null when either side lacks an applicable score, which today means the current run discovered no files.
     *
     * @return BranchReviewResult - Findings partitioned into introduced, removed, and unchanged sets plus the score delta, ready for the caller to render the branch review.
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

        // Walk each group of current findings, deciding which already existed on base and which are new.
        foreach ($currentByKey as $key => $currentFindings) {
            // No base group under this key means the base branch had none of these, so they are all new.
            $baseFindings = $baseByKey[$key] ?? [];
            $matched      = min(count($currentFindings), count($baseFindings));

            // The first matched findings existed in both runs, so they are unchanged.
            for ($i = 0; $i < $matched; $i++) {
                $unchanged[] = $currentFindings[$i];
            }

            // Any current findings past the matched count have no base counterpart - this branch introduced them.
            for ($i = $matched, $end = count($currentFindings); $i < $end; $i++) {
                $introduced[] = $currentFindings[$i];
            }
        }

        // Now walk the base groups to find what disappeared from this branch.
        foreach ($baseByKey as $key => $baseFindings) {
            // No current group under this key means every base finding here is gone.
            $currentFindings = $currentByKey[$key] ?? [];
            $matched         = min(count($baseFindings), count($currentFindings));

            // Base findings beyond the matched count no longer appear on the branch - the user resolved them.
            for ($i = $matched, $end = count($baseFindings); $i < $end; $i++) {
                $removed[] = $baseFindings[$i];
            }
        }

        return new BranchReviewResult($baseRef, $isChangedOnly, $introduced, $removed, $unchanged, $deltaScore);
    }

    /**
     * Buckets findings by their line-insensitive review identity, so comparing two runs ignores where
     * code moved to and matches only on what each finding is.
     *
     * @param list<Finding>         $findings - Findings to bucket by review identity before comparison.
     * @param FindingReviewIdentity $identity - Key strategy that buckets findings so matching ignores line drift.
     *
     * @return array<string, list<Finding>> - Findings bucketed by review-identity key, keys sorted ascending so iteration is deterministic; empty when there are no findings.
     */
    private function index(array $findings, FindingReviewIdentity $identity): array
    {
        $indexed = [];

        // Drop each finding into the bucket for its identity key; several findings can share one key.
        foreach ($findings as $finding) {
            $indexed[$identity->key($finding)][] = $finding;
        }

        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
