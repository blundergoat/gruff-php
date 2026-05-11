<?php

declare(strict_types=1);

final class Alpha
{
    /**
     * Return the fixture value used by CLI discovery tests.
     *
     * @return int
     */
    public function value(): int
    {
        return match (true) {
            true => 1,
        };
    }
}
