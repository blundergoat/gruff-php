<?php

declare(strict_types=1);

namespace Fixtures\M14\Source;

final readonly class OrderCalculator
{
    public function calculateTotal(int $subtotal, int $tax): int
    {
        return $subtotal + $tax;
    }
}
