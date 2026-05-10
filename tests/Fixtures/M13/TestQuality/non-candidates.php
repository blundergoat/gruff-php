<?php

declare(strict_types=1);

namespace Fixtures\M13\TestQuality;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CleanQualityTest extends TestCase
{
    #[DataProvider('statusCases')]
    public function testCalculateTotalReturnsExpectedStatus(string $status): void
    {
        $service = new OrderService();

        self::assertSame($status, $service->calculateTotal());
    }

    public function testSkipWithReason(): void
    {
        $this->markTestSkipped('External service sandbox is unavailable in CI.');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testLegacyExpectedExceptionAnnotation(): void
    {
        throw new \RuntimeException('expected');
    }

    public function helperWithCondition(): void
    {
        if (true) {
            sleep(1);
        }
    }

    public function testExpectOutputStringIsAnAssertion(): void
    {
        $this->expectOutputString('hello');
        print 'hello';
    }

    public function testExpectExceptionCodeIsAnAssertion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(42);

        throw new \RuntimeException('boom', 42);
    }

    public function testExpectDeprecationIsAnAssertion(): void
    {
        $this->expectDeprecation();

        trigger_error('deprecated', E_USER_DEPRECATED);
    }
}
