<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

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

class EmptyExceptionFixture extends \RuntimeException
{
}

final class PromotedConstructorFixture
{
    public function __construct(private string $name)
    {
    }
}

interface EmptyInterfaceFixture
{
    public function required(): void;
}
