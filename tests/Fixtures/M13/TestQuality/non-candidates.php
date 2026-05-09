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
}
