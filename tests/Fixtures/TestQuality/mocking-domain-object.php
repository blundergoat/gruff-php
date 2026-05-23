<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\MockingDomainObject;

use App\Domain\Order;
use App\Infrastructure\HttpClient;
use PHPUnit\Framework\TestCase;

final class MockingDomainObjectTest extends TestCase
{
    // Positive: imported short name resolves to App\Domain\Order, which matches the App\Domain\* glob.
    public function testMocksImportedDomainObject(): void
    {
        $order = $this->createMock(Order::class);

        self::assertNotNull($order);
    }

    // Positive: explicit FQN of a domain object.
    public function testMocksFullyQualifiedDomainObject(): void
    {
        $order = $this->createMock(\App\Domain\Customer::class);

        self::assertNotNull($order);
    }

    // Negative: mocking an infrastructure collaborator is fine; outside the domain glob.
    public function testMocksInfrastructureService(): void
    {
        $http = $this->createMock(HttpClient::class);

        self::assertNotNull($http);
    }

    // Edge: real domain object construction is not the rule's concern.
    public function testUsesRealDomainObject(): void
    {
        $order = new Order();

        self::assertNotNull($order);
    }
}
