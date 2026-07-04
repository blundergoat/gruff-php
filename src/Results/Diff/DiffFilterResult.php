<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use GruffPhp\Results\Finding\Finding;

/**
 * The tallied outcome of narrowing a run's findings to the code the user actually changed.
 *
 * Holds the findings that survived changed-region filtering alongside a count of those dropped
 * as out of scope. `DiffFindingFilter` builds it on a changed-region scan (`--diff`, `--since`, or
 * `--changed-ranges`), narrowing a report toward the code around the user's edit.
 */
final readonly class DiffFilterResult
{
    /**
     * Wraps the changed-region filter's two outputs - the kept findings and how many were dropped - as
     * one value. Callers rarely build this directly; `DiffFindingFilter::apply()` returns it.
     *
     * @param list<Finding> $findings - Findings inside the changed region; empty means the changed code tripped no rules.
     * @param int           $suppressedCount - Pre-existing findings dropped as out of scope; zero if none were filtered.
     */
    public function __construct(
        public array $findings,
        public int $suppressedCount,
    ) {
    }
}
