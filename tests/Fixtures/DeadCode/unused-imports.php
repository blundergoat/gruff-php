<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

use RuntimeException;
use InvalidArgumentException;
use LogicException;

class UnusedImportsFixture
{
    public function doSomething(): void
    {
        throw new RuntimeException('used');
    }
}
