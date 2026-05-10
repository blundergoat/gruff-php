<?php

declare(strict_types=1);

namespace Fixtures\Naming;

class CleanNamingFixture
{
    public function calculateTotal(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            $total += $item;
        }

        return $total;
    }
}
