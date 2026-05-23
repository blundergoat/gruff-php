<?php

declare(strict_types=1);

namespace Fixtures\TestQuality;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CumulativeQualityTest extends TestCase
{
    private mixed $service;
    private mixed $repository;
    private mixed $clock;
    private mixed $logger;
    private mixed $mailer;
    private mixed $cache;

    protected function setUp(): void
    {
        $this->service = new OrderService();
        $this->repository = new OrderRepository();
        $this->clock = new FakeClock();
        $this->logger = new ArrayLogger();
        $this->mailer = new FakeMailer();
        $this->cache = new ArrayCache();
        $this->repository->reset();
        $this->clock->freeze('2026-05-09');
    }

    public function testNoAssertion(): void
    {
        $this->service->render();
    }

    public function testTrivialAssertion(): void
    {
        self::assertTrue(true);
    }

    public function testConditionalCalculateTotal(): void
    {
        $total = $this->service->calculateTotal();
        if ($total > 0) {
            self::assertSame(2, $total);
        }
    }

    public function testLoopCalculateTotal(): void
    {
        foreach ([1, 2] as $amount) {
            self::assertSame($amount, $this->service->calculateTotal());
        }
    }

    public function testRenderBuildsLongOutput(): void
    {
        $lineOne = 'one';
        $lineTwo = 'two';
        $lineThree = 'three';
        $lineFour = 'four';
        $lineFive = 'five';
        $lineSix = 'six';
        $lineSeven = 'seven';
        $lineEight = 'eight';
        $result = $this->service->render();

        self::assertSame($lineOne . $lineTwo . $lineThree . $lineFour . $lineFive . $lineSix . $lineSeven . $lineEight, $result);
    }

    public function testProcessOrderAndSendReceipt(): void
    {
        $order = $this->service->processOrder();
        $receipt = $this->service->sendReceipt();

        self::assertSame('paid', $order->status());
        self::assertSame('sent', $receipt->status());
        self::assertTrue($this->service->auditTrailWritten());
    }

    public function testMysteryGuest(): void
    {
        $payload = file_get_contents('/tmp/order.json');

        self::assertSame('{}', $payload);
    }

    public function testTooManyMocks(): void
    {
        $first = $this->createMock(DependencyOne::class);
        $second = $this->createMock(DependencyTwo::class);
        $third = $this->createStub(DependencyThree::class);
        $fourth = $this->getMockBuilder(DependencyFour::class)->getMock();

        self::assertSame('ok', $this->service->run($first, $second, $third, $fourth));
    }

    public function testMockOnlyInteraction(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->once())->method('send');
    }

    public function testSleepCalculateTotal(): void
    {
        usleep(10);
        self::assertSame(2, $this->service->calculateTotal());
    }

    public function testMagicNumber(): void
    {
        self::assertSame(42, $this->service->countOrders());
    }

    public function testPrivateReflection(): void
    {
        $method = new ReflectionMethod(OrderService::class, 'secretTotal');
        $method->setAccessible(true);

        self::assertSame('secret', $method->invoke($this->service));
    }

    /**
     * @dataProvider orderCases
     */
    public function testDataProviderAnnotation(string $status): void
    {
        self::assertSame('paid', $status);
    }

    public function testTinySnapshot(): void
    {
        $this->assertMatchesSnapshot('ok');
    }

    public function testCalculateTotalReturnsExpectedValue(): void
    {
        $result = 6;

        self::assertSame(6, $result);
    }

    public function testSkipWithoutReason(): void
    {
        $this->markTestSkipped();
    }

    public function testProcessOrderCamelCaseName(): void
    {
        self::assertTrue($this->service->processOrder());
    }

    public function test_process_order_snake_case_name(): void
    {
        self::assertTrue($this->service->processOrder());
    }

    /**
     * @dataProvider emptyCases
     */
    public function testWithEmptyProvider(string $value): void
    {
        self::assertSame('', $value);
    }

    public static function emptyCases(): array
    {
        return [];
    }

    public function testCreatesUnusedMock(): void
    {
        $unused = $this->createMock(Mailer::class);

        self::assertSame('paid', $this->service->processOrder()->status());
    }

    public function testLongScenarioExceedsThreshold(): void
    {
        $a = 1;
        $b = 2;
        $c = 3;
        $d = 4;
        $e = 5;
        $f = 6;
        $g = 7;
        $h = 8;
        $i = 9;
        $j = 10;
        $k = 11;
        $l = 12;
        $m = 13;
        $n = 14;
        $o = 15;
        $p = 16;
        $q = 17;
        $r = 18;
        $s = 19;
        $t = 20;
        $u = 21;
        $v = 22;
        $w = 23;
        $x = 24;
        $y = 25;
        $z = 26;
        $sum = $a + $b + $c + $d + $e + $f + $g + $h + $i + $j + $k + $l;
        $tail = $m + $n + $o + $p + $q + $r + $s + $t + $u + $v + $w + $x + $y + $z;

        self::assertSame(351, $sum + $tail);
    }

    public function testBareExpectExceptionTypeOnly(): void
    {
        $this->expectException(\RuntimeException::class);

        throw new \RuntimeException('boom');
    }

    public function testAssertInstanceOfTautological(): void
    {
        $service = new OrderService();

        self::assertInstanceOf(OrderService::class, $service);
    }

    public function testWritesSuperglobalWithoutCleanup(): void
    {
        $_GET['user'] = 'leaked';

        self::assertSame('leaked', $_GET['user']);
    }
}

final class CumulativeQualityProductionExtenderTest extends OrderService
{
    public function testExtendsProductionInsteadOfTestBase(): void
    {
        // intentionally hollow.
    }
}

final class CumulativeRepeatedShapesTest extends \PHPUnit\Framework\TestCase
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
