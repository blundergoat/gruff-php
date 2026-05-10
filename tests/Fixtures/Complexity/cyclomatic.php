<?php

declare(strict_types=1);

namespace Fixtures\Complexity;

class CyclomaticFixture
{
    // CCN = 1 (no branches)
    public function flat(): int
    {
        return 1;
    }

    // CCN = 3 (1 + if + elseif)
    public function ifElseIf(int $x): int
    {
        if ($x > 0) {
            return 1;
        } elseif ($x < 0) {
            return -1;
        }

        return 0;
    }

    // CCN = 4 (1 + for + if + &&)
    public function loopWithCondition(array $items): int
    {
        $count = 0;

        for ($i = 0; $i < count($items); $i++) {
            if ($items[$i] > 0 && $items[$i] < 100) {
                $count++;
            }
        }

        return $count;
    }

    // CCN = 4 (1 + case + case + case; default excluded)
    public function switchBlock(int $x): string
    {
        switch ($x) {
            case 1:
                return 'one';
            case 2:
                return 'two';
            case 3:
                return 'three';
            default:
                return 'other';
        }
    }

    // CCN = 7 (1 + foreach + if + || + ternary + ?? + ??)
    public function mixedOperators(array $items, ?int $fallback): int
    {
        $sum = 0;

        foreach ($items as $item) {
            if ($item > 0 || $item === 0) {
                $sum += $item > 10 ? 10 : $item;
            }
        }

        return $sum ?? $fallback ?? 0;
    }

    // CCN = 4 (1 + while + if + catch)
    public function tryCatchLoop(): int
    {
        $i = 0;

        while ($i < 10) {
            try {
                if ($i === 5) {
                    throw new \RuntimeException('stop');
                }
            } catch (\RuntimeException $e) {
                return $i;
            }

            $i++;
        }

        return $i;
    }
}
