<?php

declare(strict_types=1);

final class PromotionFixture
{
    private string $name;
    protected int $count;

    public function __construct(string $name, int $count)
    {
        $this->name = $name;
        $this->count = $count;
    }
}

final class PublicStateFixture
{
    public string $name;
}

function modernStatus(string $status): string
{
    switch ($status) {
        case 'open':
            return 'Open';
        case 'closed':
            return 'Closed';
        default:
            return 'Unknown';
    }
}

function mixedBoundary(mixed $payload): mixed
{
    $_GET['filter'] ?? null;
    $_SESSION['user'] ?? null;

    processModernOrder('customer-1', 10, true, 'priority');
    configureModernService('host', 'user', 'pass', 'database');

    return $payload;
}

function processModernOrder(string $customerId, int $count, bool $urgent, string $note): void
{
}

function configureModernService(string $host, string $user, string $password, string $database): void
{
}
