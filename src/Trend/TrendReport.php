<?php

declare(strict_types=1);

namespace GruffPhp\Trend;

final readonly class TrendReport
{
    /**
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        public string $path,
        public float $currentScore,
        public ?float $previousScore,
        public ?float $delta,
        public array $entries,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     currentScore: float,
     *     previousScore: float|null,
     *     delta: float|null,
     *     entries: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'currentScore' => $this->currentScore,
            'previousScore' => $this->previousScore,
            'delta' => $this->delta,
            'entries' => $this->entries,
        ];
    }
}
