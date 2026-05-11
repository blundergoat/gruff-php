<?php

declare(strict_types=1);

namespace Fixtures\Docs\MissingClass;

/**
 * Has a class-level docblock.
 */
final class DocumentedClass
{
}

final class UndocumentedClass
{
}

interface UndocumentedInterface
{
}

trait UndocumentedTrait
{
}

enum UndocumentedEnum
{
}

/**
 * Returns an anonymous class instance to confirm the rule skips anonymous classes.
 */
final class AnonymousFactory
{
    /**
     * Returns an anonymous class instance.
     */
    public function make(): object
    {
        return new class {
            public int $value = 0;
        };
    }
}
