<?php

declare(strict_types=1);

namespace Fixtures\TestQuality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoreSmellTest extends TestCase
{
    public function testNoAssertion(): void
    {
        $this->buildInvoice();
    }

    #[Test]
    public function attributeNoAssertion(): void
    {
        $this->buildInvoice();
    }

    /**
     * @test
     */
    public function annotationNoAssertion(): void
    {
        $this->buildInvoice();
    }

    public function testTrivialAssertion(): void
    {
        $this->assertTrue(true);
        $this->buildInvoice();
    }

    public function testConditionalCalculateTotal(): void
    {
        $total = $this->calculateTotal();
        if ($total > 0) {
            self::assertSame(2, $total);
        }
    }

    public function testLoopCalculateTotal(): void
    {
        foreach ([1, 2] as $amount) {
            self::assertSame($amount, $this->calculateTotal());
        }
    }

    public function testSleepCalculateTotal(): void
    {
        sleep(1);
        self::assertSame(2, $this->calculateTotal());
    }

    public function testTimeReadsCurrentClock(): void
    {
        $now = time();
        self::assertGreaterThan(0, $now);
    }

    public function testMicrotimeReadsCurrentClock(): void
    {
        $now = microtime(true);
        self::assertGreaterThan(0.0, $now);
    }

    public function testDateTimeNowConstruction(): void
    {
        $now = new \DateTime('now');
        self::assertNotNull($now->format('U'));
    }

    public function testDateTimeImmutableNoArg(): void
    {
        $now = new \DateTimeImmutable();
        self::assertNotNull($now->format('U'));
    }

    public function testFrozenDateTimeIsNotFlagged(): void
    {
        $frozen = new \DateTime('2026-05-10T00:00:00Z');
        self::assertSame('1778976000', $frozen->format('U'));
    }

    private function buildInvoice(): void
    {
    }

    private function calculateTotal(): int
    {
        return 2;
    }
}
