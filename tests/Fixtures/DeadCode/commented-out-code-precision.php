<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

class CommentedOutCodePrecisionFixture
{
    public function proseAndPlaceholders(object $dbi, object $value, string $userAgent): void
    {
        // It future replace str_getcsv with $dbi->fetchSingleRow(...);
        // For 'all' messages, the code is not simplified as $this->messages = $target->all($domain);
        // $value->format('H'); returns 09
        //$userAgent = $userAgent; // kept for the log format
        // $i=0 : 1; $i=1 : 26; $i=2 : 676; ...
    }

    public function restatements(int $src, object $first, object $second): int
    {
        $diff = 0;
        // if ($src > 62) $diff += 0x2f - 0x2b - 1; // 3
        $diff += ((62 - $src) >> 8) & 3;
        // $sum = $first + $second;
        $sum = $first->add($second);

        return $diff + $sum;
    }

    public function disabledCode(object $service, mixed $input): void
    {
        // $result = $service->process($input);
        //     $canAccess = true; // Admins can view all profiles
    }

    public function lookAlikeRestatements(string $raw, object $a, object $b, object $width, object $depth): void
    {
        // $name = substr($raw, 0, 4);
        $name = str_replace("\x00", '_', substr($raw, 0, 4));
        // $limit = 10 * 2;
        $limit = 20;
        // $out = trim($a) + $b;
        $out = $a->minus($b);
        // $area = $width * $height;
        $area = $width->times($depth);
        // $x = $a + $b;
        $y = $x->plus($a, $b);
        // Registry::$total = $a + $b;
        $total = $a->plus($b);
        // $first = $a + $b;
        // $second = $a - $b;
        $first = $a->plus($b);
        // $mode = $a + $b;
        $mode = match (true) { default => $a->plus($b) };
    }
}
