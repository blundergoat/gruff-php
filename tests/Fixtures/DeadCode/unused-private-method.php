<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

class UnusedPrivateMethodFixture
{
    public function publicMethod(): int
    {
        return $this->usedPrivate();
    }

    private function usedPrivate(): int
    {
        return 42;
    }

    private function unusedPrivate(): void
    {
        echo 'never called';
    }

    private function alsoUnused(): string
    {
        return 'dead';
    }

    protected function protectedMethod(): void
    {
    }

    private function __construct()
    {
    }

    public function __toString(): string
    {
        return '';
    }
}
