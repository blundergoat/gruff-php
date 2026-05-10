<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

class CleanFixture
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
