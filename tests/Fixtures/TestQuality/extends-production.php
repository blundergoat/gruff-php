<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\ExtendsProduction;

use PHPUnit\Framework\TestCase;

class OrderService
{
    public function process(): string
    {
        return 'ok';
    }
}

class CustomTestCase extends TestCase
{
}

// Positive: test class extends a production class instead of a *TestCase base.
final class OrderServiceTest extends OrderService
{
    public function testExtendsProductionInsteadOfTestBase(): void
    {
        // intentionally hollow.
    }
}

// Negative: test class extends a recognised *TestCase base, no finding.
final class OrderServiceProperTest extends TestCase
{
    public function testProperBase(): void
    {
        self::assertSame('ok', (new OrderService())->process());
    }
}

// Edge: test class extends a project-specific *TestCase descendant - still a test base, no finding.
final class OrderServiceCustomBaseTest extends CustomTestCase
{
    public function testCustomBase(): void
    {
        self::assertSame('ok', (new OrderService())->process());
    }
}
