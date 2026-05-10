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

function shortCall(): void
{
    configureSafeService('host', 'user', 'database');
}

function configureSafeService(string $host, string $user, string $database): void
{
}
