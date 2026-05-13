<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\LoopInTest;

use PHPUnit\Framework\TestCase;

/**
 * Exercises loop-in-test boundaries for assertion-bearing and setup-only loops.
 */
final class LoopInTestHeuristicTest extends TestCase
{
    /**
     * Verify assertion loops are still reported.
     *
     * @return void No return value.
     */
    public function testAssertionInsideLoopIsFlagged(): void
    {
        foreach ([1, 2] as $amount) {
            self::assertGreaterThan(0, $amount, sprintf('amount %d should be positive.', $amount));
        }
    }

    /**
     * Verify loops that only build input are not reported.
     *
     * @return void No return value.
     */
    public function testInputConstructionLoopIsAllowed(): void
    {
        $headers = '';

        for ($index = 0; $index < 3; $index++) {
            $headers .= sprintf("X-Test-%d: value\r\n", $index);
        }

        self::assertStringContainsString('X-Test-2: value', $headers);
    }

    /**
     * Verify search loops are not reported when assertions stay outside the loop.
     *
     * @return void No return value.
     */
    public function testLookupLoopBeforeAssertionIsAllowed(): void
    {
        $matched = null;

        foreach (['first', 'target'] as $candidate) {
            if ($candidate === 'target') {
                $matched = $candidate;

                break;
            }
        }

        self::assertSame('target', $matched);
    }
}
