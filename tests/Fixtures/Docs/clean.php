<?php

/**
 * Clean docs fixture used as the gold standard for the documentation pillar.
 */

declare(strict_types=1);

namespace Fixtures\Docs;

/**
 * Cleanly documented class.
 */
class CleanDocsFixture
{
    /**
     * Calculate the sum of items.
     *
     * @param list<int> $items The items to sum.
     * @return int The total.
     */
    public function calculateTotal(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            $total += $item;
        }

        // The accumulated total is the documented result of this fixture.
        return $total;
    }
}
