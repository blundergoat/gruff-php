<?php

declare(strict_types=1);

namespace Fixtures\Docs;

use RuntimeException;

/**
 * Fixtures for docs.return-comment (described @return). Each method name states the case it locks:
 * a `Fires` method must raise the rule, a `Clean` method must stay silent. Covers described vs bare
 * tags, generics, void/never, constructor/destructor exemptions, the no-@return hand-off, and the
 * untyped-declaration body fallback.
 */
class ReturnDescriptionFixture
{
    /**
     * Fires: value-returning with a bare @return that states only the type.
     *
     * @return int
     */
    public function bareReturnFires(): int
    {
        return 1;
    }

    /**
     * Clean: the @return carries a description after the type.
     *
     * @return int - the resolved count
     */
    public function describedReturnIsClean(): int
    {
        return 1;
    }

    /**
     * Fires: a bare generic @return whose only spaces sit inside the brackets still has no description.
     *
     * @return array<string, int>
     */
    public function bareGenericReturnFires(): array
    {
        return [];
    }

    /**
     * Clean: a described generic @return is read past its bracketed type.
     *
     * @return array<string, int> - counts keyed by label
     */
    public function describedGenericReturnIsClean(): array
    {
        return [];
    }

    /**
     * Clean: void declares no value, so there is nothing to describe.
     *
     * @return void
     */
    public function voidIsClean(): void
    {
    }

    /**
     * Clean: never declares no value, so there is nothing to describe.
     *
     * @return never
     */
    public function neverIsClean(): never
    {
        throw new RuntimeException('halt');
    }

    /**
     * Clean: constructors are exempt even when a bare @return is mistakenly documented.
     *
     * @return self
     */
    public function __construct()
    {
    }

    /**
     * Clean: destructors are exempt even when a bare @return is mistakenly documented.
     *
     * @return void
     */
    public function __destruct()
    {
    }

    /**
     * Clean: no @return tag at all is owned by docs.missing-return-tag, not this rule.
     *
     * @param int $value - value echoed straight back to the caller
     */
    public function noReturnTagIsClean(int $value): int
    {
        return $value;
    }

    /**
     * Fires: an untyped declaration whose body returns a value falls in scope via the body fallback.
     *
     * @return mixed
     */
    public function untypedBareReturnFires()
    {
        return 1;
    }

    /**
     * Clean: an untyped declaration whose body returns no value has nothing to describe.
     *
     * @return void
     */
    public function untypedVoidBodyIsClean()
    {
        return;
    }
}
