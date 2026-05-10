<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Finding\Finding;

final readonly class BranchReviewComparator
{
    /**
     * @param list<Finding> $current
     * @param list<Finding> $base
     */
    public function compare(
        array $current,
        array $base,
        string $baseRef,
        bool $changedOnly,
        ?float $deltaScore,
    ): BranchReviewResult {
        $identity = new FindingReviewIdentity();
        $currentByKey = $this->index($current, $identity);
        $baseByKey = $this->index($base, $identity);
        $introduced = [];
        $unchanged = [];
        $removed = [];

        foreach ($currentByKey as $key => $finding) {
            if (isset($baseByKey[$key])) {
                $unchanged[] = $finding;
                continue;
            }

            $introduced[] = $finding;
        }

        foreach ($baseByKey as $key => $finding) {
            if (!isset($currentByKey[$key])) {
                $removed[] = $finding;
            }
        }

        return new BranchReviewResult($baseRef, $changedOnly, $introduced, $removed, $unchanged, $deltaScore);
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, Finding>
     */
    private function index(array $findings, FindingReviewIdentity $identity): array
    {
        $indexed = [];

        foreach ($findings as $finding) {
            $indexed[$identity->key($finding)] ??= $finding;
        }

        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
