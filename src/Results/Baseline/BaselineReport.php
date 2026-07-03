<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

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
     * @param string              $path - Baseline file path used for the run.
     * @param bool                $generated - Whether the run generated a baseline file.
     * @param int                 $totalEntries - Total group rows loaded from the baseline.
     * @param int                 $suppressedFindings - Findings suppressed by the baseline.
     * @param string              $staleEvaluation - Stale-entry evaluation mode or summary.
     * @param list<BaselineEntry> $staleEntries - Absent baseline groups, each carrying the resolved instance count in its count field.
     * @param string              $source - Baseline source classification.
     * @param int                 $newCount - Finding instances beyond their group's accepted count (the `new` bucket).
     * @param int                 $unchangedCount - Finding instances matched inside a group's accepted count (the `unchanged` bucket; equals
     *                                                $suppressedFindings).
     * @param int                 $absentCount - Accepted instances with no matching finding this run (the `absent`/resolved bucket; the sum of
     *                                                resolved counts across $staleEntries, so it can exceed the row count).
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
     *     stale: list<array{file: string, ruleId: string, message: string, count: int}>,
     *     buckets: array{new: int, unchanged: int, absent: int}
     * } - serialized report for JSON output: staleEntries counts absent group rows while the rows live under stale (count = resolved instances),
     * and new/unchanged/absent instance tallies group under buckets
     */
    public function toArray(): array
    {
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
