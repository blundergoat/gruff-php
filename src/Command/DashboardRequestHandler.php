<?php

declare(strict_types=1);

namespace GruffPhp\Command;

final readonly class DashboardRequestHandler
{
    private const MAX_REQUEST_LINE_BYTES = 8192;
    private const MAX_HEADER_LINES = 100;
    private const MAX_HEADER_BYTES = 16384;

    /**
     * Create a request handler for one dashboard server context.
     */
    public function __construct(
        private DashboardRequestContext $context,
        private DashboardStateFactory $stateFactory,
        private DashboardScanRunner $scanRunner,
        private DashboardHttpResponder $responder,
    ) {
    }

    /**
     * Read, route, and write one HTTP request from a socket client.
     *
     * @param resource $client
     *
     * @return void No return value.
     */
    public function handleRequest($client): void
    {
        $request = $this->request($client);

        if ($request === null) {
            $this->responder->write($client, new DashboardHttpResponse(400, 'Bad Request', 'Bad Request', 'text/plain; charset=UTF-8'), false);

            return;
        }

        if ($request instanceof DashboardHttpResponse) {
            $this->responder->write($client, $request, false);

            return;
        }

        $this->responder->write(
            $client,
            $this->responseFor($request['method'], $request['target'], $request['headers']),
            $request['method'] === 'HEAD',
        );
    }

    /**
     * @param resource $client
     * @return array{method: string, target: string, headers: array<string, string>}|DashboardHttpResponse|null
     */
    private function request($client): array|DashboardHttpResponse|null
    {
        $requestLine = fgets($client, self::MAX_REQUEST_LINE_BYTES + 2);

        if (!is_string($requestLine)) {
            return null;
        }

        if (strlen($requestLine) > self::MAX_REQUEST_LINE_BYTES) {
            return $this->tooLargeResponse();
        }

        if (!preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?\r?\n$/', $requestLine, $matches)) {
            return null;
        }

        $headers = $this->headers($client);

        if ($headers instanceof DashboardHttpResponse) {
            return $headers;
        }

        return [
            'method' => $matches[1],
            'target' => $matches[2],
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return DashboardHttpResponse Response for the request target.
     */
    private function responseFor(string $method, string $target, array $headers): DashboardHttpResponse
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return new DashboardHttpResponse(405, 'Method Not Allowed', 'Method Not Allowed', 'text/plain; charset=UTF-8');
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        if ($path !== '/health' && !$this->hostAllowed($headers['host'] ?? null)) {
            return new DashboardHttpResponse(421, 'Misdirected Request', 'Misdirected Request', 'text/plain; charset=UTF-8');
        }

        $query = $this->query($target);

        return match ($path) {
            '/health' => new DashboardHttpResponse(200, 'OK', 'ok', 'text/plain; charset=UTF-8'),
            '/favicon.ico' => new DashboardHttpResponse(204, 'No Content', '', 'text/plain; charset=UTF-8'),
            '/' => new DashboardHttpResponse(200, 'OK', $this->dashboardHtml($query), 'text/html; charset=UTF-8'),
            '/scan' => new DashboardHttpResponse(200, 'OK', $this->scanRunner->scanHtml($this->context, $query), 'text/html; charset=UTF-8'),
            default => new DashboardHttpResponse(404, 'Not Found', 'Not Found', 'text/plain; charset=UTF-8'),
        };
    }

    /**
     * @param array<string, string> $query
     *
     * @return string Dashboard HTML shell.
     */
    private function dashboardHtml(array $query): string
    {
        $state = $this->stateFactory->state($this->context->input, $this->context->projectRoot, $query);

        return (new DashboardPageRenderer())->dashboardHtml($state);
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
     * @return array<string, string>|DashboardHttpResponse
     */
    private function headers($client): array|DashboardHttpResponse
    {
        $headers = [];
        $lineCount = 0;
        $byteCount = 0;

        while (($line = fgets($client, self::MAX_HEADER_BYTES + 2)) !== false) {
            $lineCount++;
            $byteCount += strlen($line);

            if ($lineCount > self::MAX_HEADER_LINES || $byteCount > self::MAX_HEADER_BYTES) {
                return $this->tooLargeResponse();
            }

            if ($line === "\r\n" || $line === "\n") {
                return $headers;
            }

            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            if ($name === '') {
                continue;
            }

            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return $headers;
    }

    /**
     * Validate the Host header against the dashboard bind host and port.
     *
     * @return bool True when the request is allowed for this local dashboard.
     */
    private function hostAllowed(?string $hostHeader): bool
    {
        if ($hostHeader === null || $hostHeader === '') {
            return false;
        }

        $host = strtolower($hostHeader);
        $port = null;

        if (preg_match('/^\[(?<host>[^\]]+)\]:(?<port>\d+)$/', $host, $matches) === 1) {
            $host = '[' . $matches['host'] . ']';
            $port = (int) $matches['port'];
        } elseif (preg_match('/^(?<host>[^:]+):(?<port>\d+)$/', $host, $matches) === 1) {
            $host = $matches['host'];
            $port = (int) $matches['port'];
        }

        if ($port !== $this->context->bindPort) {
            return false;
        }

        if ($this->bindHostIsLoopback()) {
            return in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        }

        return $host === strtolower($this->context->bindHost);
    }

    /**
     * Check whether the configured bind host is a loopback address.
     *
     * @return bool True for localhost loopback hosts.
     */
    private function bindHostIsLoopback(): bool
    {
        return in_array(strtolower($this->context->bindHost), ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    /**
     * Build the response used when request headers exceed dashboard limits.
     *
     * @return DashboardHttpResponse 431 response.
     */
    private function tooLargeResponse(): DashboardHttpResponse
    {
        return new DashboardHttpResponse(431, 'Request Header Fields Too Large', 'Request Header Fields Too Large', 'text/plain; charset=UTF-8');
    }
}
