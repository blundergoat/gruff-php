<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Immutable per-run settings shared by every dashboard request handler.
 *
 * Created once when the user launches `gruff-php dashboard`: it carries where the server is bound,
 * which project it scans, and the directory it launched from, so each incoming browser request works
 * from the same fixed context instead of re-deriving it per hit.
 */
final readonly class DashboardRequestContext
{
    /**
     * Capture request-level state shared by dashboard handlers.
     *
     * @param InputInterface $input       - Console input used to seed dashboard state.
     * @param string         $launchRoot  - Shell working directory that launched the dashboard.
     * @param string         $projectRoot - Active project root for scan requests.
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
