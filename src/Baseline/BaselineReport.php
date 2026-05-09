<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

final readonly class BaselineReport
{
    /**
     * @param list<BaselineEntry> $staleEntries
     */
    public function __construct(
        public string $path,
        public bool $generated,
        public int $totalEntries,
        public int $suppressedFindings,
        public string $staleEvaluation,
        public array $staleEntries = [],
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     generated: bool,
     *     totalEntries: int,
     *     suppressedFindings: int,
     *     staleEvaluation: string,
     *     staleEntries: int,
     *     stale: list<array{fingerprint: string, ruleId: string, file: string, line: int|null, symbol: string|null, message: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'generated' => $this->generated,
            'totalEntries' => $this->totalEntries,
            'suppressedFindings' => $this->suppressedFindings,
            'staleEvaluation' => $this->staleEvaluation,
            'staleEntries' => count($this->staleEntries),
            'stale' => array_map(
                static fn (BaselineEntry $entry): array => $entry->toArray(),
                $this->staleEntries,
            ),
        ];
    }
}
