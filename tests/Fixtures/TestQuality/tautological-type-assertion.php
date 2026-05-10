<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\TautologicalTypeAssertion;

use PHPUnit\Framework\TestCase;

class OrderService
{
    public function name(): string
    {
        return 'order';
    }
}

final class TautologicalTypeAssertionTest extends TestCase
{
    // Positive: variable assigned from `new OrderService()` then asserted to be one.
    public function testFlagsAssertOnLocallyConstructedInstance(): void
    {
        $service = new OrderService();

        self::assertInstanceOf(OrderService::class, $service);
    }

    // Positive: argument is the new expression itself.
    public function testFlagsAssertOnInlineNewExpression(): void
    {
        self::assertInstanceOf(OrderService::class, new OrderService());
    }

    // Negative: asserting a different class is not tautological.
    public function testAllowsDifferentClassAssertion(): void
    {
        $service = new OrderService();

        self::assertInstanceOf(\Countable::class, $service);
    }

    // Negative: value comes from a method call whose return type we can't prove locally.
    public function testAllowsAssertionOnUnknownReturnType(): void
    {
        $value = $this->buildSomething();

        self::assertInstanceOf(OrderService::class, $value);
    }

    // Edge: not an assertInstanceOf call at all.
    public function testIgnoresOtherAssertions(): void
    {
        $service = new OrderService();

        self::assertSame('order', $service->name());
    }

    private function buildSomething(): mixed
    {
        return new OrderService();
    }
}
