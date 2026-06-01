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
     * @param string              $path               Baseline file path used for the run.
     * @param bool                $generated          Whether the run generated a baseline file.
     * @param int                 $totalEntries       Total entries loaded from the baseline.
     * @param int                 $suppressedFindings Findings suppressed by the baseline.
     * @param string              $staleEvaluation    Stale-entry evaluation mode or summary.
     * @param list<BaselineEntry> $staleEntries       Baseline entries that no longer match findings.
     * @param string              $source             Baseline source classification.
     * @param int                 $newCount           Findings present this run with no baseline match (the `new` bucket).
     * @param int                 $unchangedCount     Findings matched by a baseline entry (the `unchanged` bucket; equals $suppressedFindings).
     * @param int                 $absentCount        Baseline entries with no matching finding this run (the `absent`/resolved bucket; equals
     *                                                count($staleEntries)).
     */
    public function __construct(
        public string $path,
        public bool   $generated,
        public int    $totalEntries,
        public int    $suppressedFindings,
        public string $staleEvaluation,
        public array  $staleEntries = [],
        public string $source = self::SOURCE_EXPLICIT,
        public int    $newCount = 0,
        public int    $unchangedCount = 0,
        public int    $absentCount = 0,
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
     *     stale: list<array{fingerprint: string, ruleId: string, file: string, line: int|null, symbol: string|null, message: string}>,
     *     buckets: array{new: int, unchanged: int, absent: int}
     * } - serialized report for JSON output: staleEntries is a count while full entries live under stale, and new/unchanged/absent finding tallies
     * group under buckets
     */
    public function toArray(): array
    {
        // Report shape: "staleEntries" is a count, the full entries live under "stale", counts group under "buckets".
        return [
            'path'               => $this->path,
            'generated'          => $this->generated,
            'totalEntries'       => $this->totalEntries,
            'suppressedFindings' => $this->suppressedFindings,
            'staleEvaluation'    => $this->staleEvaluation,
            'staleEntries'       => count($this->staleEntries),
            'source'             => $this->source,
            'stale'              => array_map(
                static fn(BaselineEntry $baselineEntry): array => $baselineEntry->toArray(),
                $this->staleEntries,
            ),
            'buckets'            => [
                'new'       => $this->newCount,
                'unchanged' => $this->unchangedCount,
                'absent'    => $this->absentCount,
            ],
        ];
    }
}
