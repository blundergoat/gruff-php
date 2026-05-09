<?php

declare(strict_types=1);

namespace Fixtures\M09\Docs;

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
     * Missing @param tag.
     *
     * @return int
     */
    public function missingParam(int $x): int
    {
        return $x;
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
}
