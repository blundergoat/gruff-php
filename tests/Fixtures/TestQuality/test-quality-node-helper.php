<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\NodeHelper;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HelperEdgeCaseTest extends TestCase
{
    #[Test]
    public function attributeStyle(): void
    {
        self::assertSame('same', 'same');
    }

    /**
     * @test
     */
    public function annotationStyle(): void
    {
        expect(true)->toBeTrue();
        expect(false)->toBeFalse();
        expect('x')->toBe('x')->toEqual('x');
    }

    public function testPrefixStyle(): void
    {
        helper('work');

        self::assertTrue(true);
        self::assertEquals(3, 3);
        self::assertSame(5, 6);
        $this->fail('stop');
    }

    public function testTrivialAssertions(): void
    {
        self::assertTrue(true);
        self::assertFalse(false);
        self::assertEquals('x', 'x');
        self::assertSame(2, 2);
        self::assertSame(2, 3);

        expect(true)->toBeTrue();
        expect(false)->toBeFalse();
        expect('x')->toBe('x');
        expect('x')->toEqual('x');
        expect('x')->toBe('y');
        AssertLike::toBeTrue(true);
        AssertLike::toBeFalse(false);
    }

    public function testLiteralAndMagicNumbers(): void
    {
        self::assertSame(-1, $negativeOne);
        self::assertSame(0, $zero);
        self::assertSame(1, $one);
        self::assertSame(2, $two);
        expect($items)->toHaveCount(4);
        expect($value)->toBe(3);
        helper('literal', TRUE, FALSE, NULL, $dynamic);
        helper(2);
        helper(MAYBE);
        $callable('dynamic');
    }

    public function testMockApi(): void
    {
        $this->createMock(Service::class);
        $this->createStub(Service::class);
        $this->getMockBuilder(Service::class);
        mock(Service::class);
        partialMock(Service::class);
        spy(Service::class);
        prophesize(Service::class);

        $mock->expects($this->once())->method('run')->with('x');
        $mock->shouldReceive('run')->never();
        $mock->shouldHaveBeenCalled();
        $service->run();
    }

    public function helperMethod(): void
    {
        helper('not a test');
    }

    /**
     * Mentions @test annotation prose without declaring the tag.
     *
     * @return void No return value.
     */
    public function annotationDocumentationOnly(): void
    {
        helper('not a test');
    }
}

new class extends TestCase
{
    /**
     * @test
     */
    public function anonymousExample(): void
    {
        self::assertTrue(true);
    }
};

final class StartsWithTestButNoBase
{
    public function testScopes(): void
    {
        helper('not a phpunit test');
    }
}

it('pest description', function (): void {
    expect(['a'])->toHaveCount(1);
    helper('pest');
});

test('explicit pest test', function (): void {
    expect('ok')->toMatchSnapshot();
});

test('too few args');
$dynamic('ignored', function (): void {
    helper('dynamic');
});

function helper(mixed ...$values): void
{
}
