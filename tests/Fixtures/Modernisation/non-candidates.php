<?php

declare(strict_types=1);

final class LateMutationFixture
{
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function rename(string $id): void
    {
        $this->id = $id;
    }
}

class BaseModel
{
}

class InheritedFixture extends BaseModel
{
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}

final class UserDto
{
    public string $name;
}

final class AlreadyReadonlyFixture
{
    public readonly string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}

final class ExternalAssignmentFixture
{
    private string $id;

    public function __construct(object $other, string $id)
    {
        $other->id = $id;
    }
}

final class MessageInboxFixture
{
    private array $messages;

    public function __construct(array $initial)
    {
        $this->messages = $initial;
    }

    public function append(string $message): void
    {
        $this->messages[] = $message;
    }

    public function set(string $key, string $message): void
    {
        $this->messages[$key] = $message;
    }

    public function drop(string $key): void
    {
        unset($this->messages[$key]);
    }
}

function shortCall(): void
{
    configureSafeService('host', 'user', 'database');
}

function literalStringListIsNotCallableSyntax(string $status): bool
{
    return in_array($status, ['open', 'closed'], true);
}

function configureSafeService(string $host, string $user, string $database): void
{
}
