<?php

declare(strict_types=1);

namespace GruffPhp\Results\Trend;

/**
 * Carries score and finding-count deltas for a trend snapshot.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendReport
{
    /**
      * User flow: Turns findings into score and trend signals users track.
      *
     * @param string           $path - Trend history file path.
     * @param string           $scope - Score scope this snapshot and its delta belong to ('full-project' or 'diff').
     * @param float            $currentScore - Current composite score.
     * @param float|null       $previousScore - Previous same-scope composite score, when available.
     * @param float|null       $delta - Score delta from the previous same-scope snapshot.
     * @param list<TrendEntry> $entries - Historical trend entries.
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
      * User flow: Turns findings into score and trend signals users track.
      *
     * @return array{
     *     path: string,
     *     scope: string,
     *     currentScore: float,
     *     previousScore: float|null,
     *     delta: float|null,
     *     entries: list<TrendEntry>
     * } - JSON-ready snapshot keys for report writers; previousScore and delta are null when the history holds
     * no earlier entry of the same scope, and scope names the series the delta belongs to
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
