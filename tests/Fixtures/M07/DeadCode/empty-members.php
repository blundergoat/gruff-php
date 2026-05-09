<?php

declare(strict_types=1);

namespace Fixtures\M07\DeadCode;

abstract class AbstractFixture
{
    public function emptyMethod(): void
    {
    }

    public function notEmpty(): void
    {
        echo 'content';
    }

    abstract public function abstractMethod(): void;
}

class EmptyClassFixture
{
}

class NotEmptyClassFixture
{
    public int $x = 0;
}

interface EmptyInterfaceFixture
{
    public function required(): void;
}
