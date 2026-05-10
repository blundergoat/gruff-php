<?php

declare(strict_types=1);

final class ReadonlyFixture
{
    private string $id;
    private int $count;

    public function __construct(string $id, int $count)
    {
        $this->id = $id;
        $this->count = $count;
    }

    public function id(): string
    {
        return $this->id;
    }
}

class WorkflowState
{
    public const DRAFT = 'draft';
    public const OPEN = 'open';
    public const CLOSED = 'closed';
}

final class CallableTarget
{
    public function run(): void
    {
    }
}

function callableCandidate(CallableTarget $target): callable
{
    return [$target, 'run'];
}
