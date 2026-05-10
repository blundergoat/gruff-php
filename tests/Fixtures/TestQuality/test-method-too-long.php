<?php

declare(strict_types=1);

namespace Fixtures\TestQuality\TestMethodTooLong;

use PHPUnit\Framework\TestCase;

final class TestMethodLengthTest extends TestCase
{
    // Positive: 30+ meaningful statement lines, blanks/braces/comments excluded from the count.
    public function testLongScenarioExceedsThreshold(): void
    {
        $a = 1;
        $b = 2;
        $c = 3;
        $d = 4;
        $e = 5;
        $f = 6;
        $g = 7;
        $h = 8;
        $i = 9;
        $j = 10;
        $k = 11;
        $l = 12;
        $m = 13;
        $n = 14;
        $o = 15;
        $p = 16;
        $q = 17;
        $r = 18;
        $s = 19;
        $t = 20;
        $u = 21;
        $v = 22;
        $w = 23;
        $x = 24;
        $y = 25;
        $z = 26;
        $sum = $a + $b + $c + $d + $e + $f + $g + $h + $i + $j + $k + $l;
        $tail = $m + $n + $o + $p + $q + $r + $s + $t + $u + $v + $w + $x + $y + $z;

        self::assertSame(351, $sum + $tail);
    }

    // Negative: short test, well under the 25-line threshold.
    public function testShortScenario(): void
    {
        $value = 1 + 2;

        self::assertSame(3, $value);
    }

    // Edge: long visual span but mostly blanks/braces/comments — meaningful lines are well under threshold.
    public function testHasLotsOfWhitespaceAndComments(): void
    {
        // arrange


        $value = 1 + 2;


        // assert


        self::assertSame(3, $value);
    }
}
