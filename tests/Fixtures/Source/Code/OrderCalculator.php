<?php

/**
 * Baseline workflow fixture - keep exactly one finding so baseline tests are stable.
 */

declare(strict_types=1);

namespace Fixtures\Source\Code;

/**
 * Sums an order's subtotal and tax amount.
 */
final readonly class OrderCalculator
{
    public function calculateTotal(int $subtotal, int $taxAmount): int
    {
        // Sum subtotal and tax to produce the gross order total.
        return $subtotal + $taxAmount;
    }
}
