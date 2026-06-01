<?php

declare(strict_types=1);

namespace GruffPhp\Trend;

/**
 * Carries score and finding-count deltas for a trend snapshot.
 *
 * @phpstan-type TrendEntry array<string, bool|float|int|string|null>
 */
final readonly class TrendReport
{
    /**
     * @param string           $path - Trend history file path.
     * @param float            $currentScore - Current composite score.
     * @param float|null       $previousScore - Previous composite score, when available.
     * @param float|null       $delta - Score delta from the previous snapshot.
     * @param list<TrendEntry> $entries - Historical trend entries.
     */
    public function __construct(
        public string $path,
        public float  $currentScore,
        public ?float $previousScore,
        public ?float $delta,
        public array  $entries,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     currentScore: float,
     *     previousScore: float|null,
     *     delta: float|null,
     *     entries: list<TrendEntry>
     * } - JSON-ready snapshot keys for report writers; previousScore and delta are null on the first snapshot
     */
    public function toArray(): array
    {
        // Mirror the snapshot as JSON-ready keys for the report writers that serialise trend output.
        return [
            'path'          => $this->path,
            'currentScore'  => $this->currentScore,
            'previousScore' => $this->previousScore,
            'delta'         => $this->delta,
            'entries'       => $this->entries,
        ];
    }
}
