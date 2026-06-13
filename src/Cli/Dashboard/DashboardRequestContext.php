<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Carries immutable dashboard server paths and command helpers for a request.
 */
final readonly class DashboardRequestContext
{
    /**
     * Capture request-level state shared by dashboard handlers.
     *
     * @param InputInterface $input - Console input used to seed dashboard state.
     * @param string         $launchRoot - Shell working directory that launched the dashboard.
     * @param string         $projectRoot - Active project root for scan requests.
     * @param float|null     $scanTimeout - Scan timeout in seconds, when configured.
     * @param string         $bindHost - Hostname or address bound by the dashboard server.
     * @param int            $bindPort - Port bound by the dashboard server.
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
