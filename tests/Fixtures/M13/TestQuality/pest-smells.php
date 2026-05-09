<?php

declare(strict_types=1);

use function Pest\Faker\fake;

it('has no assertion', function (): void {
    fake()->name();
});

test('has a trivial assertion', function (): void {
    expect(true)->toBeTrue();
});

it('snapshots a tiny value', function (): void {
    expect('ok')->toMatchSnapshot();
});
