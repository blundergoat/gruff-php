<?php

declare(strict_types=1);

namespace Fixtures\M13\TestQuality;

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

    private function buildInvoice(): void
    {
    }

    private function calculateTotal(): int
    {
        return 2;
    }
}
