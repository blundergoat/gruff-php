<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\RepeatedStructureGroups;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LiteralGroupTest extends TestCase
{
    public function testAlpha(): void { $service = new OrderService(); self::assertSame(1, $service->sum('a')); }
    public function testBeta(): void { $service = new OrderService(); self::assertSame(2, $service->sum('b')); }
    public function testGamma(): void { $service = new OrderService(); self::assertSame(3, $service->sum('c')); }
    public function testDelta(): void { $service = new OrderService(); self::assertSame(4, $service->sum('d')); }
}

final class MatchingAttributesTest extends TestCase
{
    #[Group('fast')]
    public function testAlpha(): void { $service = new OrderService(); self::assertSame(1, $service->sum('a')); }
    #[Group('fast')]
    public function testBeta(): void { $service = new OrderService(); self::assertSame(2, $service->sum('b')); }
    #[Group('fast')]
    public function testGamma(): void { $service = new OrderService(); self::assertSame(3, $service->sum('c')); }
}

final class DifferentAttributesTest extends TestCase
{
    #[Group('alpha')]
    public function testAlpha(): void { $service = new OrderService(); self::assertSame(1, $service->sum('a')); }
    #[Group('beta')]
    public function testBeta(): void { $service = new OrderService(); self::assertSame(2, $service->sum('b')); }
    #[Group('gamma')]
    public function testGamma(): void { $service = new OrderService(); self::assertSame(3, $service->sum('c')); }
}

final class MatchingAnnotationsTest extends TestCase
{
    /**
     * Alpha scenario.
     * @group fast
     */
    public function testAlpha(): void { $service = new OrderService(); self::assertSame(1, $service->sum('a')); }
    /**
     * Beta scenario.
     * @group fast
     */
    public function testBeta(): void { $service = new OrderService(); self::assertSame(2, $service->sum('b')); }
    /**
     * Gamma scenario.
     * @group fast
     */
    public function testGamma(): void { $service = new OrderService(); self::assertSame(3, $service->sum('c')); }
}

final class DifferentAnnotationsTest extends TestCase
{
    /** @group alpha */
    public function testAlpha(): void { $service = new OrderService(); self::assertSame(1, $service->sum('a')); }
    /** @group beta */
    public function testBeta(): void { $service = new OrderService(); self::assertSame(2, $service->sum('b')); }
    /** @group gamma */
    public function testGamma(): void { $service = new OrderService(); self::assertSame(3, $service->sum('c')); }
}

final class WhitespacePayloadTest extends TestCase
{
    public function testAlpha(): void { $value = read('scenario alpha'); self::assertNotEmpty($value); }
    public function testBeta(): void { $value = read('scenario beta'); self::assertNotEmpty($value); }
    public function testGamma(): void { $value = read('scenario gamma'); self::assertNotEmpty($value); }
}

final class MatchingPayloadTest extends TestCase
{
    public function testAlpha(): void { $value = read("same\nscenario"); self::assertSame(1, $value); }
    public function testBeta(): void { $value = read("same\nscenario"); self::assertSame(2, $value); }
    public function testGamma(): void { $value = read("same\nscenario"); self::assertSame(3, $value); }
}

final class InterpolatedPayloadTest extends TestCase
{
    public function testAlpha(): void { $value = "scenario alpha {$name}"; self::assertNotEmpty($value); }
    public function testBeta(): void { $value = "scenario beta {$name}"; self::assertNotEmpty($value); }
    public function testGamma(): void { $value = "scenario gamma {$name}"; self::assertNotEmpty($value); }
}

final class MatchingArrayKeysTest extends TestCase
{
    public function testAlpha(): void { $data = ['field' => 1]; self::assertNotEmpty($data); }
    public function testBeta(): void { $data = ['field' => 2]; self::assertNotEmpty($data); }
    public function testGamma(): void { $data = ['field' => 3]; self::assertNotEmpty($data); }
}

final class DifferentArrayKeysTest extends TestCase
{
    public function testAlpha(): void { $data = ['alpha' => 1]; self::assertNotEmpty($data); }
    public function testBeta(): void { $data = ['beta' => 2]; self::assertNotEmpty($data); }
    public function testGamma(): void { $data = ['gamma' => 3]; self::assertNotEmpty($data); }
}

final class DifferentStringIndicesTest extends TestCase
{
    public function testAlpha(): void { $value = $record['alpha']; self::assertNotEmpty($value); }
    public function testBeta(): void { $value = $record['beta']; self::assertNotEmpty($value); }
    public function testGamma(): void { $value = $record['gamma']; self::assertNotEmpty($value); }
}

final class DifferentNumberIndicesTest extends TestCase
{
    public function testAlpha(): void { $value = $record[0]; self::assertNotEmpty($value); }
    public function testBeta(): void { $value = $record[1]; self::assertNotEmpty($value); }
    public function testGamma(): void { $value = $record[2]; self::assertNotEmpty($value); }
}

