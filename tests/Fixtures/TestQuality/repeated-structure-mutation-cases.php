<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\RepeatedStructureMutationCases;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

new class extends TestCase
{
    /**
     * @test
     */
    public function anonymousNoise(): void
    {
        self::assertTrue(true);
        self::assertFalse(false);
    }
};

final class ContinuePastNonTestMethodTest extends TestCase
{
    public function helperFirst(): void
    {
        helperAlpha();
    }

    public function testAlpha(): void
    {
        $service = new OrderService();
        self::assertSame(1, $service->sum('a'));
    }

    public function testBeta(): void
    {
        $service = new OrderService();
        self::assertSame(2, $service->sum('b'));
    }

    public function testGamma(): void
    {
        $service = new OrderService();
        self::assertSame(3, $service->sum('c'));
    }
}

final class ContinuePastShortMethodTest extends TestCase
{
    public function testShort(): void
    {
        self::assertTrue(true);
    }

    public function testAlpha(): void
    {
        $service = new OrderService();
        self::assertSame(1, $service->sum('a'));
    }

    public function testBeta(): void
    {
        $service = new OrderService();
        self::assertSame(2, $service->sum('b'));
    }

    public function testGamma(): void
    {
        $service = new OrderService();
        self::assertSame(3, $service->sum('c'));
    }
}

final class ContinuePastProviderTest extends TestCase
{
    #[DataProvider('cases')]
    public function testAlreadyParameterized(int $value): void
    {
        self::assertSame($value, $value);
        self::assertIsInt($value);
    }

    public function testShapeAlpha(): void
    {
        $service = new OrderService();
        self::assertSame(1, $service->sum('a'));
    }

    public function testShapeBeta(): void
    {
        $service = new OrderService();
        self::assertSame(2, $service->sum('b'));
    }

    public function testShapeGamma(): void
    {
        $service = new OrderService();
        self::assertSame(3, $service->sum('c'));
    }

    public static function cases(): array
    {
        return [[1], [2], [3]];
    }
}

final class ContinuePastSmallGroupTest extends TestCase
{
    public function testPairOne(): void
    {
        $service = new OrderService();
        self::assertSame(1, $service->pair('a'));
    }

    public function testPairTwo(): void
    {
        $service = new OrderService();
        self::assertSame(2, $service->pair('b'));
    }

    public function testRealAlpha(): void
    {
        $service = new OtherService();
        self::assertSame(1, $service->sum('a'));
    }

    public function testRealBeta(): void
    {
        $service = new OtherService();
        self::assertSame(2, $service->sum('b'));
    }

    public function testRealGamma(): void
    {
        $service = new OtherService();
        self::assertSame(3, $service->sum('c'));
    }
}

final class NonProviderAttributeStillAnalysedTest extends TestCase
{
    #[Test]
    public function testDecoratedAlpha(): void
    {
        $service = new DecoratedService();
        self::assertSame(1, $service->sum('a'));
    }

    #[Test]
    public function testDecoratedBeta(): void
    {
        $service = new DecoratedService();
        self::assertSame(2, $service->sum('b'));
    }

    #[Test]
    public function testDecoratedGamma(): void
    {
        $service = new DecoratedService();
        self::assertSame(3, $service->sum('c'));
    }
}

final class AttributeProviderSkippedTest extends TestCase
{
    #[DATAPROVIDER('cases')]
    public function testProvidedAlpha(int $value): void
    {
        $service = new ProvidedService();
        self::assertSame($value, $service->sum('a'));
    }

    #[DATAPROVIDER('cases')]
    public function testProvidedBeta(int $value): void
    {
        $service = new ProvidedService();
        self::assertSame($value, $service->sum('b'));
    }

    #[DATAPROVIDER('cases')]
    public function testProvidedGamma(int $value): void
    {
        $service = new ProvidedService();
        self::assertSame($value, $service->sum('c'));
    }

    public static function cases(): array
    {
        return [[1], [2], [3]];
    }
}

final class DocblockProviderSkippedTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testProvidedAlpha(int $value): void
    {
        $service = new DocblockProvidedService();
        self::assertSame($value, $service->sum('a'));
    }

    /**
     * @dataProvider cases
     */
    public function testProvidedBeta(int $value): void
    {
        $service = new DocblockProvidedService();
        self::assertSame($value, $service->sum('b'));
    }

    /**
     * @dataProvider cases
     */
    public function testProvidedGamma(int $value): void
    {
        $service = new DocblockProvidedService();
        self::assertSame($value, $service->sum('c'));
    }

    public static function cases(): array
    {
        return [[1], [2], [3]];
    }
}

final class NewClassTokenCollisionTest extends TestCase
{
    public function testBuildsAlpha(): void
    {
        $service = new AlphaService();
        self::assertNotNull($service);
    }

    public function testBuildsBeta(): void
    {
        $service = new BetaService();
        self::assertNotNull($service);
    }

    public function testBuildsGamma(): void
    {
        $service = new GammaService();
        self::assertNotNull($service);
    }
}

final class FunctionTokenCollisionTest extends TestCase
{
    public function testCallsAlpha(): void
    {
        helperAlpha();
        self::assertTrue(true);
    }

    public function testCallsBeta(): void
    {
        helperBeta();
        self::assertTrue(true);
    }

    public function testCallsGamma(): void
    {
        helperGamma();
        self::assertTrue(true);
    }
}

final class MethodTokenCollisionTest extends TestCase
{
    public function testCallsAlpha(): void
    {
        $service->alpha();
        self::assertTrue(true);
    }

    public function testCallsBeta(): void
    {
        $service->beta();
        self::assertTrue(true);
    }

    public function testCallsGamma(): void
    {
        $service->gamma();
        self::assertTrue(true);
    }
}

final class StaticClassTokenCollisionTest extends TestCase
{
    public function testCallsAlpha(): void
    {
        AlphaService::run();
        self::assertTrue(true);
    }

    public function testCallsBeta(): void
    {
        BetaService::run();
        self::assertTrue(true);
    }

    public function testCallsGamma(): void
    {
        GammaService::run();
        self::assertTrue(true);
    }
}

final class StaticMethodTokenCollisionTest extends TestCase
{
    public function testCallsAlpha(): void
    {
        SharedService::alpha();
        self::assertTrue(true);
    }

    public function testCallsBeta(): void
    {
        SharedService::beta();
        self::assertTrue(true);
    }

    public function testCallsGamma(): void
    {
        SharedService::gamma();
        self::assertTrue(true);
    }
}

final class ControlFlowTokenCollisionTest extends TestCase
{
    public function testIfBranch(): void
    {
        if ($flag) {
            helperAlpha();
        }

        self::assertTrue(true);
    }

    public function testForeachBranch(): void
    {
        foreach ([1] as $item) {
            helperAlpha();
        }

        self::assertTrue(true);
    }

    public function testForBranch(): void
    {
        for ($i = 0; $i < 1; $i++) {
            helperAlpha();
        }

        self::assertTrue(true);
    }

    public function testWhileBranch(): void
    {
        while ($flag) {
            helperAlpha();
        }

        self::assertTrue(true);
    }

    public function testReturnBranch(): void
    {
        self::assertTrue(true);

        return;
    }

    public function testThrowBranch(): void
    {
        if ($flag) {
            throw new \RuntimeException('boom');
        }

        self::assertTrue(true);
    }
}
