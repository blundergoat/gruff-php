<?php

declare(strict_types=1);

namespace Fixtures\DeadCode;

class UnusedParameterFixture
{
    private function withUnused(int $used, int $unused): int
    {
        return $used;
    }

    private function allUsed(int $a, int $b): int
    {
        return $a + $b;
    }

    public function publicMethod(string $event, array $structural, array $detailed = [], ?string $transport = null): void
    {
        unset($detailed);
        $payload = ['event' => $event] + $structural;
        if ($transport !== null) {
            $payload = ['event' => $event, 'transport' => $transport] + $structural;
        }

        echo json_encode($payload);
    }
}

class BaseParameterFixture
{
    protected function touch(): void
    {
    }
}

class InheritedParameterFixture extends BaseParameterFixture
{
    public function hook(string $hookParam): void
    {
        $this->touch();
    }
}

interface ParameterContract
{
    public function handle(string $contractParam): void;
}

class ContractParameterFixture implements ParameterContract
{
    public function handle(string $contractParam): void
    {
        $this->touch();
    }

    private function touch(): void
    {
    }
}

final readonly class PromotedPrivateConstructorFixture
{
    private function __construct(
        public string $promoted,
    ) {
    }
}

function standaloneFunction(int $used, string $unused): int
{
    return $used;
}
