<?php

declare(strict_types=1);

namespace Fixtures\Size;

final readonly class PromotedPayloadFixture
{
    public function __construct(
        public int $p1,
        public int $p2,
        public int $p3,
        public int $p4,
        public int $p5,
        public int $p6,
        public int $p7,
        public int $p8,
        public int $p9,
    ) {
    }
}
