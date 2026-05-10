<?php

declare(strict_types=1);

namespace Fixtures\M08\Naming;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenericMethodFixture
{
    public function process(): void {}
    public function handle(): void {}
    public function execute(): void {}
    public function run(): void {}
    public function manage(): void {}
    public function doIt(): void {}

    public function processPayment(): void {}
    public function handleRequest(): void {}
    public function calculateTotal(): int { return 0; }
}

class FrameworkOverrideFixture
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }
}
