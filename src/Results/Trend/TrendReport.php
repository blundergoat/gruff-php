<?php

declare(strict_types=1);

namespace GruffPhp\Results\Trend;

/**
 * Carries a recorded composite score, its same-scope comparison, and the retained history.
 * TrendRecorder appends the current entry and limits retention separately for each scope.
 *
 * A null previous score or delta means there is no usable numeric score in the latest same-scope entry.
 * The retained entries may contain both full-project and diff snapshots.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendReport
{
    /**
     * Bundles one trend snapshot: where the score is now, where it was, and the history behind it.
     *
     * @param string $path - History file display path; relative to the project when possible.
     * @param string $scope - Scope shared by the current score and its comparison entry.
     * @param float $currentScore - Composite score for this run.
     * @param float|null $previousScore - Latest same-scope numeric score; null if that entry is absent or its score is unusable.
     * @param float|null $delta - Rounded difference from the previous score; null when no usable comparison score exists.
     * @param list<TrendEntry> $entries - Retained snapshots in file order, including the current entry and any other scopes.
     */
    public function __construct(
        public string $path,
        public string $scope,
        public float  $currentScore,
        public ?float $previousScore,
        public ?float $delta,
        public array  $entries,
    ) {
    }

    /**
     * Return the stored trend fields, preserving null comparison values and retained entry order.
     *
     * @return array{path: string, scope: string, currentScore: float, previousScore: float|null, delta: float|null, entries: list<TrendEntry>} -
     *     Snapshot fields; null comparison values and retained entry order are preserved.
     */
    public function toArray(): array
    {
        return [
            'path'          => $this->path,
            'scope'         => $this->scope,
            'currentScore'  => $this->currentScore,
            'previousScore' => $this->previousScore,
            'delta'         => $this->delta,
            'entries'       => $this->entries,
        ];
    }
}
