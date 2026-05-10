<?php

declare(strict_types=1);

namespace Fixtures\Docs;

interface DocumentedContract
{
    /**
     * Contract docs live here.
     *
     * @throws \RuntimeException when the value is invalid.
     */
    public function inheritedThrows(int $value): int;
}

class PhpdocTagsFixture
{
    /**
     * Has all tags.
     *
     * @param int $x The value.
     * @return int The result.
     */
    public function complete(int $x): int
    {
        return $x;
    }

    /**
     * Missing one @param tag.
     *
     * @param int $x The first value.
     * @return int
     */
    public function missingParam(int $x, int $y): int
    {
        return $x + $y;
    }

    /**
     * Missing the array @param tag.
     *
     * @param int $x The first value.
     * @return int
     */
    public function missingArrayParam(int $x, array $y): int
    {
        return $x + count($y);
    }

    /**
     * Does not document the return value.
     *
     * @param int $x The value.
     */
    public function missingReturn(int $x): int
    {
        return $x;
    }

    /**
     * Does not document the array return shape.
     *
     * @param int $x The value.
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
     * Stale param.
     *
     * @param int $x Exists.
     * @param string $oldParam No longer exists.
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
     * @param int $x The value being doubled.
     */
    public function describedTagDoc(int $x): int
    {
        return $x * 2;
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
