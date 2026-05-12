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
     * Create a dashboard server using the shared state factory and gruff binary path.
     *
     * @param DashboardStateFactory $stateFactory Factory used to build dashboard state.
     * @param string                $gruffBinary  Absolute gruff binary path used for scan requests.
     */
    public function __construct(
        private DashboardStateFactory $stateFactory,
        private string $gruffBinary,
    ) {
    }

    /**
     * Bind the dashboard socket and process HTTP clients until the socket closes.
     *
     * @param OutputInterface         $output  Console output for the dashboard URL and errors.
     * @param string                  $host    Hostname or address to bind.
     * @param int                     $port    Port to bind.
     * @param DashboardRequestContext $context Request context shared with handlers.
     * @return int Symfony command exit code.
     */
    public function serve(OutputInterface $output, string $host, int $port, DashboardRequestContext $context): int
    {
        $server = $this->createServer($host, $port, $errorCode, $errorMessage);

        if ($server === false) {
            $output->writeln(sprintf('<error>Unable to start dashboard on %s:%d: %s (%d)</error>', $host, $port, $errorMessage, $errorCode));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Serving gruff dashboard at %s</info>', $this->url($host, $port, $context)));
        $output->writeln('<comment>Use the form to refresh the scan or point gruff at another project. Press Ctrl+C to stop.</comment>');

        while (is_resource($server)) {
            $client = $this->acceptClient($server);

            if ($client === false) {
                continue;
            }

            try {
                $this->handler($context)->handleRequest($client);
            } finally {
                fclose($client);
            }
        }

        fclose($server);

        return Command::SUCCESS;
    }

    /**
     * Build the initial dashboard URL shown in console output.
     *
     * @return string Dashboard URL with default query state.
     */
    private function url(string $host, int $port, DashboardRequestContext $context): string
    {
        return sprintf(
            'http://%s:%d/?%s',
            $host,
            $port,
            http_build_query($this->stateFactory->defaultQuery($context->input, $context->projectRoot), '', '&', PHP_QUERY_RFC3986),
        );
    }

    /**
     * Build the per-request dashboard handler.
     *
     * @return DashboardRequestHandler HTTP request handler.
     */
    private function handler(DashboardRequestContext $context): DashboardRequestHandler
    {
        $renderer   = new DashboardPageRenderer();
        $scanRunner = new DashboardScanRunner($this->gruffBinary, $this->stateFactory, $renderer);

        return new DashboardRequestHandler($context, $this->stateFactory, $scanRunner, new DashboardHttpResponder());
    }

    /**
     * @param-out int|null $errorCode
     * @param-out string|null $errorMessage
     * @return resource|false
     */
    private function createServer(string $host, int $port, ?int &$errorCode, ?string &$errorMessage)
    {
        set_error_handler(static fn (): bool => true);

        try {
            return stream_socket_server(sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param resource $server
     * @return resource|false
     */
    private function acceptClient($server)
    {
        set_error_handler(static fn (): bool => true);

        try {
            return stream_socket_accept($server, 1);
        } finally {
            restore_error_handler();
        }
    }
}
