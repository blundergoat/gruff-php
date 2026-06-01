<?php

declare(strict_types=1);

namespace Fixtures\Docs;

interface DocumentedContract
{
    /**
     * Contract docs live here.
     *
     * @return int - The accepted value.
     * @throws \RuntimeException when the value is invalid.
     */
    public function inheritedThrows(int $value): int;
}

class PhpdocTagsFixture
{
    /**
     * Has all tags.
     *
     * @param int $x - The value.
     *
     * @return int - The result.
     */
    public function complete(int $x): int
    {
        return $x;
    }

    /**
     * Missing one @param tag.
     *
     * @param int $x - The first value.
     *
     * @return int
     */
    public function missingParam(int $x, int $y): int
    {
        return $x + $y;
    }

    /**
     * Documents the exceptional case.
     *
     * @throws \RuntimeException when the value is invalid.
     */
    public function missingScalarParamWithThrows(string $groupId): string
    {
        if ($groupId === '') {
            throw new \RuntimeException('invalid');
        }

        return $groupId;
    }

    /**
     * Missing the array @param tag.
     *
     * @param int $x - The first value.
     *
     * @return int
     */
    public function missingArrayParam(int $x, array $y): int
    {
        return $x + count($y);
    }

    /**
     * Does not document the return value.
     *
     * @param int $x - The value.
     */
    public function missingReturn(int $x): int
    {
        return $x;
    }

    /** Delete one configured item. */
    public function missingReturnForDescriptiveDocblock(string $groupId): bool
    {
        return $groupId !== '';
    }

    /**
     * Does not document the array return shape.
     *
     * @param int $x - The value.
     */
    public function missingArrayReturn(int $x): array
    {
        return [$x];
    }

    /**
     * @api
     */
    public function apiMarkerOnly(int $x): int
    {
        return $x;
    }

    /**
     * Performs a side-effect with no return value.
     */
    public function voidWithDocblock(int $x): void
    {
        unset($x);
    }

    /**
     * Always throws; never returns.
     */
    public function neverWithDocblock(int $x): never
    {
        throw new \RuntimeException('always: ' . $x);
    }

    /**
     * Stale param.
     *
     * @param int $x - Exists.
     * @param string $oldParam - No longer exists.
     *
     * @return int
     */
    public function staleParam(int $x): int
    {
        return $x;
    }

    /**
     * @param class-string[] $types
     */
    public function genericParamDoc(array $types): array
    {
        return $types;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{label: string, value: string}> $rows
     */
    public function genericParamDocWithSpaces(array $context, array $rows): array
    {
        return [$context, $rows];
    }

    /**
     * @param int $x - The value being doubled.
     */
    public function describedTagDoc(int $x): int
    {
        return $x * 2;
    }

    /**
     * @return int - The stable count.
     */
    public function describedReturnTagDoc(): int
    {
        return 1;
    }

    /**
     * Does not document exceptions.
     */
    public function throwsWithoutTag(int $x): int
    {
        if ($x < 0) {
            throw new \InvalidArgumentException('negative');
        }

        return $x;
    }

    /**
     * @param int $x
     *
     * @return int
     */
    public function uselessDoc(int $x): int
    {
        return $x;
    }

    /**
     * @param resource $stream
     */
    public function resourceParamDoc($stream): int
    {
        return is_resource($stream) ? 1 : 0;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function arrayShapeDoc(): array
    {
        return [['label' => 'Name', 'value' => 'Ada']];
    }

    /**
     * Private helpers with docs still need return tags.
     */
    private function privateMissingReturnTag(): string
    {
        return 'value';
    }

    /**
     * Private helpers are clean when the return tag is present.
     *
     * @return string - The resolved value.
     */
    private function privateCompleteReturnTag(): string
    {
        return 'value';
    }
}

final class ImplementsDocumentedContract implements DocumentedContract
{
    public function inheritedThrows(int $value): int
    {
        if ($value < 0) {
            throw new \RuntimeException('invalid');
        }

        return $value;
    }
}

final class OverrideDocumentedContract implements DocumentedContract
{
    /**
     * Local detail.
     */
    #[\Override]
    public function inheritedThrows(int $value): int
    {
        if ($value < 0) {
            throw new \RuntimeException('invalid');
        }

        return $value;
    }
}
