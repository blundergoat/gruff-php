<?php

declare(strict_types=1);

it('exercises the documented Pest expectation API', function (): void {
    expect(null)->toBeNull();
    expect(true)->toBeTruthy();
    expect(false)->toBeFalsy();

    expect([])->toBeArray();
    expect(1)->toBeInt();
    expect('s')->toBeString();
    expect(true)->toBeBool();
    expect(1.5)->toBeFloat();
    expect(new stdClass())->toBeObject();
    expect(fn () => 0)->toBeCallable();
    expect([])->toBeIterable();
    expect(1)->toBeNumeric();
    expect(1)->toBeScalar();

    expect([])->toBeEmpty();
    expect(2)->toBeEven();
    expect(3)->toBeOdd();
    expect(5)->toBeGreaterThan(3);
    expect(5)->toBeGreaterThanOrEqual(5);
    expect(3)->toBeLessThan(5);
    expect(3)->toBeLessThanOrEqual(3);
    expect(5)->toBeBetween(1, 10);
    expect(5)->toBePositive();
    expect(-5)->toBeNegative();
    expect(INF)->toBeInfinite();
    expect(NAN)->toBeNan();

    expect(new stdClass())->toBeInstanceOf(stdClass::class);

    expect(['a' => 1])->toEqual(['a' => 1]);
    expect(['a' => 1, 'b' => 2])->toEqualCanonicalizing(['b' => 2, 'a' => 1]);
    expect(1.0001)->toEqualWithDelta(1.0, 0.001);

    expect([1, 2])->toContain(2);
    expect([1, 2])->toContainEqual(2);
    expect([1, 2])->toContainOnly('int');
    expect([new stdClass()])->toContainOnlyInstancesOf(stdClass::class);

    expect(['a' => 1])->toHaveKey('a');
    expect(['a' => 1, 'b' => 2])->toHaveKeys(['a', 'b']);
    expect('abc')->toHaveLength(3);
    expect((object) ['a' => 1])->toHaveProperty('a');
    expect((object) ['a' => 1, 'b' => 2])->toHaveProperties(['a', 'b']);
    expect([1, 2, 3])->toHaveSameSize([4, 5, 6]);

    expect('abc')->toMatch('/^a/');
    expect(['a' => 1])->toMatchArray(['a' => 1]);
    expect((object) ['a' => 1])->toMatchObject(['a' => 1]);
    expect('ok')->toMatchSnapshot();
    expect('contents')->toMatchFileSnapshot('/tmp/snap.txt');

    expect('abcdef')->toStartWith('abc');
    expect('abcdef')->toEndWith('def');

    expect(fn () => throw new RuntimeException('x'))->toThrow(RuntimeException::class);
    expect(fn () => throw new RuntimeException('x'))->toThrowIf(true, RuntimeException::class);
    expect(fn () => null)->toThrowUnless(false, RuntimeException::class);
});
