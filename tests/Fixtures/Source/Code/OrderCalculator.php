<?php

declare(strict_types=1);

namespace Fixtures\Source\Code;

final readonly class OrderCalculator
{
    public function calculateTotal(int $subtotal, int $tax): int
    {
        return $subtotal + $tax;
    }
}
