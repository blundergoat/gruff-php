<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\EmptyDataProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptyDataProviderTest extends TestCase
{
    // Positive: provider literally returns an empty array.
    #[DataProvider('emptyArrayProvider')]
    public function testWithEmptyArrayProvider(string $value): void
    {
        self::assertSame('', $value);
    }

    // Positive: legacy @dataProvider annotation pointing at an empty body.
    /**
     * @dataProvider noStatementsProvider
     */
    public function testWithNoStatementProvider(string $value): void
    {
        self::assertSame('', $value);
    }

    // Negative: provider returns non-empty rows.
    #[DataProvider('populatedProvider')]
    public function testWithPopulatedProvider(string $value): void
    {
        self::assertNotSame('', $value);
    }

    // Edge: yielding provider. Cannot prove empty without execution, treat as non-empty.
    #[DataProvider('yieldingProvider')]
    public function testWithYieldingProvider(string $value): void
    {
        self::assertNotSame('', $value);
    }

    public static function emptyArrayProvider(): array
    {
        return [];
    }

    public static function noStatementsProvider(): array
    {
    }

    public static function populatedProvider(): array
    {
        return [
            ['first'],
            ['second'],
        ];
    }

    public static function yieldingProvider(): \Generator
    {
        yield ['first'];
        yield ['second'];
    }
}
