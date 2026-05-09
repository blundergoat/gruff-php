<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

final readonly class ChangedLineRange
{
    public function __construct(
        public int $startLine,
        public int $endLine,
    ) {
    }

    public static function fromStartAndLength(int $startLine, int $length): self
    {
        $safeLength = max(1, $length);

        return new self($startLine, $startLine + $safeLength - 1);
    }

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
