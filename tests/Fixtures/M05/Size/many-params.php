<?php

declare(strict_types=1);

namespace Fixtures\M05\Size;

class ManyParamsFixture
{
    public function fewParams(int $a, int $b): void
    {
    }

    public function sixParams(int $a, int $b, int $c, int $d, int $e, int $f): void
    {
    }

    public function nineParams(int $a, int $b, int $c, int $d, int $e, int $f, int $g, int $h, int $i): void
    {
    }

    public function variadicParams(int $a, int $b, int ...$rest): void
    {
    }

    public function __construct(
        public readonly int $promoted1,
        public readonly int $promoted2,
        public readonly int $promoted3,
        public readonly int $promoted4,
        public readonly int $promoted5,
        public readonly int $promoted6,
    ) {
    }
}
