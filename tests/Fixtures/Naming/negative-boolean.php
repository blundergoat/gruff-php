<?php

declare(strict_types=1);

namespace Fixtures\Naming;

final class NegativeBooleanFixture
{
    public bool $noConfig = false;

    public function configure(bool $disableCache, bool $skipValidation): bool
    {
        $nonNull  = $disableCache !== null;
        $notFound = !$skipValidation;

        return $nonNull && !$notFound;
    }
}

final class CliMirrorOptions
{
    public function __construct(public bool $noConfig = false)
    {
    }
}
