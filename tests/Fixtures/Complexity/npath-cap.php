<?php

declare(strict_types=1);

namespace Fixtures\Complexity;

final class NpathCapFixture
{
    public function capped(bool $a, bool $b, bool $c, bool $d, bool $e, bool $f, bool $g, bool $h, bool $i, bool $j, bool $k, bool $l, bool $m, bool $n, bool $o, bool $p, bool $q): int
    {
        $total = 0;
        if ($a) { $total++; }
        if ($b) { $total++; }
        if ($c) { $total++; }
        if ($d) { $total++; }
        if ($e) { $total++; }
        if ($f) { $total++; }
        if ($g) { $total++; }
        if ($h) { $total++; }
        if ($i) { $total++; }
        if ($j) { $total++; }
        if ($k) { $total++; }
        if ($l) { $total++; }
        if ($m) { $total++; }
        if ($n) { $total++; }
        if ($o) { $total++; }
        if ($p) { $total++; }
        if ($q) { $total++; }

        return $total;
    }
}
