<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * Captures what a baseline did to a single `analyse` run, so the user sees how their accepted
 * debt moved instead of a bare pass or fail. A baseline is the committed `gruff-baseline.json`
 * that signs off known findings; applying it drops those before scoring while anything new still
 * fails the run. This record carries the numbers the reports print - findings that were new,
 * unchanged accepted debt, and debt resolved since sign-off - alongside where the baseline came from.
 * The user meets it as the "Baseline" block under `analyse`, or right after `analyse --generate-baseline`.
 */
final readonly class BaselineReport
{
    /**
     * Marks the baseline as one the user named a path for - `--baseline <path>`, or `--generate-baseline <custom-path>` - rather than the discovered default.
     */
    public const SOURCE_EXPLICIT = 'explicit';

    /**
     * Marks the baseline as the default `gruff-baseline.json` that gruff discovered on its own.
     */
    public const SOURCE_DEFAULT = 'default';

    /**
     * Holds the fully-resolved baseline outcome the reporters print. On an apply run the filter builds it
     * and `BaselineApplication` stamps the source; a `--generate-baseline` run constructs it directly.
     *
     * @param string              $path - Path of the `gruff-baseline.json` this run read or wrote, echoed back as the report's "Path:" line.
     * @param bool                $generated - True when this run wrote a fresh baseline via `--generate-baseline`; false when it applied an existing one.
     * @param int                 $totalEntries - How many accepted-debt rows the baseline holds, shown to the user as the "Entries:" count.
     * @param int                 $suppressedFindings - Findings hidden as accepted debt before scoring, so known issues do not fail the run.
     * @param string              $staleEvaluation - Which resolution check ran: `full-project` compares every group, `not-evaluated-diff-scope` skips it because a partial or diff scan cannot prove a fix, and `generated` marks a freshly written baseline.
     * @param list<BaselineEntry> $staleEntries - Accepted-debt groups where fewer instances turned up than were accepted - debt partly or fully resolved since, each row's count being how many instances resolved; empty when nothing resolved or the scan was too narrow to tell.
     * @param string              $source - Either `explicit` or `default`, telling the user whether they pointed at this baseline or gruff discovered it.
     * @param int                 $newCount - Finding instances past what the baseline accepted - the "new" figure, and the count that still fails the run.
     * @param int                 $unchangedCount - Finding instances still covered by accepted debt (the "unchanged" figure, equal in number to
     *                                                $suppressedFindings).
     * @param int                 $absentCount - Accepted instances gone from this run (the "resolved" figure, summed from the
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
     * Flattens the report into the JSON shape `analyse --format json` emits; the text, HTML, and Markdown
     * reporters read the object's fields directly instead, so this is only the machine-readable path.
     *
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
     * } - JSON-ready map where `staleEntries` is only the count of resolved groups while the rows themselves live under `stale` (each `count` = instances resolved),
     * and the new, unchanged, and absent movement totals sit under `buckets`.
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
