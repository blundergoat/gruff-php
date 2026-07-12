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

/**
 * Stores target and method values as descriptive fields rather than an invokable callback.
 * Dead-code analysis must not treat associative metadata as dynamic same-class dispatch.
 * Users reach this shape when serialising callback-like labels for diagnostics.
 */
class AssociativeCallbackMetadataRouter
{
    /**
     * Build descriptive metadata without registering a runtime callback.
     *
     * @param string $method - Method label to store; an empty string remains an empty field.
     *
     * @return array{target: self, method: string} - Associative metadata that PHP cannot invoke as a callable.
     */
    public function describe(string $method): array
    {
        return ['target' => $this, 'method' => $method];
    }

    /**
     * Produce a value that no supported call reaches.
     *
     * @return string - Stable marker proving the helper owns non-empty work.
     */
    private function unusedMetadataHelper(): string
    {
        return 'unused';
    }
}

/**
 * Registers a valid dynamic callable whose numeric keys appear in reverse source order.
 * Dead-code analysis must stay conservative because the method name is known only at runtime.
 * Users reach this shape when callback slots are assigned explicit PHP array keys.
 */
class ReorderedCallableMapRouter
{
    /**
     * Build the same-class dynamic callback using PHP's required keys zero and one.
     *
     * @param string $method - Runtime callback method; an empty string yields a non-invokable value.
     *
     * @return array{1: string, 0: self} - Keyed callback pair whose insertion order does not affect invocation.
     */
    public function register(string $method): array
    {
        return [1 => $method, 0 => $this];
    }

    /**
     * Produce a value that the dynamic callable may select at runtime.
     *
     * @return string - Stable marker returned if runtime dispatch selects this helper.
     */
    private function possibleTarget(): string
    {
        return 'possible';
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
