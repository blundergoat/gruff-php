<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Finding\Finding;

/**
 * Carries introduced, resolved, and existing findings for branch review.
 *
 * @phpstan-type ReviewScalar bool|float|int|object|string|null
 * @phpstan-type ReviewValue ReviewScalar|array<array-key, ReviewScalar|array<array-key, ReviewScalar|array<array-key, ReviewScalar|array<array-key,
 *               ReviewScalar>>>>
 */
final readonly class BranchReviewResult
{
    /**
     * @param string        $base          Base ref used for the review comparison.
     * @param bool          $isChangedOnly Whether the review was restricted to changed files.
     * @param list<Finding> $introduced    Findings introduced by the branch.
     * @param list<Finding> $removed       Findings removed by the branch.
     * @param list<Finding> $unchanged     Findings present in both base and branch.
     * @param float|null    $deltaScore    Score delta versus the base snapshot, when available.
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
     * @param callable(list<Finding>): list<Finding> $filter
     *
     * @return self - new result carrying the same base ref, changed-only flag, and delta score, with each finding group (introduced, removed,
     *              unchanged) passed through $filter
     */
    public function filtered(callable $filter): self
    {
        // Metadata (base, changed-only flag, delta) is copied verbatim; only the finding lists pass through the filter.
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
     * Bucket introduced and removed findings by rule id and return per-rule deltas
     * sorted by net change. Net = introduced - removed. Zero-net rules are
     * omitted. Ties are broken by rule id ascending so output is deterministic.
     *
     * @return list<array{ruleId: string, introduced: int, removed: int, net: int}> - one row per rule with a nonzero net change, ascending by net
     *                            (largest reductions first) then rule id; empty when introduced and removed cancel out
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
            $net = $counts['introduced'] - $counts['removed'];
            if ($net === 0) {
                continue;
            }
            $rows[] = [
                'ruleId'     => $ruleId,
                'introduced' => $counts['introduced'],
                'removed'    => $counts['removed'],
                'net'        => $net,
            ];
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => $left['net'] <=> $right['net']
                ?: strcmp($left['ruleId'], $right['ruleId']),
        );

        // Total order, so report output and regression snapshots stay byte-stable regardless of map insertion order.
        return $rows;
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array<string, ReviewValue> - report payload keyed by field name: base ref, changed-only flag, per-bucket counts, delta score (null
     *                       when unavailable), per-rule deltas, and the serialized finding groups
     */
    public function toArray(): array
    {
        // Report payload; active=true flags that a branch review ran, and counts are derived from the finding lists.
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
