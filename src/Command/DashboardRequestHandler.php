<?php

declare(strict_types=1);

namespace GruffPhp\Command;

/**
 * Parses and routes one dashboard HTTP request.
 */
final readonly class DashboardRequestHandler
{
    /**
     * Maximum bytes allowed in the HTTP request line.
     */
    private const MAX_REQUEST_LINE_BYTES = 8192;

    /**
     * Maximum number of HTTP header lines accepted per request.
     */
    private const MAX_HEADER_LINES = 100;

    /**
     * Maximum total header bytes accepted per request.
     */
    private const MAX_HEADER_BYTES = 16384;

    /**
     * Create a request handler for one dashboard server context.
     *
     * @param DashboardRequestContext $dashboardRequestContext - Request context shared by dashboard routes.
     * @param DashboardStateFactory   $stateFactory - Factory used to build dashboard state.
     * @param DashboardScanRunner     $scanRunner - Runner used for scan requests.
     * @param DashboardHttpResponder  $responder - Responder used to write HTTP responses.
     */
    public function __construct(
        private DashboardRequestContext $dashboardRequestContext,
        private DashboardStateFactory $stateFactory,
        private DashboardScanRunner $scanRunner,
        private DashboardHttpResponder $responder,
    ) {
    }

    /**
     * Read, route, and write one HTTP request from a socket client.
     *
     * @param resource $client - Connected client stream to read from and write the response to.
     *
     * @return void
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
     * Read one dashboard HTTP request from the client socket.
     *
     * @param resource $client - Connected client stream positioned at the request line.
     *
     * @return array{method: string, target: string, headers: array<string, string>}|DashboardHttpResponse|null - the parsed request (method, target, headers) when well-formed; a DashboardHttpResponse to send verbatim when a size limit or duplicate Host was hit; null when the connection dropped or the request line was malformed
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

        // Parse the HTTP request line into method, target, and protocol version.
        if (!preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?\r?\n$/', $requestLine, $matches)) {
            return null;
        }

        $headers = $this->headers($client);

        if ($headers === null) {
            return null;
        }

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
     * @param string                $method - HTTP verb; only GET and HEAD are served, anything else returns 405.
     * @param string                $target - Raw request target (path plus query); parsed for the route and params.
     * @param array<string, string> $headers - Lower-cased request headers; the Host entry gates access before any route.
     *
     * @return DashboardHttpResponse - the routed reply: a 200 page/health/scan body for an allowed GET/HEAD, or the matching error (405 wrong verb, 421 disallowed host, 404 unknown path)
     */
    private function responseFor(string $method, string $target, array $headers): DashboardHttpResponse
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return new DashboardHttpResponse(405, 'Method Not Allowed', 'Method Not Allowed', 'text/plain; charset=UTF-8');
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        if ($path !== '/health' && !$this->isHostAllowed($headers['host'] ?? null)) {
            // Reject cross-origin/DNS-rebinding hosts with 421; /health stays open for liveness probes.
            return new DashboardHttpResponse(421, 'Misdirected Request', 'Misdirected Request', 'text/plain; charset=UTF-8');
        }

        $query = $this->query($target);

        return match ($path) {
            '/health' => new DashboardHttpResponse(200, 'OK', 'ok', 'text/plain; charset=UTF-8'),
            '/favicon.ico' => new DashboardHttpResponse(204, 'No Content', '', 'text/plain; charset=UTF-8'),
            '/' => new DashboardHttpResponse(200, 'OK', $this->dashboardHtml($query), 'text/html; charset=UTF-8'),
            '/scan' => new DashboardHttpResponse(200, 'OK', $this->scanRunner->scanHtml($this->dashboardRequestContext, $query), 'text/html; charset=UTF-8'),
            default => new DashboardHttpResponse(404, 'Not Found', 'Not Found', 'text/plain; charset=UTF-8'),
        };
    }

    /**
     * @param array<string, string> $query - Sanitised query params used to seed dashboard state.
     *
     * @return string - the complete HTML document for the dashboard page, with state derived from the query params already rendered in
     */
    private function dashboardHtml(array $query): string
    {
        $state = $this->stateFactory->state($this->dashboardRequestContext->input, $this->dashboardRequestContext->projectRoot, $query);

        return (new DashboardPageRenderer())->dashboardHtml($state);
    }

    /**
     * Parse dashboard query parameters from the request target.
     *
     * @param string $target - Raw request target; only its query string is read, and non-scalar values are dropped.
     *
     * @return array<string, string> - scalar query params keyed by name, each stringified; empty when the target has no query string
     */
    private function query(string $target): array
    {
        $rawQuery = parse_url($target, PHP_URL_QUERY);

        if (!is_string($rawQuery) || $rawQuery === '') {
            return [];
        }

        parse_str($rawQuery, $query);
        $clean = [];

        foreach ($query as $key => $queryValue) {
            if (!is_string($key) || !is_scalar($queryValue)) {
                continue;
            }

            $clean[$key] = (string) $queryValue;
        }

        return $clean;
    }

    /**
     * Read dashboard HTTP headers until the request header block ends.
     *
     * @param resource $client - Connected client stream positioned at the first header line.
     *
     * @return array<string, string>|DashboardHttpResponse|null - lower-cased header name to value map on the blank-line terminator; a DashboardHttpResponse (431/400) when the line/byte budget overran or a duplicate Host appeared; null when the stream ended before the terminator
     */
    private function headers($client): array|DashboardHttpResponse|null
    {
        $headers   = [];
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

            if ($name === 'host' && array_key_exists('host', $headers)) {
                // A second Host header is an ambiguity/smuggling risk; reject the request with 400.
                return new DashboardHttpResponse(400, 'Bad Request', 'Bad Request', 'text/plain; charset=UTF-8');
            }

            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return null;
    }

    /**
     * Validate the Host header against the dashboard bind host and port.
     *
     * @param ?string $hostHeader - Raw Host header, or null when absent; missing or empty is rejected as not allowed.
     *
     * @return bool - true when the Host header's name and port match this dashboard's bind target; false denies the request as a likely cross-origin or DNS-rebinding attempt
     */
    private function isHostAllowed(?string $hostHeader): bool
    {
        if ($hostHeader === null || $hostHeader === '') {
            // No Host header means we cannot prove the request targets this dashboard; deny it.
            return false;
        }

        $host = strtolower($hostHeader);
        $port = $this->dashboardRequestContext->bindPort;

        // Parse bracketed IPv6 host headers with optional ports.
        if (preg_match('/^\[(?<host>[^\]]+)\](?::(?<port>\d+))?$/', $host, $matches) === 1) {
            $host = '[' . $matches['host'] . ']';
            if (isset($matches['port'])) {
                $port = (int) $matches['port'];
            }
        } else {
            // Parse non-IPv6 host headers with optional ports.
            if (preg_match('/^(?<host>[^:]+)(?::(?<port>\d+))?$/', $host, $matches) === 1) {
                $host = $matches['host'];
                if (isset($matches['port'])) {
                    $port = (int) $matches['port'];
                }
            }
        }

        if ($port !== $this->dashboardRequestContext->bindPort) {
            // Host header port disagrees with the port we bound; reject to block port-confusion requests.
            return false;
        }

        if ($this->isBindHostLoopback()) {
            // Bound to loopback, so only the canonical loopback names are legitimate.
            return in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        }

        if ($this->isBindHostWildcard()) {
            // Bound to all interfaces, so the host name cannot be pinned; accept any matching-port host.
            return true;
        }

        $bindHost = strtolower($this->dashboardRequestContext->bindHost);

        if (str_contains($bindHost, ':') && !str_starts_with($bindHost, '[')) {
            $bindHost = '[' . $bindHost . ']';
        }

        return $host === $bindHost;
    }

    /**
     * Check whether the configured bind host is a loopback address.
     *
     * @return bool - true when the configured bind host is any loopback spelling (127.0.0.1, localhost, ::1), which narrows the allowed Host names to those
     */
    private function isBindHostLoopback(): bool
    {
        return in_array(strtolower($this->dashboardRequestContext->bindHost), ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    /**
     * Check whether the configured bind host is a wildcard address.
     *
     * @return bool - true when bound to a wildcard address (0.0.0.0, ::), meaning the request Host name cannot be pinned and only its port is checked
     */
    private function isBindHostWildcard(): bool
    {
        return in_array(strtolower($this->dashboardRequestContext->bindHost), ['0.0.0.0', '::', '[::]'], true);
    }

    /**
     * Build the response used when request headers exceed dashboard limits.
     *
     * @return DashboardHttpResponse - the shared 431 Request Header Fields Too Large reply, reused for both an over-long request line and an over-budget header block
     */
    private function tooLargeResponse(): DashboardHttpResponse
    {
        return new DashboardHttpResponse(431, 'Request Header Fields Too Large', 'Request Header Fields Too Large', 'text/plain; charset=UTF-8');
    }
}
