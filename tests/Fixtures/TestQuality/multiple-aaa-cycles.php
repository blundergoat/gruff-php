<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\MultipleAaaCycles;

use PHPUnit\Framework\TestCase;

final class MultipleAaaCyclesTest extends TestCase
{
    // Positive: 3 distinct act-then-assert pairs in one method.
    public function testThreeCyclesInOneMethod(): void
    {
        $service = new OrderService();

        $first = $service->run('one');
        self::assertSame(1, $first);

        $second = $service->run('two');
        self::assertSame(2, $second);

        $third = $service->run('three');
        self::assertSame(3, $third);
    }

    // Negative: single act-assert is the canonical AAA shape.
    public function testSingleCycle(): void
    {
        $service = new OrderService();

        $result = $service->run('one');

        self::assertSame(1, $result);
    }

    // Edge: setup then a single multi-assert block - one cycle, not many.
    public function testSetupAndMultipleAssertionsCountAsOneCycle(): void
    {
        $service = new OrderService();
        $result = $service->run('one');

        self::assertSame(1, $result);
        self::assertNotNull($result);
    }

    // Edge: an act statement followed by an inline act-and-assert statement is one cycle, not two.
    public function testActThenInlineActAssertCountsAsOneCycle(): void
    {
        $service = new OrderService();

        $service->run('warmup');

        self::assertSame(1, $service->run('one'));
    }
}
