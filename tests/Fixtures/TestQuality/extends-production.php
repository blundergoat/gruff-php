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

class WC_Unit_Test_Case extends TestCase
{
}

class Email_Editor_Integration_Test_Case extends TestCase
{
}

class IntegrationTestBase
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

// Edge: snake_case *_Test_Case parent still spells *TestCase once underscores are removed - no finding.
final class SnakeCaseBaseTest extends WC_Unit_Test_Case
{
    public function testSnakeCaseBase(): void
    {
        self::assertSame('ok', (new OrderService())->process());
    }
}

// Edge: longer snake_case *_Test_Case parent is also a recognised test base - no finding.
final class EmailEditorIntegrationTest extends Email_Editor_Integration_Test_Case
{
    public function testLongerSnakeCaseBase(): void
    {
        self::assertSame('ok', (new OrderService())->process());
    }
}

// Positive by default: a base matching neither *TestCase shape flags until additionalTestBaseClasses accepts it.
final class LegacyIntegrationTest extends IntegrationTestBase
{
    public function testUnrecognisedConfigurableBase(): void
    {
        self::assertSame('ok', (new OrderService())->process());
    }
}
