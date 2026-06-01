<?php

declare(strict_types=1);

namespace Fixtures\Docs;

final class ControlFlowCommentsFixture
{
    /**
     * Exercise loop and return control-flow comments.
     *
     * @param list<int> $items - The items to inspect.
     *
     * @return int - The calculated total.
     */
    public function run(array $items): int
    {
        foreach ($items as $item) {
            if ($item < 0) {
                continue;
            }

            if ($item === 0) {
                // Zero is a sentinel that should not be processed.
                continue;
            }

            if ($item > 10) {
                // This comment is too far away.

                continue;
            }
        }

        if ($items === []) {
            return 0;
        }

        if ($items === [0]) {
            // A zero-only list has no total to carry forward.
            return 0;
        }

        if ($items === [1]) {
            // This comment is too far away.

            return 1;
        }

        // Non-empty lists use the built-in summation path.
        return array_sum($items);
    }
}
