<?php

declare(strict_types=1);

namespace GruffPhp\Results\Review;

use GruffPhp\Results\Finding\Finding;

/**
 * The outcome of a branch review - what this branch introduced, removed, and left unchanged, plus how
 * the score moved - ready for a reporter to render.
 *
 * `BranchReviewComparator` produces this when a user runs `gruff-php analyse --diff-vs <base>`. It
 * holds the three finding buckets, the base ref they were compared against, whether the review was
 * limited to changed files, and the score delta, so a report can tell the user "your branch adds N and
 * fixes M" and break that down per rule.
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
     * @param string        $base - Base ref the branch was compared against (for example `origin/main`).
     * @param bool          $isChangedOnly - True when the review only looked at changed files rather than the whole project.
     * @param list<Finding> $introduced - Findings new on this branch - the ones the review puts front and centre.
     * @param list<Finding> $removed - Findings the branch resolved: present in base, gone now.
     * @param list<Finding> $unchanged - Findings present in both base and branch, carried for context.
     * @param float|null    $deltaScore - Score change versus the base snapshot; null when no comparable base score was available.
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
     * Breaks the review down per rule - how many findings each rule gained or lost on this branch - so
     * the user can see which checks their changes helped and which they set back.
     *
     * Net = introduced - removed. Rules whose introduced and removed cancel out are omitted. Ties are
     * broken by rule id ascending so the output is deterministic.
     *
     * @return list<array{ruleId: string, introduced: int, removed: int, net: int}> - One row per rule with a nonzero net change, ascending by net (largest reductions first) then rule id; empty when introduced and removed fully cancel out.
     */
    public function perRuleDelta(): array
    {
        $buckets = [];

        // Tally how many findings this branch added under each rule.
        foreach ($this->introduced as $finding) {
            $buckets[$finding->ruleId] ??= ['introduced' => 0, 'removed' => 0];
            $buckets[$finding->ruleId]['introduced']++;
        }

        // Tally how many findings this branch resolved under each rule.
        foreach ($this->removed as $finding) {
            $buckets[$finding->ruleId] ??= ['introduced' => 0, 'removed' => 0];
            $buckets[$finding->ruleId]['removed']++;
        }

        $rows = [];

        // Turn each rule's raw tallies into a net change, keeping only the rules that actually moved.
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
     * @return array<string, ReviewValue> - report payload keyed by field name: base ref, changed-only flag, per-bucket counts, delta score (null when unavailable), per-rule deltas, and the serialized finding groups.
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
