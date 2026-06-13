<?php

declare(strict_types=1);

namespace Fixtures\Size;

/**
 * A final readonly class with many promoted fields plus a behaviour method whose return type is
 * undeclared, so it reads as a service-shaped object rather than a pure data carrier and keeps its
 * configured property-count severity instead of the advisory data-carrier downgrade.
 */
final readonly class ReadonlyCarrierWithBehaviourFixture
{
    public function __construct(
        public int $f1,
        public int $f2,
        public int $f3,
        public int $f4,
        public int $f5,
        public int $f6,
        public int $f7,
        public int $f8,
        public int $f9,
        public int $f10,
        public int $f11,
        public int $f12,
        public int $f13,
        public int $f14,
        public int $f15,
        public int $f16,
    ) {
    }

    public function recompute()
    {
        return $this->f1 + $this->f16;
    }
}
