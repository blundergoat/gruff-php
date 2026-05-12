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
     * Create an inclusive range from a start line and line count.
     *
     * @param int $startLine First changed line in the range.
     * @param int $length    Number of changed lines in the range.
     * @return self Changed line range.
     */
    public static function fromStartAndLength(int $startLine, int $length): self
    {
        $safeLength = max(1, $length);

        return new self($startLine, $startLine + $safeLength - 1);
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
        return $this->startLine <= $endLine && $this->endLine >= $startLine;
    }

    /**
     * @return array{start: int, end: int}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->startLine,
            'end' => $this->endLine,
        ];
    }
}
