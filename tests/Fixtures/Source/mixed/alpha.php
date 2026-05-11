<?php

/**
 * Alpha fixture for CLI discovery and warning-threshold tests.
 */

declare(strict_types=1);

/**
 * Alpha class used by CLI tests.
 */
final class Alpha
{
    /**
     * Return the fixture value used by CLI discovery tests.
     *
     * @return int
     */
    public function value(): int
    {
        // Match exhaustively to return the canonical fixture value.
        return match (true) {
            true => 1,
        };
    }
}
