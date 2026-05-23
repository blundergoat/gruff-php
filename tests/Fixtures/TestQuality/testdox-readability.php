<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\TestdoxReadability;

use PHPUnit\Framework\TestCase;

final class TestdoxReadabilityTest extends TestCase
{
    // Positive: 2 words after the test prefix (under default threshold of 3).
    public function testProcessOrder(): void
    {
        self::assertSame(1, 1);
    }

    // Positive: 1 word.
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
