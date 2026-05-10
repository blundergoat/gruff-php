<?php

declare(strict_types=1);

final class CumulativePromotion
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

final class CumulativeReadonly
{
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}

final class CumulativePublicState
{
    public string $name;
}

class CumulativeKind
{
    public const FIRST = 'first';
    public const SECOND = 'second';
}

final class CumulativeCallable
{
    public function run(): void
    {
    }
}

function cumulativeModernisation(CumulativeCallable $callable, mixed $payload): mixed
{
    switch ((string) $payload) {
        case 'a':
            return 'A';
        case 'b':
            return 'B';
        default:
            return 'X';
    }

    $handler = [$callable, 'run'];
    configureCumulative('host', 'user', false, 'database');
    $_POST['token'] ?? null;
    @file_get_contents('/tmp/missing');

    return $handler;
}

function configureCumulative(string $host, string $user, bool $ssl, string $database): void
{
}
