<?php

declare(strict_types=1);

namespace Fixtures\Naming;

final class DuplicateDeferralFixture
{
    public bool $disableCache = false;

    public function run(): void
    {
        $cb = 1;

        $callback = static function (string $strX): string {
            return $strX;
        };

        $callback((string) $cb);
    }
}
