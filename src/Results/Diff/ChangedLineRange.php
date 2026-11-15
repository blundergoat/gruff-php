<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

/**
 * An inclusive line span used for diff hunks, explicit changed ranges, or enclosing declarations.
 * Diff filtering compares finding or declaration spans with these ranges according to the selected scope.
 * `touches()` checks overlap, and `toArray()` serializes the bounds as `start` and `end`.
 */
final readonly class ChangedLineRange
{
    /**
     * Records one inclusive line span for changed-range matching or declaration widening.
     * The stored bounds use the same 1-based coordinates as findings and parser nodes.
     *
     * @param int $startLine - First line of the inclusive span, using 1-based file coordinates.
     * @param int $endLine - Last line of the inclusive span; equal to startLine for a single-line span.
     */
    public function __construct(
        public int $startLine,
        public int $endLine,
    ) {
    }

    /**
     * Tests inclusive overlap between this range and another line span.
     *
     * @param int $startLine - First line of the span to compare.
     * @param int $endLine - Last line of the span to compare.
     *
     * @return bool - True when the spans share a line, including a shared boundary; false when they are disjoint.
     */
    public function touches(int $startLine, int $endLine): bool
    {


        return $this->startLine <= $endLine && $this->endLine >= $startLine;
    }

    /**
     * Serializes this span using the report's `start` and `end` keys.
     *
     * @return array{start: int, end: int} - Both inclusive bounds, copied from startLine and endLine.
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
