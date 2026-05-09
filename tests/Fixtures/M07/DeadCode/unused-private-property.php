<?php

declare(strict_types=1);

namespace Fixtures\M07\DeadCode;

class UnusedPrivatePropertyFixture
{
    private int $usedProp = 0;

    private int $neverRead = 0;

    private int $neverWritten;

    private string $totallyUnused = '';

    public int $publicProp = 0;

    public function doWork(): int
    {
        $this->neverRead = 5;
        $this->usedProp = 10;

        return $this->usedProp;
    }
}
