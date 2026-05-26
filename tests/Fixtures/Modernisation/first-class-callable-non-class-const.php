<?php

declare(strict_types=1);

final class ProviderRegistry
{
    public const PRIMARY_ID = 1;

    public function dispatch(callable $callable): void
    {
        $callable();
    }
}

function nonClassConstCallableTarget(ProviderRegistry $registry): void
{
    $registry->dispatch([ProviderRegistry::PRIMARY_ID, 'method']);
}
