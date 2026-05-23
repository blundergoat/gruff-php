<?php

declare(strict_types=1);

namespace Fixtures\TestQuality;

use PHPUnit\Framework\TestCase;

final class AdvancedSmellTest extends TestCase
{
    public function testProcessOrderAndSendReceipt(): void
    {
        $service = new OrderService();
        $order = $service->processOrder();
        $receipt = $service->sendReceipt();

        self::assertSame('paid', $order->status());
        self::assertSame('sent', $receipt->status());
        self::assertTrue($service->auditTrailWritten());
    }

    public function testRenderBuildsLongOutput(): void
    {
        $service = new OrderService();
        $lineOne = 'one';
        $lineTwo = 'two';
        $lineThree = 'three';
        $lineFour = 'four';
        $lineFive = 'five';
        $lineSix = 'six';
        $lineSeven = 'seven';
        $lineEight = 'eight';
        $result = $service->render();

        self::assertSame($lineOne . $lineTwo . $lineThree . $lineFour . $lineFive . $lineSix . $lineSeven . $lineEight, $result);
    }

    public function testCalculateTotalReturnsExpectedValue(): void
    {
        $result = 6;

        self::assertSame(6, $result);
    }
}

final class MixedNamingQualityTest extends TestCase
{
    public function testProcessOrderCamelCaseName(): void
    {
        self::assertTrue((new OrderService())->processOrder());
    }

    public function test_process_order_snake_case_name(): void
    {
        self::assertTrue((new OrderService())->processOrder());
    }
}

final class PoorlyNamedTest extends TestCase
{
    public function testProcessOrderWorks(): void
    {
        self::assertTrue((new OrderService())->processOrder());
    }

    public function testProcessOrder1(): void
    {
        self::assertTrue((new OrderService())->processOrder());
    }

    public function testReturnsOk(): void
    {
        self::assertTrue((new OrderService())->processOrder());
    }

    public function testProcessOrderHandlesEmptyInput(): void
    {
        self::assertTrue((new OrderService())->processOrder());
    }
}
