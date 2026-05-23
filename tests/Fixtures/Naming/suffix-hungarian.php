<?php

declare(strict_types=1);

namespace Fixtures\Naming;

final class SuffixHungarianFixture
{
    public function parse(mixed $input): array
    {
        $rawString = trim((string) $input);

        /** @var array<string,int> $useMap */
        $useMap = ['first' => 1];

        /** @var array<string,int> $cache */
        $cache = ['second' => 2];

        $nameAsString = (string) $input;

        return [$rawString, $useMap, $cache, $nameAsString];
    }
}
