<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\ExceptionTypeOnly;

use PHPUnit\Framework\TestCase;

final class ExceptionTypeOnlyTest extends TestCase
{
    // Positive: only the type is asserted; no message/code/object follow-up.
    public function testFlagsBareExpectException(): void
    {
        $this->expectException(\RuntimeException::class);

        throw new \RuntimeException('boom');
    }

    // Negative: paired with expectExceptionMessage, fully specified.
    public function testAllowsTypeAndMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected explosion');

        throw new \RuntimeException('expected explosion');
    }

    // Negative: type plus code is enough.
    public function testAllowsTypeAndCode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(42);

        throw new \RuntimeException('boom', 42);
    }

    // Negative: expectExceptionObject pins type AND state.
    public function testAllowsExpectExceptionObject(): void
    {
        $this->expectExceptionObject(new \RuntimeException('boom', 42));

        throw new \RuntimeException('boom', 42);
    }

    // Edge: throwing test without expectException is unrelated to the rule.
    public function testNoExpectExceptionIsNotFlagged(): void
    {
        self::assertSame(1, 1);
    }
}
