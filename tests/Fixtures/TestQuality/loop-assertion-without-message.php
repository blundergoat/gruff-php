<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\LoopAssertionWithoutMessage;

use PHPUnit\Framework\TestCase;

final class LoopAssertionWithoutMessageTest extends TestCase
{
    // Positive: foreach with a bare assertion (no message argument).
    public function testForeachAssertionWithoutMessage(): void
    {
        foreach ([1, 2, 3] as $value) {
            self::assertSame(1, $value);
        }
    }

    // Positive: for-loop with bare assertion.
    public function testForLoopAssertionWithoutMessage(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertGreaterThan(-1, $i);
        }
    }

    // Negative: foreach with a sprintf message argument.
    public function testForeachAssertionWithMessage(): void
    {
        foreach ([1, 2, 3] as $index => $value) {
            self::assertSame($index + 1, $value, sprintf('row %d', $index));
        }
    }

    // Negative: foreach with an interpolated string message.
    public function testForeachAssertionWithInterpolatedMessage(): void
    {
        foreach ([1, 2, 3] as $index => $value) {
            self::assertSame($index + 1, $value, "row {$index}");
        }
    }

    // Edge: assertion outside any loop must not fire.
    public function testBareAssertionOutsideLoopIsNotFlagged(): void
    {
        self::assertSame(1, 1);
    }
}
