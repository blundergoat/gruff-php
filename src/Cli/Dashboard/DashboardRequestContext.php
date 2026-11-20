<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Stores launch settings shared by dashboard request handlers for one server run.
 *
 * Created by the dashboard command with the bind address, launch directory, default project, and scan timeout.
 * Submitted forms may select a different project without changing these defaults.
 */
final readonly class DashboardRequestContext
{
    /**
     * Capture launch settings shared by dashboard handlers.
     *
     * @param InputInterface $input       - Console input used to seed dashboard state.
     * @param string         $launchRoot  - Shell working directory that launched the dashboard.
     * @param string         $projectRoot - Default project root; a submitted scan form may select another project.
     * @param float|null     $scanTimeout - Scan timeout in seconds; null means no limit was set, so scans run untimed.
     * @param string         $bindHost    - Hostname or address bound by the dashboard server.
     * @param int            $bindPort    - Port bound by the dashboard server.
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
