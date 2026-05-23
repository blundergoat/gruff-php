<?php

declare(strict_types=1);

namespace Fixtures\TestQuality;

use PHPUnit\Framework\TestCase;

final class MagicNumberHeuristicTest extends TestCase
{
    public function testOpaqueBusinessNumberIsFlagged(): void
    {
        self::assertSame(42, (new OrderService())->countOrders());
    }

    public function testContextualNumericContractsAreAllowed(): void
    {
        $settings = new RuleSettingsFixture();
        $report = [
            'summary' => [
                'exitCode' => 2,
                'parseErrors' => 2,
            ],
        ];
        $finding = new FindingFixture();

        self::assertSame(2, $settings->getExitCode());
        self::assertSame(800, $settings->numericThreshold('error'));
        self::assertSame(2, $report['summary']['exitCode'] ?? null);
        self::assertSame(2, $report['summary']['parseErrors'] ?? null);
        self::assertSame(6, $finding->metadata['parameters']);
        self::assertSame(31, $finding->metadata['lines']);
        self::assertCount(2, ['first', 'second']);
        self::assertLessThanOrEqual(3, count(['first', 'second']));
    }
}
