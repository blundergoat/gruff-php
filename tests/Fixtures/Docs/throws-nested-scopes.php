<?php

declare(strict_types=1);

namespace Fixtures\Docs\ThrowsNestedScopes;

class ScopedThrows
{
    /**
     * Throw directly from the method body.
     */
    public function directThrow(int $id): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('bad id');
        }

        return $id;
    }

    /**
     * Map values through a throwing arrow function.
     */
    public function arrowFunctionThrow(array $ids): array
    {
        return array_map(static fn (int $id) => $id > 0 ? $id : throw new \InvalidArgumentException('bad id'), $ids);
    }

    /**
     * Filter values through a throwing closure.
     */
    public function closureThrow(array $ids): array
    {
        return array_filter($ids, static function (int $id): bool {
            if ($id < 0) {
                throw new \InvalidArgumentException('bad id');
            }

            return $id > 0;
        });
    }

    /**
     * Build a validator object whose method throws.
     */
    public function anonymousClassThrow(): object
    {
        return new class {
            /**
             * Check one identifier.
             *
             * @throws \InvalidArgumentException When the identifier is below one.
             */
            public function check(int $id): void
            {
                if ($id < 1) {
                    throw new \InvalidArgumentException('bad id');
                }
            }
        };
    }

    /**
     * Run a throwing immediately invoked closure.
     */
    public function iifeThrow(int $id): int
    {
        return (static function () use ($id): int {
            if ($id < 1) {
                throw new \InvalidArgumentException('bad id');
            }

            return $id;
        })();
    }
}

/**
 * Throw directly from a free function.
 */
function freeFunctionDirectThrow(int $id): int
{
    if ($id < 1) {
        throw new \InvalidArgumentException('bad id');
    }

    return $id;
}
