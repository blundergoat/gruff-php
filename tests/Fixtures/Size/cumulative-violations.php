<?php

declare(strict_types=1);

namespace Fixtures\Size;

class CumulativeViolationsFixture
{
    public int $p1 = 0;
    public int $p2 = 0;
    public int $p3 = 0;
    public int $p4 = 0;
    public int $p5 = 0;
    public int $p6 = 0;

    public function m1(): void {}
    public function m2(): void {}
    public function m3(): void {}
    public function m4(): void {}
    public function m5(): void {}
    public function m6(): void {}

    public function longMethod(int $a, int $b, int $c, int $d, int $e, int $f): void
    {
        $x = 1;
        $x = 2;
        $x = 3;
        $x = 4;
        $x = 5;
        $x = 6;
        $x = 7;
        $x = 8;
        $x = 9;
        $x = 10;
        $x = 11;
        $x = 12;
        $x = 13;
        $x = 14;
        $x = 15;
        $x = 16;
        $x = 17;
        $x = 18;
        $x = 19;
        $x = 20;
    }
}
