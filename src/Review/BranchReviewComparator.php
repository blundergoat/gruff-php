<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Finding\Finding;

/**
 * Compares current findings against a base-branch analysis snapshot.
 */
final readonly class BranchReviewComparator
{
    /**
     * @param list<Finding> $current     Current branch findings to compare.
     * @param list<Finding> $base        Base branch findings to compare against.
     * @param string        $baseRef     Base ref used to produce the comparison.
     * @param bool          $changedOnly Whether unchanged changed-file scope applies.
     * @param float|null    $deltaScore  Optional score delta between base and current runs.
     * @return BranchReviewResult Introduced, removed, and unchanged finding sets.
     */
    public function compare(
        array $current,
        array $base,
        string $baseRef,
        bool $changedOnly,
        ?float $deltaScore,
    ): BranchReviewResult {
        $identity     = new FindingReviewIdentity();
        $currentByKey = $this->index($current, $identity);
        $baseByKey    = $this->index($base, $identity);
        $introduced   = [];
        $unchanged    = [];
        $removed      = [];

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

        return new BranchReviewResult($baseRef, $changedOnly, $introduced, $removed, $unchanged, $deltaScore);
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, list<Finding>>
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
