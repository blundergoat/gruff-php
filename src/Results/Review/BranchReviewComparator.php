<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Finding\Finding;

/**
 * Compares current findings against a base-branch analysis snapshot.
 */
final readonly class BranchReviewComparator
{
    /**
      * User flow: Compares branch feedback for review workflows.
      *
     * @param list<Finding> $current - Current branch findings to compare.
     * @param list<Finding> $base - Base branch findings to compare against.
     * @param string        $baseRef - Base ref used to produce the comparison.
     * @param bool          $isChangedOnly - Whether unchanged changed-file scope applies.
     * @param float|null    $deltaScore - Optional score delta between base and current runs.
     *
     * @return BranchReviewResult - findings partitioned into introduced, removed, and unchanged sets plus the
     *   score delta, ready for the caller to render the branch review
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

        // User view: add each item that can appear in branch review feedback.
        foreach ($currentByKey as $key => $currentFindings) {
            // User view: missing data becomes a safe branch review feedback default.
            $baseFindings = $baseByKey[$key] ?? [];
            $matched      = min(count($currentFindings), count($baseFindings));

            for ($i = 0; $i < $matched; $i++) {
                $unchanged[] = $currentFindings[$i];
            }

            for ($i = $matched, $end = count($currentFindings); $i < $end; $i++) {
                $introduced[] = $currentFindings[$i];
            }
        }

        // User view: add each item that can appear in branch review feedback.
        foreach ($baseByKey as $key => $baseFindings) {
            // User view: missing data becomes a safe branch review feedback default.
            $currentFindings = $currentByKey[$key] ?? [];
            $matched         = min(count($baseFindings), count($currentFindings));

            for ($i = $matched, $end = count($baseFindings); $i < $end; $i++) {
                $removed[] = $baseFindings[$i];
            }
        }

        return new BranchReviewResult($baseRef, $isChangedOnly, $introduced, $removed, $unchanged, $deltaScore);
    }

    /**
     * Index findings by branch-review identity.
     *
      * User flow: Compares branch feedback for review workflows.
      *
     * @param list<Finding>         $findings - Findings to bucket by review identity before comparison.
     * @param FindingReviewIdentity $identity - Key strategy that buckets findings so matching ignores line drift.
     *
     * @return array<string, list<Finding>> - findings bucketed by review-identity key, keys sorted ascending so iteration order is deterministic;
     *                       empty when no findings
     */
    private function index(array $findings, FindingReviewIdentity $identity): array
    {
        $indexed = [];

        // User view: add each item that can appear in branch review feedback.
        foreach ($findings as $finding) {
            $indexed[$identity->key($finding)][] = $finding;
        }

        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
