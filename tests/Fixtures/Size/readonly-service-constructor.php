<?php

declare(strict_types=1);

namespace Fixtures\Size;

/**
 * A final readonly constructor with promoted typed parameters but a void behaviour method, so it is a
 * dependency-carrying service rather than a value object and keeps the severe parameter-count threshold
 * instead of the high promoted-value-object ceiling.
 */
final readonly class ReadonlyServiceConstructorFixture
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
        public int $p10,
        public int $p11,
    ) {
    }

    public function notify(): void
    {
        echo $this->p1;
    }
}
