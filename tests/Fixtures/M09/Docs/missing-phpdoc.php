<?php

declare(strict_types=1);

namespace Fixtures\M09\Docs;

class MissingPhpdocFixture
{
    public function undocumented(int $x): int
    {
        if ($x > 10) {
            return $x * 2;
        }

        return $x + 1;
    }

    public function trivialUndocumented(int $x): int
    {
        return $x * 2;
    }

    /**
     * Documented method.
     */
    public function documented(): void
    {
    }

    public function getTitle(): string
    {
        return '';
    }

    public function setTitle(string $title): void
    {
    }

    public function isActive(): bool
    {
        return true;
    }

    private function privateMethod(): void
    {
    }

    public function __toString(): string
    {
        return '';
    }
}
