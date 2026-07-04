<?php

declare(strict_types=1);

namespace GruffPhp\Results\Trend;

/**
 * One point on a project's score history - the current composite score, how it moved since last time,
 * and the past entries behind that trend.
 *
 * A user builds this history by running `gruff-php analyse` with trend recording on; each run appends a
 * snapshot. This object is what a report renders when it shows "score 82 (+3 since last run)", carrying
 * the current figure, the previous same-scope figure, their delta, and the full entry list so the
 * movement can be plotted or printed. Scores are tracked per scope, so a whole-project run and a
 * diff-only run are never compared against each other.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendReport
{
    /**
     * Bundles one trend snapshot: where the score is now, where it was, and the history behind it.
     *
     * @param string           $path - Trend history file the snapshot was read from and appended to.
     * @param string           $scope - Score scope this snapshot and its delta belong to ('full-project' or 'diff'), so runs of different scope are never compared.
     * @param float            $currentScore - Composite score for this run.
     * @param float|null       $previousScore - Composite score from the previous same-scope run; null when this is the first run of that scope, so there is nothing to compare against yet.
     * @param float|null       $delta - Score change from the previous same-scope snapshot; null when there was no earlier same-scope entry to subtract.
     * @param list<TrendEntry> $entries - Full history of recorded snapshots, oldest first.
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
     * Flattens the snapshot into the JSON shape report writers emit, so an editor or dashboard can plot
     * the same trend a person reads in the terminal.
     *
     * @return array{
     *     path: string,
     *     scope: string,
     *     currentScore: float,
     *     previousScore: float|null,
     *     delta: float|null,
     *     entries: list<TrendEntry>
     * } - JSON-ready snapshot keys for report writers; previousScore and delta are null when the history holds
     * no earlier entry of the same scope, and scope names the series the delta belongs to.
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
