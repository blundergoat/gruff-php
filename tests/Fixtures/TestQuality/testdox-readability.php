<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\TestdoxReadability;

use PHPUnit\Framework\TestCase;

final class TestdoxReadabilityTest extends TestCase
{
    // Negative: 2 words after the test prefix meets the default threshold.
    public function testProcessOrder(): void
    {
        self::assertSame(1, 1);
    }

    // Positive: 1 word is too terse to render as useful TestDox.
    public function testProcess(): void
    {
        self::assertSame(1, 1);
    }

    // Negative: 3 words reads as a sentence ("process order succeeds").
    public function testProcessOrderSucceeds(): void
    {
        self::assertSame(1, 1);
    }

    // Negative: 5 words is comfortably above threshold.
    public function testProcessOrderMarksItAsPaid(): void
    {
        self::assertSame(1, 1);
    }
}
