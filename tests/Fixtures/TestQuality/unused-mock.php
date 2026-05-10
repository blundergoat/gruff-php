<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\UnusedMock;

use PHPUnit\Framework\TestCase;

final class UnusedMockTest extends TestCase
{
    // Positive: mock created with createMock and never referenced.
    public function testCreatesMockButNeverUsesIt(): void
    {
        $unused = $this->createMock(SomeService::class);

        self::assertSame('ok', 'ok');
    }

    // Positive: mock built via getMockBuilder()->getMock() and never referenced.
    public function testCreatesBuiltMockButNeverUsesIt(): void
    {
        $unused = $this->getMockBuilder(SomeService::class)->getMock();

        self::assertSame('ok', 'ok');
    }

    // Negative: mock is read after creation (passed to SUT).
    public function testCreatesMockAndPassesItToSut(): void
    {
        $service = $this->createMock(SomeService::class);

        $caller = new Caller();

        self::assertSame('ok', $caller->call($service));
    }

    // Negative: mock is read via expects() / method() chain.
    public function testCreatesMockAndSetsExpectations(): void
    {
        $mailer = $this->createMock(SomeMailer::class);
        $mailer->expects(self::once())->method('send');
    }

    // Edge: non-mock variable left unread is not the rule's concern.
    public function testCreatesPlainObjectButNeverUsesIt(): void
    {
        $unused = new \stdClass();

        self::assertSame('ok', 'ok');
    }
}
