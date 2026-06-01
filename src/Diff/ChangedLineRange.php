<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

/**
 * Represents an inclusive changed-line range from a git diff.
 */
final readonly class ChangedLineRange
{
    /**
     * Create an inclusive changed-line range.
     *
     * @param int $startLine First changed line in the range.
     * @param int $endLine   Last changed line in the range.
     */
    public function __construct(
        public int $startLine,
        public int $endLine,
    ) {
    }

    /**
     * Check whether this range overlaps another inclusive line span.
     *
     * @param int $startLine First line in the compared range.
     * @param int $endLine   Last line in the compared range.
     * @return bool True when the ranges overlap.
     */
    public function touches(int $startLine, int $endLine): bool
    {
        // Overlap is the negation of "one range lies entirely before the other"; the inclusive
        // bounds mean ranges sharing a single endpoint (start == end) still count as touching.
        return $this->startLine <= $endLine && $this->endLine >= $startLine;
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array{start: int, end: int}
     */
    public function toArray(): array
    {
        // Report consumers key on `start`/`end`; that wire contract is deliberately decoupled from
        // the internal `startLine`/`endLine` property names, so renaming the properties must not leak here.
        return [
            'start' => $this->startLine,
            'end' => $this->endLine,
        ];
    }
}
