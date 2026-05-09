<?php

declare(strict_types=1);

namespace Fixtures\M19\TestQuality;

/**
 * Library code whose method names happen to start with `test`.
 *
 * The class does NOT extend a *TestCase base, so test-quality rules must skip these
 * methods even though their names match the `test*` PHPUnit convention.
 */
final class NonTestHelper
{
    /**
     * @return list<int>
     */
    public function testScopes(): array
    {
        $scopes = [];

        foreach ([1, 2, 3] as $value) {
            if ($value > 1) {
                $scopes[] = $value;
            }
        }

        return $scopes;
    }

    public function testCandidate(int $value): bool
    {
        if ($value < 0) {
            return false;
        }

        return $value > 0;
    }
}
