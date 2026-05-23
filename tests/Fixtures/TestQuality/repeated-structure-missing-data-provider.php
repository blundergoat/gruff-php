<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\RepeatedStructureMissingDataProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Positive: 3+ tests with structurally identical bodies that differ only by literal scalars.
final class RepeatedShapesTest extends TestCase
{
    public function testSumsAlpha(): void
    {
        $service = new OrderService();

        self::assertSame(1, $service->sum('a'));
    }

    public function testSumsBeta(): void
    {
        $service = new OrderService();

        self::assertSame(2, $service->sum('b'));
    }

    public function testSumsGamma(): void
    {
        $service = new OrderService();

        self::assertSame(3, $service->sum('c'));
    }
}

// Positive: repeated control-flow-heavy shape covering the fingerprint token variants.
final class RepeatedControlFlowTest extends TestCase
{
    public function testComplexAlpha(): void
    {
        $service = new OrderService();

        if (helper('a')) {
            foreach ([1] as $item) {
                self::assertTrue(Helper::check($service->sum($item)));
            }
        }

        if (false) {
            throw new \RuntimeException('alpha');
        }

        return;
    }

    public function testComplexBeta(): void
    {
        $service = new OrderService();

        if (helper('b')) {
            foreach ([2] as $item) {
                self::assertTrue(Helper::check($service->sum($item)));
            }
        }

        if (false) {
            throw new \RuntimeException('beta');
        }

        return;
    }

    public function testComplexGamma(): void
    {
        $service = new OrderService();

        if (helper('c')) {
            foreach ([3] as $item) {
                self::assertTrue(Helper::check($service->sum($item)));
            }
        }

        if (false) {
            throw new \RuntimeException('gamma');
        }

        return;
    }
}

// Negative: only 2 structurally identical methods - below the rule's minimum group size.
final class TwoRepeatedShapesTest extends TestCase
{
    public function testSumsAlpha(): void
    {
        $service = new OrderService();

        self::assertSame(1, $service->sum('a'));
    }

    public function testSumsBeta(): void
    {
        $service = new OrderService();

        self::assertSame(2, $service->sum('b'));
    }
}

// Negative: 3+ methods with the same shape, but already driven by a data provider - rule skips them.
final class AlreadyParameterisedTest extends TestCase
{
    #[DataProvider('cases')]
    public function testSumsAlphaParameterised(string $key, int $expected): void
    {
        $service = new OrderService();

        self::assertSame($expected, $service->sum($key));
    }

    public static function cases(): array
    {
        return [
            ['a', 1],
            ['b', 2],
            ['c', 3],
        ];
    }
}

// Edge: 3+ tests with different shapes - no fingerprint group of size 3.
final class HeterogeneousShapesTest extends TestCase
{
    public function testCallsSum(): void
    {
        $service = new OrderService();

        self::assertSame(1, $service->sum('a'));
    }

    public function testCallsName(): void
    {
        $service = new OrderService();

        self::assertSame('order', $service->name());
    }

    public function testNoSut(): void
    {
        self::assertSame(true, true);
    }
}
