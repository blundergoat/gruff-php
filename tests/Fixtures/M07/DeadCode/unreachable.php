<?php

declare(strict_types=1);

namespace Fixtures\M07\DeadCode;

class UnreachableFixture
{
    public function afterReturn(): int
    {
        return 1;
        $x = 2;
    }

    public function afterThrow(): void
    {
        throw new \RuntimeException('stop');
        echo 'never';
    }

    public function noUnreachable(): int
    {
        if (true) {
            return 1;
        }

        return 0;
    }

    public function afterExit(): void
    {
        exit(1);
        echo 'dead';
    }
}
