<?php

declare(strict_types=1);

namespace Fixtures\Modernisation;

final class PhpDocMixedOveruseFixture
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $listOfMixedVar = [];

    /**
     * @var mixed
     */
    public mixed $standaloneMixedVar = null;

    /**
     * Reads a context bag.
     *
     * @param array<string, mixed> $context
     */
    public function arrayShapeMixedParam(array $context): void
    {
        unset($context);
    }

    /**
     * Returns a shaped record.
     *
     * @return array{x: mixed}
     */
    public function arrayShapeMixedReturn(): array
    {
        return ['x' => 1];
    }

    /**
     * Accepts a standalone mixed value.
     *
     * @param mixed $foo
     */
    public function standaloneMixedParam(mixed $foo): void
    {
        unset($foo);
    }

    /**
     * Returns standalone mixed.
     *
     * @return mixed
     */
    public function standaloneMixedReturn(): mixed
    {
        return null;
    }

    /**
     * Untyped signature documented as mixed-only.
     *
     * @param mixed $x
     */
    public function untypedSignatureMixedDoc($x): void
    {
        unset($x);
    }

    /**
     * Accepts a nested array shape.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function nestedArrayShapeMixed(array $items): void
    {
        unset($items);
    }

    /**
     * Returns a union that contains mixed.
     *
     * @return string|mixed
     */
    public function unionWithMixed(): string
    {
        return '';
    }

    /**
     * Phpstan-flavoured tag.
     *
     * @phpstan-return array<string, mixed>
     */
    public function phpstanReturnMixed(): array
    {
        return [];
    }

    /**
     * Psalm-flavoured tag.
     *
     * @psalm-param list<array<string, mixed>> $rows
     */
    public function psalmParamMixed(array $rows): void
    {
        unset($rows);
    }

    /**
     * Generic template - bounded by T, not mixed.
     *
     * @template T
     *
     * @param array<int, T> $items
     *
     * @return list<T>
     */
    public function templateGeneric(array $items): array
    {
        return array_values($items);
    }

    /**
     * Documents a thrown exception, no value typing.
     *
     * @throws \RuntimeException when invalid.
     */
    public function throwsOnly(): void
    {
        throw new \RuntimeException('demo');
    }

    public function noDocblock(string $value): string
    {
        return $value;
    }

    /**
     * Plain descriptive text only, no tags.
     */
    public function descriptiveDocblockNoTags(): void
    {
    }

    /**
     * Iterable generic containing mixed.
     *
     * @return iterable<mixed>
     */
    public function mixedInIterable(): iterable
    {
        return [];
    }

    /**
     * Collection generic containing mixed.
     *
     * @return Collection<int, mixed>
     */
    public function mixedInCollection(): Collection
    {
        return new Collection();
    }
}

/**
 * Stand-in for an external collection class so the fixture parses cleanly.
 */
final class Collection
{
}
