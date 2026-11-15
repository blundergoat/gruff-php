<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the dashboard HTTP server on the configured host and port.
 * Prints a URL seeded with launch options and processes client connections one at a time.
 * Reuses one request handler across connections, retaining the scan runner's in-memory cache.
 */
final readonly class DashboardServer
{
    /**
     * Wires the server to the state factory and the gruff-php binary its scans will invoke.
     *
     * @param DashboardStateFactory $stateFactory - Factory used to build dashboard state.
     * @param string                $gruffBinary  - Absolute gruff-php binary path used for scan requests.
     */
    public function __construct(
        private DashboardStateFactory $stateFactory,
        private string                $gruffBinary,
    ) {
    }

    /**
     * Binds the socket and serves browser requests until the user stops the dashboard.
     *
     * @param OutputInterface         $output                  - Console output for the dashboard URL and errors.
     * @param string                  $host                    - Hostname or address to bind.
     * @param int                     $port                    - Port to bind.
     * @param DashboardRequestContext $dashboardRequestContext - Request context shared with handlers.
     *
     * @return int - Command::SUCCESS on clean shutdown (socket closed), Command::FAILURE when the bind never succeeded
     */
    public function serve(OutputInterface $output, string $host, int $port, DashboardRequestContext $dashboardRequestContext): int
    {
        $server = $this->createServer($host, $port, $errorCode, $errorMessage);

        // Socket bind failed (port already in use, or permission denied); tell the user and give up before serving.
        if ($server === false) {
            $output->writeln(sprintf('<error>Unable to start dashboard on %s:%d: %s (%d)</error>', $host, $port, $errorMessage, $errorCode));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Serving gruff-php dashboard at %s</info>', $this->url($host, $port, $dashboardRequestContext)));
        $output->writeln('<comment>Use the form to refresh the scan or point gruff-php at another project. Press Ctrl+C to stop.</comment>');

        $handler = $this->handler($dashboardRequestContext);

        // Keep accepting browser connections for as long as the listening socket stays open.
        while (is_resource($server)) {
            $client = $this->acceptClient($server);

            // No client this tick (the 1-second accept timeout fired): loop back and keep waiting.
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

        // Reached only when the listening socket closed (Ctrl+C / external close): a clean shutdown, not an error.
        return Command::SUCCESS;
    }

    /**
     * Builds the dashboard URL printed to the console, pre-seeded with the user's launch options.
     *
     * @param string                  $host                    - Bound host the browser should connect back to.
     * @param int                     $port                    - Bound TCP port to embed in the URL.
     * @param DashboardRequestContext $dashboardRequestContext - Request context whose input/projectRoot seed the default query string.
     *
     * @return string - http URL the operator opens, pre-seeded with the launch options as the default query string
     */
    private function url(string $host, int $port, DashboardRequestContext $dashboardRequestContext): string
    {

        return sprintf(
            'http://%s:%d/?%s',
            $this->urlHost($host),
            $port,
            http_build_query($this->stateFactory->defaultQuery($dashboardRequestContext->input, $dashboardRequestContext->projectRoot), '', '&', PHP_QUERY_RFC3986),
        );
    }

    /**
     * Adds URL brackets to a colon-containing host unless it already starts with '['.
     *
     * @param string $host - Bound host or address; an unbracketed IPv6 literal here gets wrapped.
     *
     * @return string - Host unchanged, or bracketed when it contains ':' and does not already start with '['.
     */
    private function urlHost(string $host): string
    {

        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }

        return $host;
    }

    /**
     * Builds the request handler reused by the server's connection loop.
     *
     * @param DashboardRequestContext $dashboardRequestContext - Request context threaded into the handler and its scan runner.
     *
     * @return DashboardRequestHandler - Handler with a renderer, scan runner, and HTTP responder shared across connections.
     */
    private function handler(DashboardRequestContext $dashboardRequestContext): DashboardRequestHandler
    {
        $dashboardPageRenderer = new DashboardPageRenderer();
        $dashboardScanRunner   = new DashboardScanRunner($this->gruffBinary, $this->stateFactory, $dashboardPageRenderer);

        return new DashboardRequestHandler($dashboardRequestContext, $this->stateFactory, $dashboardScanRunner, new DashboardHttpResponder());
    }

    /**
     * Opens the listening socket with PHP warnings suppressed, so the caller decides how to report a failed bind.
     *
     * @param string      $host         - Host or address to bind; IPv6 literals are bracketed before use.
     * @param int         $port         - TCP port to bind.
     * @param int|null    $errorCode    - Receives the socket errno on failure; caller passes an unset variable to be filled.
     * @param string|null $errorMessage - Receives the human-readable bind error on failure; pairs with $errorCode.
     * @param-out int|null $errorCode - Socket error code output; inspect when the function returns false.
     * @param-out string|null $errorMessage - Socket error message output; inspect when the function returns false.
     *
     * @return resource|false - listening stream on success, or false when the bind fails (details in the out params)
     */
    private function createServer(string $host, int $port, ?int &$errorCode, ?string &$errorMessage)
    {
        set_error_handler(static fn(): bool => true);

        try {

            return stream_socket_server(sprintf('tcp://%s:%d', $this->urlHost($host), $port), $errorCode, $errorMessage);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Accepts one browser connection, or returns false on the short timeout so the loop keeps polling.
     *
     * @param resource $server - Listening stream created by createServer().
     *
     * @return resource|false - accepted client stream, or false on the 1s accept timeout or error so the loop retries
     */
    private function acceptClient($server)
    {
        set_error_handler(static fn(): bool => true);

        try {

            return stream_socket_accept($server, 1);
        } finally {
            restore_error_handler();
        }
    }
}
