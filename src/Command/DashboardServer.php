<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Serves the dashboard HTTP loop for local browser usage.
 */
final readonly class DashboardServer
{
    /**
     * Create a dashboard server using the shared state factory and gruff-php binary path.
     *
     * @param DashboardStateFactory $stateFactory - Factory used to build dashboard state.
     * @param string                $gruffBinary - Absolute gruff-php binary path used for scan requests.
     */
    public function __construct(
        private DashboardStateFactory $stateFactory,
        private string                $gruffBinary,
    ) {
    }

    /**
     * Bind the dashboard socket and process HTTP clients until the socket closes.
     *
     * @param OutputInterface         $output - Console output for the dashboard URL and errors.
     * @param string                  $host - Hostname or address to bind.
     * @param int                     $port - Port to bind.
     * @param DashboardRequestContext $dashboardRequestContext - Request context shared with handlers.
     *
     * @return int - Command::SUCCESS on clean shutdown (socket closed), Command::FAILURE when the bind never succeeded
     */
    public function serve(OutputInterface $output, string $host, int $port, DashboardRequestContext $dashboardRequestContext): int
    {
        $server = $this->createServer($host, $port, $errorCode, $errorMessage);

        if ($server === false) {
            $output->writeln(sprintf('<error>Unable to start dashboard on %s:%d: %s (%d)</error>', $host, $port, $errorMessage, $errorCode));

            // Socket bind failed (port in use or denied); abort before the accept loop so the command exits non-zero.
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Serving gruff-php dashboard at %s</info>', $this->url($host, $port, $dashboardRequestContext)));
        $output->writeln('<comment>Use the form to refresh the scan or point gruff-php at another project. Press Ctrl+C to stop.</comment>');

        $handler = $this->handler($dashboardRequestContext);

        while (is_resource($server)) {
            $client = $this->acceptClient($server);

            if ($client === false) {
                continue;
            }

            try {
                $handler->handleRequest($client);
            } catch (\RuntimeException $exception) {
                $output->writeln(sprintf('<comment>Dashboard client response failed: %s</comment>', $exception->getMessage()));
            } finally {
                fclose($client);
            }
        }

        fclose($server);

        // Reached only when the listening socket is closed (Ctrl+C / external close); a clean shutdown, not an error.
        return Command::SUCCESS;
    }

    /**
     * Build the initial dashboard URL shown in console output.
     *
     * @param string                  $host - Bound host the browser should connect back to.
     * @param int                     $port - Bound TCP port to embed in the URL.
     * @param DashboardRequestContext $dashboardRequestContext - Request context whose input/projectRoot seed the default query string.
     *
     * @return string - http URL the operator opens, pre-seeded with the launch options as the default query string
     */
    private function url(string $host, int $port, DashboardRequestContext $dashboardRequestContext): string
    {
        // Pre-populate the URL with the default form state so the first page load reflects the launch options.
        return sprintf(
            'http://%s:%d/?%s',
            $this->urlHost($host),
            $port,
            http_build_query($this->stateFactory->defaultQuery($dashboardRequestContext->input, $dashboardRequestContext->projectRoot), '', '&', PHP_QUERY_RFC3986),
        );
    }

    /**
     * Format a host for use in a browser URL.
     *
     * @param string $host - Bound host or address; an unbracketed IPv6 literal here gets wrapped.
     *
     * @return string - the host unchanged, except a bare IPv6 literal is wrapped in brackets for safe URL embedding
     */
    private function urlHost(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            // Bare IPv6 literal: bracket it so the colons are not mistaken for the URL port separator.
            return '[' . $host . ']';
        }

        // Hostname or IPv4 address needs no bracketing; return it untouched.
        return $host;
    }

    /**
     * Build the per-request dashboard handler.
     *
     * @param DashboardRequestContext $dashboardRequestContext - Request context threaded into the handler and its scan runner.
     *
     * @return DashboardRequestHandler - handler pre-wired with its renderer, scan runner, and responder per connection
     */
    private function handler(DashboardRequestContext $dashboardRequestContext): DashboardRequestHandler
    {
        $dashboardPageRenderer = new DashboardPageRenderer();
        $dashboardScanRunner   = new DashboardScanRunner($this->gruffBinary, $this->stateFactory, $dashboardPageRenderer);

        // Handler wired with its own renderer, scan runner, and responder so each connection reuses one collaborator set.
        return new DashboardRequestHandler($dashboardRequestContext, $this->stateFactory, $dashboardScanRunner, new DashboardHttpResponder());
    }

    /**
     * Open the listening socket with PHP warnings suppressed so the caller handles bind failures.
     *
     * @param string          $host - Host or address to bind; IPv6 literals are bracketed before use.
     * @param int             $port - TCP port to bind.
     * @param int|null        $errorCode - Receives the socket errno on failure; caller passes an unset variable to be filled.
     * @param string|null     $errorMessage - Receives the human-readable bind error on failure; pairs with $errorCode.
     * @param-out int|null    $errorCode - Socket errno on bind failure, null otherwise.
     * @param-out string|null $errorMessage - Human-readable socket error on bind failure, null otherwise.
     *
     * @return resource|false - listening stream on success, or false when the bind fails (details in the out params)
     */
    private function createServer(string $host, int $port, ?int &$errorCode, ?string &$errorMessage)
    {
        set_error_handler(static fn(): bool => true);

        try {
            // Return the listening socket (or false) while the suppressing handler is active so bind warnings stay quiet.
            return stream_socket_server(sprintf('tcp://%s:%d', $this->urlHost($host), $port), $errorCode, $errorMessage);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Accept one dashboard HTTP client connection.
     *
     * @param resource $server - Listening stream created by createServer().
     *
     * @return resource|false - accepted client stream, or false on the 1s accept timeout or error so the loop retries
     */
    private function acceptClient($server)
    {
        set_error_handler(static fn(): bool => true);

        try {
            // Accept with a 1s timeout so the loop wakes periodically; false on timeout/error lets the caller retry.
            return stream_socket_accept($server, 1);
        } finally {
            restore_error_handler();
        }
    }
}
