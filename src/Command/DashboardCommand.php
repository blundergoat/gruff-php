<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class DashboardCommand extends Command
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 8765;

    protected function configure(): void
    {
        $this
            ->setName('dashboard')
            ->setDescription('Serve the local gruff dashboard.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Initial files or directories to analyse.')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Initial project root for scans.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host for the dashboard server.', self::DEFAULT_HOST)
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port for the dashboard server.', (string) self::DEFAULT_PORT)
            ->addOption('scan-timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to allow each refresh scan. Mutation runs are not timed out. Use 0 to disable.', '120')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the scan: advisory, warning, error, or none.', 'none')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Initial gruff JSON config path.')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff.json file for dashboard scans.')
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_OPTIONAL,
                'Initial gruff baseline JSON path. Defaults to "gruff-baseline.json" at the project root when present.',
            )
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Skip auto-applying the default baseline file for dashboard scans.')
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd();

        if ($cwd === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $projectRoot = $this->initialProjectRoot($input, $cwd);

        if ($projectRoot === null) {
            $output->writeln('<error>Initial --project must resolve to an existing directory.</error>');

            return Command::INVALID;
        }

        $port = $this->port($input, $output);

        if ($port === false) {
            return Command::INVALID;
        }

        $scanTimeout = $this->scanTimeout($input, $output);

        if ($scanTimeout === false) {
            return Command::INVALID;
        }

        return $this->serve($input, $output, $cwd, $projectRoot, $port, $scanTimeout);
    }

    private function serve(
        InputInterface $input,
        OutputInterface $output,
        string $launchRoot,
        string $projectRoot,
        int $port,
        ?float $scanTimeout,
    ): int {
        $host = $this->optionalStringOption($input, 'host') ?? self::DEFAULT_HOST;
        $server = $this->createServer($host, $port, $errorCode, $errorMessage);

        if ($server === false) {
            $output->writeln(sprintf('<error>Unable to start dashboard on %s:%d: %s (%d)</error>', $host, $port, $errorMessage, $errorCode));

            return Command::FAILURE;
        }

        $url = sprintf(
            'http://%s:%d/?%s',
            $host,
            $port,
            http_build_query($this->defaultQuery($input, $projectRoot), '', '&', PHP_QUERY_RFC3986),
        );
        $output->writeln(sprintf('<info>Serving gruff dashboard at %s</info>', $url));
        $output->writeln('<comment>Use the form to refresh the scan or point gruff at another project. Press Ctrl+C to stop.</comment>');

        while ($this->serverOpen($server)) {
            $client = $this->acceptClient($server);

            if ($client === false) {
                continue;
            }

            try {
                $this->handleClient($client, $input, $launchRoot, $projectRoot, $scanTimeout);
            } finally {
                fclose($client);
            }
        }

        fclose($server);

        return Command::SUCCESS;
    }

    /**
     * @param resource $server
     */
    private function serverOpen($server): bool
    {
        return is_resource($server);
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

    /**
     * @param resource $client
     */
    private function handleClient($client, InputInterface $input, string $launchRoot, string $projectRoot, ?float $scanTimeout): void
    {
        $requestLine = fgets($client);

        if (!is_string($requestLine) || !preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?\r?\n$/', $requestLine, $matches)) {
            $this->writeResponse($client, 400, 'Bad Request', 'Bad Request', 'text/plain; charset=UTF-8');

            return;
        }

        $method = $matches[1];
        $target = $matches[2];
        $this->drainHeaders($client);

        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->writeResponse($client, 405, 'Method Not Allowed', 'Method Not Allowed', 'text/plain; charset=UTF-8', $method === 'HEAD');

            return;
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $query = $this->query($target);

        if ($path === '/health') {
            $this->writeResponse($client, 200, 'OK', 'ok', 'text/plain; charset=UTF-8', $method === 'HEAD');

            return;
        }

        if ($path === '/favicon.ico') {
            $this->writeResponse($client, 204, 'No Content', '', 'text/plain; charset=UTF-8', $method === 'HEAD');

            return;
        }

        if ($path === '/') {
            $this->writeResponse($client, 200, 'OK', $this->dashboardHtml($input, $projectRoot, $query), 'text/html; charset=UTF-8', $method === 'HEAD');

            return;
        }

        if ($path === '/scan') {
            $this->writeResponse($client, 200, 'OK', $this->scanHtml($input, $launchRoot, $projectRoot, $query, $scanTimeout), 'text/html; charset=UTF-8', $method === 'HEAD');

            return;
        }

        $this->writeResponse($client, 404, 'Not Found', 'Not Found', 'text/plain; charset=UTF-8', $method === 'HEAD');
    }

    /**
     * @param array<string, string> $query
     */
    private function dashboardHtml(InputInterface $input, string $projectRoot, array $query): string
    {
        $state = $this->dashboardState($input, $projectRoot, $query);

        return (new DashboardPageRenderer())->dashboardHtml($state);
    }

    /**
     * @param array<string, string> $query
     */
    private function scanHtml(InputInterface $input, string $launchRoot, string $projectRoot, array $query, ?float $scanTimeout): string
    {
        $state = $this->dashboardState($input, $projectRoot, $query);
        $renderer = new DashboardPageRenderer();
        $commandBuilder = new DashboardScanCommandBuilder($this->gruffBinary());
        $scanRoot = $this->resolveProjectRoot($state['project'], $launchRoot);

        if ($scanRoot === null) {
            return $renderer->errorHtml(
                'Project root is not an existing directory.',
                sprintf('Project: %s', $state['project']),
                Command::INVALID,
                0,
            );
        }

        $paths = $commandBuilder->parsePaths($state['paths']);
        $editedUnitTestFiles = [];

        if ($state['mutation'] === 'run') {
            $editedUnitTestFiles = $commandBuilder->editedUnitTestFiles($scanRoot);

            if ($editedUnitTestFiles === []) {
                return $renderer->errorHtml(
                    'No edited unit test files found.',
                    'Dashboard mutation analysis checks only PHPUnit unit test files changed relative to HEAD under tests/. Newly created untracked unit test files are included when the project is a git repository. Use scripts/mutation-test-full.sh for a full unit-suite mutation run.',
                    Command::SUCCESS,
                    0,
                );
            }
        }

        $command = $commandBuilder->analyseCommand($paths, $state, $scanRoot, $editedUnitTestFiles);
        $startedAt = microtime(true);
        $process = new Process($command, $scanRoot);
        $process->setTimeout($state['mutation'] === 'run' ? null : $scanTimeout);
        $stderr = '';
        $exitCode = Command::SUCCESS;

        try {
            $process->run();
            $stderr = $process->getErrorOutput();
            $exitCode = $process->getExitCode() ?? Command::FAILURE;
        } catch (ProcessTimedOutException $exception) {
            $stderr = $exception->getMessage();
            $exitCode = Command::FAILURE;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $html = $process->getOutput();

        if ($html === '') {
            return $renderer->errorHtml('The scan did not produce HTML output.', $stderr === '' ? 'No stderr output.' : $stderr, $exitCode, $durationMs);
        }

        $html = $renderer->injectMutationButtons($html, $state);

        return $renderer->injectDashboardMetadata($html, $scanRoot, $command, $exitCode, $durationMs);
    }

    /**
     * @param array<string, string> $query
     * @return array{project: string, paths: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, mutation: string}
     */
    private function dashboardState(InputInterface $input, string $projectRoot, array $query): array
    {
        $defaults = $this->defaultQuery($input, $projectRoot);

        return [
            'project' => $query['project'] ?? $defaults['project'],
            'paths' => $query['paths'] ?? $defaults['paths'],
            'failOn' => $query['failOn'] ?? $defaults['failOn'],
            'config' => $query['config'] ?? $defaults['config'],
            'baseline' => $query['baseline'] ?? $defaults['baseline'],
            'noBaseline' => ($query['noBaseline'] ?? $defaults['noBaseline']) === '1' ? '1' : '0',
            'noConfig' => ($query['noConfig'] ?? $defaults['noConfig']) === '1' ? '1' : '0',
            'includeIgnored' => ($query['includeIgnored'] ?? $defaults['includeIgnored']) === '1' ? '1' : '0',
            'mutation' => ($query['mutation'] ?? $defaults['mutation']) === 'run' ? 'run' : 'off',
        ];
    }

    /**
     * @return array{project: string, paths: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, mutation: string}
     */
    private function defaultQuery(InputInterface $input, string $projectRoot): array
    {
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $baseline = $input->hasParameterOption('--baseline', true)
            ? ($this->optionalStringOption($input, 'baseline') ?? '')
            : '';

        return [
            'project' => $projectRoot,
            'paths' => implode(' ', $paths === [] ? ['.'] : $paths),
            'failOn' => $this->optionalStringOption($input, 'fail-on') ?? 'none',
            'config' => $this->optionalStringOption($input, 'config') ?? '',
            'baseline' => $baseline,
            'noBaseline' => (bool) $input->getOption('no-baseline') ? '1' : '0',
            'noConfig' => (bool) $input->getOption('no-config') ? '1' : '0',
            'includeIgnored' => (bool) $input->getOption('include-ignored') ? '1' : '0',
            'mutation' => 'off',
        ];
    }

    private function initialProjectRoot(InputInterface $input, string $cwd): ?string
    {
        return $this->resolveProjectRoot($this->optionalStringOption($input, 'project') ?? $cwd, $cwd);
    }

    private function resolveProjectRoot(string $project, string $baseRoot): ?string
    {
        $path = str_starts_with($project, '/') ? $project : $baseRoot . '/' . $project;
        $realPath = realpath($path);

        return is_string($realPath) && is_dir($realPath) ? $realPath : null;
    }

    /**
     * @return array<string, string>
     */
    private function query(string $target): array
    {
        $queryString = parse_url($target, PHP_URL_QUERY);

        if (!is_string($queryString) || $queryString === '') {
            return [];
        }

        parse_str($queryString, $query);
        $clean = [];

        foreach ($query as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $clean[$key] = (string) $value;
        }

        return $clean;
    }

    /**
     * @param resource $client
     */
    private function drainHeaders($client): void
    {
        while (($line = fgets($client)) !== false) {
            if ($line === "\r\n" || $line === "\n") {
                return;
            }
        }
    }

    /**
     * @param resource $client
     */
    private function writeResponse($client, int $status, string $reason, string $body, string $contentType, bool $headOnly = false): void
    {
        $headers = sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: %s\r\nContent-Length: %d\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n",
            $status,
            $reason,
            $contentType,
            strlen($body),
        );

        fwrite($client, $headers);

        if (!$headOnly) {
            fwrite($client, $body);
        }
    }

    private function port(InputInterface $input, OutputInterface $output): int|false
    {
        $rawPort = $input->getOption('port');

        if (!is_string($rawPort) || !ctype_digit($rawPort)) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            return false;
        }

        $port = (int) $rawPort;

        if ($port < 1 || $port > 65535) {
            $output->writeln('<error>--port must be an integer from 1 to 65535.</error>');

            return false;
        }

        return $port;
    }

    private function scanTimeout(InputInterface $input, OutputInterface $output): float|false|null
    {
        $rawTimeout = $input->getOption('scan-timeout');

        if (!is_string($rawTimeout) || !ctype_digit($rawTimeout)) {
            $output->writeln('<error>--scan-timeout must be a non-negative integer.</error>');

            return false;
        }

        $timeout = (int) $rawTimeout;

        return $timeout === 0 ? null : (float) $timeout;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function gruffBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/gruff';
    }
}
