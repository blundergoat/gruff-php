<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\StaticAnalysisRedundantTest;

use PHPUnit\Framework\TestCase;

final class ShapeService
{
    public string $label = 'shape';

    public function label(): string
    {
        return $this->label;
    }
}

interface ShapeContract
{
    public function handle(): void;
}

trait ShapeTrait
{
    public function helper(): void
    {
    }
}

enum ShapeStatus
{
    case Ready;
}

final class ShapeFactory
{
    public static function build(): ShapeService
    {
        return new ShapeService();
    }
}

final class StaticAnalysisRedundantCandidateTest extends TestCase
{
    public function testFlagsDirectClassLikeExistenceAssertions(): void
    {
        self::assertTrue(class_exists(ShapeService::class));
        self::assertTrue(interface_exists(ShapeContract::class));
        self::assertTrue(trait_exists(ShapeTrait::class));
        self::assertTrue(enum_exists(ShapeStatus::class));
    }

    public function testFlagsDirectMemberExistenceAssertions(): void
    {
        self::assertTrue(method_exists(ShapeService::class, 'label'));
        self::assertTrue(property_exists(ShapeService::class, 'label'));
    }

    public function testKeepsBehavioralValueAssertionClean(): void
    {
        $service = new ShapeService();

        self::assertSame('shape', $service->label());
    }

    public function testKeepsDynamicSymbolNamesClean(): void
    {
        $className = ShapeService::class;
        $methodName = 'label';

        self::assertTrue(class_exists($className));
        self::assertTrue(method_exists(ShapeService::class, $methodName));
    }

    public function testKeepsExternalRuntimeContractClean(): void
    {
        self::assertTrue(class_exists(\DateTimeImmutable::class));
    }

    public function testKeepsFactoryReturnTypeAssertionDeferred(): void
    {
        $service = ShapeFactory::build();

        self::assertInstanceOf(ShapeService::class, $service);
    }
}
