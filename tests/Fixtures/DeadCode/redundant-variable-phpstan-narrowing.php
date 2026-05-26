<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

final class RedundantVariablePhpStanNarrowingFixture
{
    public function withVarTagBeforeReturn(mixed $payload): string
    {
        $value = $payload;
        /** @var string $value */
        return $value;
    }

    public function withPhpStanVarTagBeforeReturn(mixed $payload): int
    {
        $value = $payload;
        /** @phpstan-var int $value */
        return $value;
    }

    public function withPsalmVarTagBeforeReturn(mixed $payload): bool
    {
        $value = $payload;
        /** @psalm-var bool $value */
        return $value;
    }

    public function withVarTagOnAssignment(mixed $payload): float
    {
        /** @var float $value */
        $value = $payload;
        return $value;
    }
}
