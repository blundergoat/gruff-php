<?php

declare(strict_types=1);

namespace Fixtures\Naming;

class CleanNamingFixture
{
    public function calculateTotal(array $cartItems): int
    {
        $runningTotal = 0;

        foreach ($cartItems as $cartItem) {
            $runningTotal += $cartItem;
        }

        return $runningTotal;
    }
}
