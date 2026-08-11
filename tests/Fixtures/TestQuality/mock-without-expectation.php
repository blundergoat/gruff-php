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

    // Negative: each Prophecy prediction/configuration method makes the double intentional.
    public function testConfiguresPropheciesWithTheirNativeVocabulary(): void
    {
        $returningProphecy = $this->prophesize(OrderService::class);
        $returningProphecy->name()->willReturn('ok');

        $expectedProphecy = $this->prophesize(OrderService::class);
        $expectedProphecy->name()->shouldBeCalled();

        $singleCallProphecy = $this->prophesize(OrderService::class);
        $singleCallProphecy->name()->shouldBeCalledOnce();

        $countedProphecy = $this->prophesize(OrderService::class);
        $countedProphecy->name()->shouldBeCalledTimes(2);

        $observedProphecy = $this->prophesize(OrderService::class);
        $observedProphecy->name()->shouldHaveBeenCalled();

        $forbiddenProphecy = $this->prophesize(OrderService::class);
        $forbiddenProphecy->name()->shouldNotHaveBeenCalled();

        $throwingProphecy = $this->prophesize(OrderService::class);
        $throwingProphecy->name()->willThrow(\RuntimeException::class);

        $callbackProphecy = $this->prophesize(OrderService::class);
        $callbackProphecy->name()->willReturnCallback(static fn(): string => 'ok');

        self::assertTrue(true);
    }

    // Negative: comparing reveal() inside an assertion verifies the exposed double identity.
    public function testAssertsAgainstRevealedProphecy(): void
    {
        $assertedProphecy = $this->prophesize(OrderService::class);

        self::assertSame($assertedProphecy->reveal(), (new Caller())->service());
    }

    // Positive: reveal() passed only to the subject does not configure or verify the prophecy.
    public function testPassesBareProphecyToSubject(): void
    {
        $bareProphecy = $this->prophesize(OrderService::class);

        (new Caller())->call($bareProphecy->reveal());

        self::assertTrue(true);
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

    // Negative: the assigned value is a fake; only its constructor dependency is a PHPUnit stub.
    public function testFakeWithStubDependencyIsNotTreatedAsMock(): void
    {
        $fake = new CapturingFake($this->createStub(TokenProvider::class));

        $caller = new Caller();

        self::assertSame('ok', $caller->call($fake));
    }
}
