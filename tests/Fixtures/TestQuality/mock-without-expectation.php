<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\MockWithoutExpectation;

use PHPUnit\Framework\TestCase;

final class MockWithoutExpectationTest extends TestCase
{
    // Positive (dead-mock variant, warning): mock created, passed to the SUT, no expects()/willReturn().
    public function testCreatesMockAndPassesItWithoutExpectation(): void
    {
        $service = $this->createMock(OrderService::class);

        $caller = new Caller();

        self::assertSame('ok', $caller->call($service));
    }

    // Positive (stub-only variant, advisory): willReturn but no expects().
    public function testStubsReturnValueWithoutVerification(): void
    {
        $service = $this->createMock(OrderService::class);
        $service->method('name')->willReturn('ok');

        $caller = new Caller();

        self::assertSame('ok', $caller->call($service));
    }

    // Negative: full expectation chain - not flagged.
    public function testSetsUpFullExpectation(): void
    {
        $service = $this->createMock(OrderService::class);
        $service->expects(self::once())->method('name')->willReturn('ok');

        $caller = new Caller();

        self::assertSame('ok', $caller->call($service));
    }

    // Negative: variable never read at all - that is unused-mock's territory, not this rule's.
    public function testCreatesMockNeverReferenced(): void
    {
        $unused = $this->createMock(OrderService::class);

        self::assertSame(1, 1);
    }

    // Edge: a real instance, not a mock, is not the rule's concern.
    public function testRealServiceUsedDirectly(): void
    {
        $service = new \stdClass();

        $caller = new Caller();

        self::assertSame('ok', $caller->call($service));
    }
}
