<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Carries immutable dashboard server paths and command helpers for a request.
 */
final readonly class DashboardRequestContext
{
    /**
     * Capture request-level state shared by dashboard handlers.
     */
    public function __construct(
        public InputInterface $input,
        public string $launchRoot,
        public string $projectRoot,
        public ?float $scanTimeout,
        public string $bindHost,
        public int $bindPort,
    ) {
    }
}
