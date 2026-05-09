<?php

declare(strict_types=1);

namespace Fixtures\M07\DeadCode;

class CleanFixture
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
