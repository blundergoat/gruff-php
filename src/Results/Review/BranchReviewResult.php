<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Finding\Finding;

/**
 * Holds introduced, removed, and unchanged findings from a branch review, with an optional score delta.
 * BranchReviewComparator produces it for comparisons against a base ref.
 *
 * Records whether analysis was limited to changed files and exposes per-rule deltas for reporting.
 *
 * @phpstan-type ReviewScalar bool|float|int|object|string|null
 * @phpstan-type ReviewValue ReviewScalar|array<array-key, ReviewScalar|array<array-key, ReviewScalar|array<array-key, ReviewScalar|array<array-key,
 *               ReviewScalar>>>>
 */
final readonly class BranchReviewResult
{
    /**
     * Bundles the three finding buckets with the base ref, scope flag, and score delta of one review.
     *
     * @param string        $base - Base ref the branch was compared against (for example "origin/main").
     * @param bool          $isChangedOnly - True when the review only looked at changed files rather than the whole project.
     * @param list<Finding> $introduced - Findings new on this branch - the ones the review puts front and centre.
     * @param list<Finding> $removed - Findings the branch resolved: present in base, gone now.
     * @param list<Finding> $unchanged - Findings present in both base and branch, carried for context.
     * @param float|null    $deltaScore - Score change from base; null when either snapshot has no applicable score.
     */
    public function __construct(
        public string $base,
        public bool   $isChangedOnly,
        public array  $introduced,
        public array  $removed,
        public array  $unchanged,
        public ?float $deltaScore,
    ) {
    }

    /**
     * Returns a copy with a filter applied to each finding bucket, so a caller can (say) drop
     * baseline-suppressed findings from the review without disturbing the base ref or delta.
     *
     * @param callable(list<Finding>): list<Finding> $filter - Callback applied independently to the introduced, removed, and unchanged lists.
     *
     * @return self - New result with the same base ref, changed-only flag, and delta score, each finding group passed through $filter.
     */
    public function filtered(callable $filter): self
    {
        return new self(
            base:          $this->base,
            isChangedOnly: $this->isChangedOnly,
            introduced:    $filter($this->introduced),
            removed:       $filter($this->removed),
            unchanged:     $filter($this->unchanged),
            deltaScore:    $this->deltaScore,
        );
    }

    /**
     * Summarises finding changes by rule, omitting rules whose introduced and removed counts cancel out.
     * Rows sort by net change ascending (largest reductions first), then by rule ID.
     *
     * @return list<array{ruleId: string, introduced: int, removed: int, net: int}> - Nonzero deltas by net then rule ID; empty if none.
     */
    public function perRuleDelta(): array
    {
        $buckets = [];


        foreach ($this->introduced as $finding) {
            $buckets[$finding->ruleId] ??= ['introduced' => 0, 'removed' => 0];
            $buckets[$finding->ruleId]['introduced']++;
        }


        foreach ($this->removed as $finding) {
            $buckets[$finding->ruleId] ??= ['introduced' => 0, 'removed' => 0];
            $buckets[$finding->ruleId]['removed']++;
        }

        $rows = [];


        foreach ($buckets as $ruleId => $counts) {
            $netDelta = $counts['introduced'] - $counts['removed'];
            // A rule that added exactly as many as it removed is a wash, so leave it out of the breakdown.
            if ($netDelta === 0) {
                continue;
            }
            $rows[] = [
                'ruleId'     => $ruleId,
                'introduced' => $counts['introduced'],
                'removed'    => $counts['removed'],
                'net'        => $netDelta,
            ];
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => $left['net'] <=> $right['net']
                ?: strcmp($left['ruleId'], $right['ruleId']),
        );

        return $rows;
    }

    /**
     * Flattens the review into the JSON shape reporters serialise, so an editor or CI gets the same
     * introduced/removed counts and per-rule breakdown a person reads in the terminal.
     *
     * @return array<string, ReviewValue> - Status, ref, scope, counts, score delta (null if unavailable), rule deltas, and finding lists.
     */
    public function toArray(): array
    {
        return [
            'active'       => true,
            'base'         => $this->base,
            'changedOnly'  => $this->isChangedOnly,
            'counts'       => [
                'introduced' => count($this->introduced),
                'removed'    => count($this->removed),
                'unchanged'  => count($this->unchanged),
            ],
            'deltaScore'   => $this->deltaScore,
            'perRuleDelta' => $this->perRuleDelta(),
            'introduced'   => array_map(static fn(Finding $finding): array => $finding->toArray(), $this->introduced),
            'removed'      => array_map(static fn(Finding $finding): array => $finding->toArray(), $this->removed),
            'unchanged'    => array_map(static fn(Finding $finding): array => $finding->toArray(), $this->unchanged),
        ];
    }
}
