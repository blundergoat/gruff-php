<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * One inclusive span of lines the user changed - from a git diff hunk, a `--changed-ranges` value, or a symbol's declaration span.
 *
 * These ranges are how a scan gets narrowed to just someone's edits. When a user runs
 * `gruff-php analyse --diff` (or pipes a patch with `git diff | gruff-php analyse --diff=-`),
 * every finding is tested against the changed ranges so only issues on lines they actually
 * touched are reported and pre-existing noise stays hidden. `touches()` is the per-finding
 * overlap test the diff filter runs; `toArray()` renders the span into the report payload.
 */
final readonly class ChangedLineRange
{
    /**
     * Records one changed span as the diff parser walks a hunk, or when the user hands over explicit
     * ranges, pinning the first and last line the edit covered so findings can later be matched to it.
     *
     * @param int $startLine - First changed line in the span (1-based), as it sits in the user's edited file.
     * @param int $endLine - Last changed line in the span (1-based); equal to `startLine` for a single-line edit.
     */
    public function __construct(
        public int $startLine,
        public int $endLine,
    ) {
    }

    /**
     * Tests whether a finding's line span lands on this changed range - the overlap gate the diff filter
     * (and the hook filter) runs per finding to keep issues on edited lines and drop what the user did not touch.
     *
     * @param int $startLine - First line of the finding's span being checked against this range.
     * @param int $endLine - Last line of the finding's span being checked against this range.
     *
     * @return bool - True when the two spans share at least one line, so the finding is kept; false when they are
     *                disjoint, dropping it as outside the user's edited region.
     */
    public function touches(int $startLine, int $endLine): bool
    {
        // Two spans overlap unless one lies entirely before the other; because the bounds are
        // inclusive, two spans that share a single boundary line (say [1,5] and [5,10]) still count as
        // touching, so a finding on that shared line is kept rather than quietly lost.
        return $this->startLine <= $endLine && $this->endLine >= $startLine;
    }

    /**
     * Flattens the span into the plain `start`/`end` array that report output carries, so a changed
     * range shows up in the JSON a user or their editor reads back after a `--diff` scan.
     *
     * @return array{start: int, end: int} - Inclusive bounds under the report's `start`/`end` wire keys, always both
     *                present, mirroring the internal `startLine`/`endLine` pair.
     */
    public function toArray(): array
    {
        // Report consumers key on `start`/`end`, so that public wire contract is deliberately kept
        // separate from the internal `startLine`/`endLine` names; renaming the properties must never
        // change the shape a user's tooling parses out of the report.
        return [
            'start' => $this->startLine,
            'end'   => $this->endLine,
        ];
    }
}
