<?php

declare(strict_types=1);

namespace Fixtures\M07\DeadCode;

class UnusedParameterFixture
{
    private function withUnused(int $used, int $unused): int
    {
        return $used;
    }

    private function allUsed(int $a, int $b): int
    {
        return $a + $b;
    }

    public function publicMethod(int $unused): void
    {
    }
}

function standaloneFunction(int $used, string $unused): int
{
    return $used;
}