final class DifferentOperatorsTest extends TestCase
{
    public function testAlpha(): void { $value = $left + 1; self::assertSame(1, $value); }
    public function testBeta(): void { $value = $left - 2; self::assertSame(2, $value); }
    public function testGamma(): void { $value = $left * 3; self::assertSame(3, $value); }
}

final class DifferentNamesTest extends TestCase
{
    public function testAlpha(): void { $alpha = read('a'); self::assertNotEmpty($alpha); }
    public function testBeta(): void { $beta = read('b'); self::assertNotEmpty($beta); }
    public function testGamma(): void { $gamma = read('c'); self::assertNotEmpty($gamma); }
}

final class DifferentKindsTest extends TestCase
{
    public function testAlpha(): void { $value = read('1'); self::assertNotEmpty($value); }
    public function testBeta(): void { $value = read(1); self::assertNotEmpty($value); }
    public function testGamma(): void { $value = read(true); self::assertNotEmpty($value); }
}

final class PairOneTest extends TestCase
{
    public function testAlpha(): void { $service = new OrderService(); self::assertSame(1, $service->sum('a')); }
    public function testBeta(): void { $service = new OrderService(); self::assertSame(2, $service->sum('b')); }
}

final class PairTwoTest extends TestCase
{
    public function testGamma(): void { $service = new OrderService(); self::assertSame(3, $service->sum('c')); }
    public function testDelta(): void { $service = new OrderService(); self::assertSame(4, $service->sum('d')); }
}

final class SingleStatementWithCommentsTest extends TestCase
{
    public function testAlpha(): void
    {
        self::assertTrue(true);
        // The trailing comment is not a second statement.
    }
    public function testBeta(): void
    {
        self::assertTrue(false);
        // The trailing comment is not a second statement.
    }
    public function testGamma(): void
    {
        self::assertTrue(true);
        // The trailing comment is not a second statement.
    }
}

final class InlineTableTest extends TestCase
{
    public function testAlpha(): void { $service = new OrderService(); foreach ([1, 2] as $value) { self::assertSame($value, $service->sum($value)); } }
    public function testBeta(): void { $service = new OrderService(); foreach ([3, 4] as $value) { self::assertSame($value, $service->sum($value)); } }
    public function testGamma(): void { $service = new OrderService(); foreach ([5, 6] as $value) { self::assertSame($value, $service->sum($value)); } }
}

final class ExternalProviderTest extends TestCase
{
    #[DataProviderExternal(SharedCases::class, 'rows')]
    public function testAlpha(int $value): void { $service = new OrderService(); self::assertSame($value, $service->sum('a')); }
    #[DataProviderExternal(SharedCases::class, 'rows')]
    public function testBeta(int $value): void { $service = new OrderService(); self::assertSame($value, $service->sum('b')); }
    #[DataProviderExternal(SharedCases::class, 'rows')]
    public function testGamma(int $value): void { $service = new OrderService(); self::assertSame($value, $service->sum('c')); }
}

final class InlineAttributeDataTest extends TestCase
{
    #[TestWith([1])]
    public function testAlpha(int $value): void { $service = new OrderService(); self::assertSame($value, $service->sum('a')); }
    #[TestWith([1])]
    public function testBeta(int $value): void { $service = new OrderService(); self::assertSame($value, $service->sum('b')); }
    #[TestWith([1])]
    public function testGamma(int $value): void { $service = new OrderService(); self::assertSame($value, $service->sum('c')); }
}

final class AssignedTableTest extends TestCase
{
    public function testAlpha(): void { $cases = [1, 2]; foreach ($cases as $value) { self::assertSame($value, transform($value)); } }
    public function testBeta(): void { $cases = [3, 4]; foreach ($cases as $value) { self::assertSame($value, transform($value)); } }
    public function testGamma(): void { $cases = [5, 6]; foreach ($cases as $value) { self::assertSame($value, transform($value)); } }
}

final class ReassignedIterableTest extends TestCase
{
    public function testAlpha(): void { $cases = [1]; $cases = readRows('a'); foreach ($cases as $value) { self::assertNotEmpty($value); } }
    public function testBeta(): void { $cases = [2]; $cases = readRows('b'); foreach ($cases as $value) { self::assertNotEmpty($value); } }
    public function testGamma(): void { $cases = [3]; $cases = readRows('c'); foreach ($cases as $value) { self::assertNotEmpty($value); } }
}

final class AnnotationArgumentTest extends TestCase
{
    /** @group @testWith */
    public function testAlpha(): void { $value = read('a'); self::assertNotEmpty($value); }
    /** @group @testWith */
    public function testBeta(): void { $value = read('b'); self::assertNotEmpty($value); }
    /** @group @testWith */
    public function testGamma(): void { $value = read('c'); self::assertNotEmpty($value); }
}
