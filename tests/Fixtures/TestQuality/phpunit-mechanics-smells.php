<?php

declare(strict_types=1);

namespace Fixtures\TestQuality;

use Closure;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MechanicsSmellTest extends TestCase
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

    public function testUsesTooManyMocks(): void
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

    public function testPrivateReflection(): void
    {
        $method = new ReflectionMethod(OrderService::class, 'secretTotal');
        $method->setAccessible(true);

        self::assertSame('secret', $method->invoke(new OrderService()));
    }

    public function testReflectionClassReachesPrivate(): void
    {
        $reflection = new ReflectionClass(OrderService::class);
        $property = $reflection->getProperty('secret');
        $property->setAccessible(true);

        self::assertSame('value', $property->getValue(new OrderService()));
    }

    public function testClosureBindStealsAccess(): void
    {
        $extractor = function (): string {
            return $this->secret;
        };

        $bound = Closure::bind($extractor, new OrderService(), OrderService::class);

        self::assertSame('value', $bound());
    }

    public function testMysteryGuest(): void
    {
        $payload = file_get_contents('/tmp/order.json');

        self::assertSame('{}', $payload);
    }

    public function testMagicNumber(): void
    {
        self::assertSame(42, $this->service->countOrders());
    }

    /**
     * @dataProvider orderCases
     */
    public function testDataProviderAnnotation(string $status): void
    {
        self::assertSame('paid', $status);
    }

    public function testSnapshotOnTinyValue(): void
    {
        $this->assertMatchesSnapshot('ok');
    }

    public function testSkipWithoutReason(): void
    {
        $this->markTestSkipped();
    }
}
