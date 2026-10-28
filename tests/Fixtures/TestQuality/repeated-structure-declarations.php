<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\RepeatedStructureDeclarations;

use PHPUnit\Framework\TestCase;

final class NestedFunctionTest extends TestCase
{
    public function testAlpha(): void { function helper() { return 0; } $value = helper(); self::assertTrue($value); }
    public function testBeta(): void { function helper() { return 1; } $value = helper(); self::assertTrue($value); }
    public function testGamma(): void { function helper() { return 2; } $value = helper(); self::assertTrue($value); }
}

final class NestedFunctionInBlockTest extends TestCase
{
    public function testAlpha(): void { if ($ready) { function helper() { return 0; } } $value = helper(); self::assertTrue($value); }
    public function testBeta(): void { if ($ready) { function helper() { return 1; } } $value = helper(); self::assertTrue($value); }
    public function testGamma(): void { if ($ready) { function helper() { return 2; } } $value = helper(); self::assertTrue($value); }
}

final class AnonymousClassTest extends TestCase
{
    public function testAlpha(): void { $helper = new class { public function value() { return 0; } }; self::assertTrue($helper->value()); }
    public function testBeta(): void { $helper = new class { public function value() { return 1; } }; self::assertTrue($helper->value()); }
    public function testGamma(): void { $helper = new class { public function value() { return 2; } }; self::assertTrue($helper->value()); }
}

final class NestedClassInClosureTest extends TestCase
{
    public function testAlpha(): void { $helper = function () { return new class { public function value() { return 0; } }; }; self::assertTrue($helper); }
    public function testBeta(): void { $helper = function () { return new class { public function value() { return 1; } }; }; self::assertTrue($helper); }
    public function testGamma(): void { $helper = function () { return new class { public function value() { return 2; } }; }; self::assertTrue($helper); }
}

final class DeclarationDocumentationTest extends TestCase
{
    public function testAlpha(): void { /** case0 */ function helper() { return 1; } $value = helper(); self::assertTrue($value); }
    public function testBeta(): void { /** case1 */ function helper() { return 1; } $value = helper(); self::assertTrue($value); }
    public function testGamma(): void { /** case2 */ function helper() { return 1; } $value = helper(); self::assertTrue($value); }
}

final class BodyAttributeTest extends TestCase
{
    public function testAlpha(): void { $helper = #[CaseValue(0)] function () { return 1; }; self::assertTrue($helper()); }
    public function testBeta(): void { $helper = #[CaseValue(1)] function () { return 1; }; self::assertTrue($helper()); }
    public function testGamma(): void { $helper = #[CaseValue(2)] function () { return 1; }; self::assertTrue($helper()); }
}

final class ClosureValuesTest extends TestCase
{
    public function testAlpha(): void { $helper = function () { return 0; }; self::assertTrue($helper()); }
    public function testBeta(): void { $helper = function () { return 1; }; self::assertTrue($helper()); }
    public function testGamma(): void { $helper = function () { return 2; }; self::assertTrue($helper()); }
}

final class IdenticalDeclarationTest extends TestCase
{
    public function testAlpha(): void { function helper() { return 1; } $value = read(0); self::assertTrue($value); }
    public function testBeta(): void { function helper() { return 1; } $value = read(1); self::assertTrue($value); }
    public function testGamma(): void { function helper() { return 1; } $value = read(2); self::assertTrue($value); }
}
