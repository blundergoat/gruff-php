<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * What a baseline did to one `analyse` run, so the user sees how their accepted debt moved instead of a bare pass or fail.
 *
 * Applying a baseline hides reviewed findings before scoring while anything new still fails the run.
 * This record carries the numbers the reports print: new, unchanged, resolved, plus the collisions and sensitive findings a baseline may never hide.
 * The user meets it as the "Baseline" block under `analyse`, or right after `analyse --generate-baseline`.
 */
final readonly class BaselineReport
{
    /**
     * Marks a baseline the user named a path for, with `--baseline <path>` or `--generate-baseline <custom-path>`.
     */
    public const SOURCE_EXPLICIT = 'explicit';

    /**
     * Marks the default `gruff-baseline.json` that gruff discovered on its own.
     */
    public const SOURCE_DEFAULT = 'default';

    /**
     * Holds the resolved baseline outcome the reporters print.
     *
     * @param string              $path - Path of the baseline this run read or wrote, echoed as the report's "Path:" line.
     * @param bool                $generated - True when this run wrote a fresh baseline; false when it applied an existing one.
     * @param int                 $totalEntries - Reviewed rows in the baseline, shown as the "Entries:" count.
     * @param int                 $suppressedFindings - Findings hidden as reviewed debt before scoring; equals $unchangedCount.
     * @param string              $staleEvaluation - `full-project`, `not-evaluated-diff-scope`, `generated`, or `migrated`.
     * @param list<BaselineEntry> $staleEntries - Reviewed identities with fewer live occurrences than reviewed, each count being how many resolved; empty when nothing resolved or the scan was too narrow to tell.
     * @param string              $source - `explicit` or `default`, telling the user whether they named this baseline or gruff discovered it.
     * @param int                 $newCount - Findings beyond what the baseline reviewed; still fail the run.
     * @param int                 $unchangedCount - Findings still covered by reviewed debt.
     * @param int                 $absentCount - Reviewed occurrences gone from this run, summed across $staleEntries.
     * @param int                 $collisionCount - Findings whose identity could not separate two declarations; reported, never hidden.
     * @param int                 $notEligibleCount - Sensitive findings, which no baseline entry can hide.
     * @param int                 $sensitiveCounted - Sensitive findings the written baseline counted rather than stored; 0 on an apply run.
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
        public int    $collisionCount = 0,
        public int    $notEligibleCount = 0,
        public int    $sensitiveCounted = 0,
    ) {
    }

    /**
     * Flattens the report into the JSON shape `analyse --format json` emits; the text reporters read the fields directly.
     *
     * @return array{
     *     path: string,
     *     generated: bool,
     *     totalEntries: int,
     *     suppressedFindings: int,
     *     staleEvaluation: string,
     *     staleEntries: int,
     *     source: string,
     *     stale: list<array{identity: string, count: int, ruleId?: string, path?: string, subject?: string}>,
     *     buckets: array{new: int, unchanged: int, absent: int, collision: int, notEligible: int, sensitiveCounted: int}
     * } - `staleEntries` is the resolved row count while the rows live under `stale`, and every movement total sits under `buckets`.
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
                'new'              => $this->newCount,
                'unchanged'        => $this->unchangedCount,
                'absent'           => $this->absentCount,
                'collision'        => $this->collisionCount,
                'notEligible'      => $this->notEligibleCount,
                'sensitiveCounted' => $this->sensitiveCounted,
            ],
        ];
    }
}
