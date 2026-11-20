<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

/**
 * Applying a baseline removes reviewed findings from the set passed on for scoring.
 * This record carries new, unchanged, and absent counts, plus collisions and findings without usable baseline identities.
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
     * @param string              $staleEvaluation - "full-project", "not-evaluated-diff-scope", "generated", or "migrated".
     * @param list<BaselineEntry> $staleEntries - Missing reviewed occurrences by identity; empty when none are found or the check is skipped.
     * @param string              $source - Controls whether reporters label the baseline source as "explicit" or "default".
     * @param int                 $newCount - Findings beyond the reviewed count, passed on for scoring.
     * @param int                 $unchangedCount - Findings still covered by reviewed debt.
     * @param int                 $absentCount - Reviewed occurrences gone from this run, summed across $staleEntries.
     * @param int                 $collisionCount - Findings whose identity could not separate two declarations; reported, never hidden.
     * @param int $notEligibleCount - Findings without usable identities, including sensitive findings and separator-bearing symbols.
     * @param int $sensitiveCounted - Sensitive findings counted without identity rows when writing; zero when applying a baseline.
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
     * Serializes this record's fields, stale rows, and bucket counts into an array.
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
     * } - "staleEntries" is the resolved row count while the rows live under "stale", and every movement total sits under "buckets".
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
