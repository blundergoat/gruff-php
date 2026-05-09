<?php

declare(strict_types=1);

final class Alpha
{
    public function value(): int
    {
        return match (true) {
            true => 1,
        };
    }
}
