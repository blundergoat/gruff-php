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

final class SnakeCaseSyncOptions
{
    public bool $no_cache = false;

    public function synchronise(bool $not_ready, bool $normalised_output): bool
    {
        return $not_ready || $normalised_output;
    }
}

final class SnakeCliMirrorOptions
{
    public function __construct(public bool $no_color = false)
    {
    }
}
