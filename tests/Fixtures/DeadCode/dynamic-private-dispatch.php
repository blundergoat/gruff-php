<?php

declare(strict_types=1);

namespace Fixtures\DeadCode\DynamicDispatch;

class EventRouter
{
    public function dispatch(string $name): void
    {
        $method = 'handle' . ucfirst($name);
        $this->{$method}();
    }

    private function handleCreated(): void
    {
    }

    private function handleDeleted(): void
    {
    }
}

class LiteralDynamicCaller
{
    public function run(): void
    {
        $this->{'invokeExact'}();
    }

    private function invokeExact(): void
    {
    }

    private function stillUnused(): void
    {
    }
}

class ForeignDispatcher
{
    public function forward(object $target, string $method): void
    {
        $target->{$method}();
    }

    private function neverCalled(): void
    {
    }
}

trait HooksTrait
{
    public function fire(string $hook): void
    {
        static::{$hook}();
    }

    private function bootHook(): void
    {
    }
}

class CallableMapRouter
{
    public function register(string $method): callable
    {
        return [$this, $method];
    }

    private function mappedHandler(): void
    {
    }
}

enum Signal: string
{
    case Start = 'start';

    public function label(): string
    {
        return $this->{'labelFor'}();
    }

    private function labelFor(): string
    {
        return 'signal';
    }

    private function unusedInEnum(): string
    {
        return 'dead';
    }
}
