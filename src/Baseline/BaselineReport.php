<?php

declare(strict_types=1);

namespace GruffPhp\Baseline;

/**
 * Summarizes how a baseline affected an analysis run.
 */
final readonly class BaselineReport
{
    /**
     * Baseline source selected explicitly by the user.
     */
    public const SOURCE_EXPLICIT = 'explicit';

    /**
     * Baseline source discovered from the default project location.
     */
    public const SOURCE_DEFAULT = 'default';

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
        public string $source = self::SOURCE_EXPLICIT,
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
     *     source: string,
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
            'source' => $this->source,
            'stale' => array_map(
                static fn (BaselineEntry $entry): array => $entry->toArray(),
                $this->staleEntries,
            ),
        ];
    }
}
