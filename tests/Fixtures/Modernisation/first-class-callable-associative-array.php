<?php

declare(strict_types=1);

function associativePairIsNotCallable(string $resolverSelection): void
{
    configureIntentContext([
        'service_id' => $resolverSelection,
        'service_selection_source' => 'literal',
    ]);
}

function configureIntentContext(array $context): void
{
}
