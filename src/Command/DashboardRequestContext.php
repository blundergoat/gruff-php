<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Input\InputInterface;

final readonly class DashboardRequestContext
{
    public function __construct(
        public InputInterface $input,
        public string $launchRoot,
        public string $projectRoot,
        public ?float $scanTimeout,
    ) {
    }
}
