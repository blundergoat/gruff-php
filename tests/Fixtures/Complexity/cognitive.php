<?php

declare(strict_types=1);

namespace Fixtures\Complexity;

class CognitiveFixture
{
    // CC = 0
    public function flat(): int
    {
        return 1;
    }

    // CC = 1 (if +1)
    public function oneIf(int $x): int
    {
        if ($x > 0) {
            return $x;
        }

        return 0;
    }

    // CC = 4 (if +1, else +1, nested if +2 = 1+nesting1)
    public function nestedIf(int $x, int $y): int
    {
        if ($x > 0) {
            if ($y > 0) {
                return $x + $y;
            } else {
                return $x;
            }
        }

        return 0;
    }

    // CC = 3 (if +1, && +1, || +1 — operator switch)
    public function booleanChain(int $x, int $y): bool
    {
        if ($x > 0 && $y > 0 || $x < -10) {
            return true;
        }

        return false;
    }

    // CC = 2 (if +1, && +1 — same-operator chain = 1)
    public function sameOperatorChain(int $x, int $y, int $z): bool
    {
        if ($x > 0 && $y > 0 && $z > 0) {
            return true;
        }

        return false;
    }

    // CC = 1 (switch +1, no nesting penalty at level 0)
    public function switchOnly(int $x): string
    {
        switch ($x) {
            case 1:
                return 'one';
            case 2:
                return 'two';
            default:
                return 'other';
        }
    }

    // CC = 7 (foreach +1, if +2[1+nest1], for +3[1+nest2], if +4[1+nest3], else +1)
    //   = 1 + 2 + 3 + 4 + 1 = 11
    // Wait, let me recount:
    // foreach: +1 (nesting=0)                     → 1
    // if ($item>0): +1 + 1(nest=1)                → 3
    // for: +1 + 2(nest=2)                         → 6
    // if ($i%2): +1 + 3(nest=3)                   → 10
    // else: +1                                     → 11
    public function deeplyNested(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if ($item > 0) {
                for ($i = 0; $i < $item; $i++) {
                    if ($i % 2 === 0) {
                        $count++;
                    } else {
                        $count--;
                    }
                }
            }
        }

        return $count;
    }

    public function whileWithBooleanCondition(int $x): int
    {
        while ($x > 0 && $x < 10) {
            $x--;
        }

        return $x;
    }

    public function doWhileWithBooleanCondition(int $x): int
    {
        do {
            $x++;
        } while ($x < 3 || $x > 20);

        return $x;
    }

    public function tryCatchFinallyBranches(int $x): int
    {
        try {
            if ($x > 0) {
                return $x;
            }
        } catch (\RuntimeException) {
            if ($x < 0) {
                return -1;
            }
        } finally {
            if ($x === 0) {
                $x++;
            }
        }

        return $x;
    }

    public function jumpsAndGoto(array $items): int
    {
        foreach ($items as $item) {
            for ($i = 0; $i < $item; $i++) {
                if ($i > 10) {
                    break 2;
                }

                if ($i === 5) {
                    continue 2;
                }
            }
        }

        goto done;

        done:
        return 0;
    }

    public function logicalKeywordChain(bool $first, bool $second, bool $third): bool
    {
        if ($first and $second or $third) {
            return true;
        }

        return false;
    }

    public function expressionAndReturnTernaries(int $x): int
    {
        $value = $x > 10 ? 10 : ($x > 0 ? $x : 0);

        return $value > 0 ? $value : 0;
    }

    public function closureAndArrowFunction(int $x): int
    {
        $closure = function () use ($x): int {
            if ($x > 0) {
                return $x;
            }

            return 0;
        };
        $arrow = fn (): int => $x > 0 ? $x : 0;

        return $closure() + $arrow();
    }
}
