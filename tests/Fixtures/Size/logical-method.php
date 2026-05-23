<?php

declare(strict_types=1);

namespace Fixtures\Size;

class LogicalMethodFixture
{
    public function multilineCall(): void
    {
        $this->target(
            first: 'alpha',
            second: 'beta',
            third: 'gamma',
            fourth: 'delta',
            fifth: 'epsilon',
        );
    }

    private function target(
        string $first,
        string $second,
        string $third,
        string $fourth,
        string $fifth,
    ): void {
    }
}
