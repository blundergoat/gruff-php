<?php

declare(strict_types=1);

namespace Fixtures\M09\Docs;

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

        return $total;
    }
}
