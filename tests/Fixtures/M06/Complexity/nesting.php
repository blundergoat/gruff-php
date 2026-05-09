<?php

declare(strict_types=1);

namespace Fixtures\M06\Complexity;

class NestingFixture
{
    // max nesting = 0
    public function flat(): int
    {
        return 1;
    }

    // max nesting = 1
    public function oneLevel(int $x): int
    {
        if ($x > 0) {
            return $x;
        }

        return 0;
    }

    // max nesting = 4
    public function fourLevels(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if ($item > 0) {
                for ($i = 0; $i < $item; $i++) {
                    if ($i % 2 === 0) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    // max nesting = 5
    public function fiveLevels(array $data): int
    {
        $total = 0;

        foreach ($data as $group) {
            if (is_array($group)) {
                foreach ($group as $item) {
                    if ($item > 0) {
                        while ($item > 1) {
                            $item--;
                            $total++;
                        }
                    }
                }
            }
        }

        return $total;
    }
}
