<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

/**
 * Parses client requests and routes them to the dashboard shell, scan runner, or health probe.
 * The server reuses one handler for sequential connections, with the responder writing each reply.
 *
 * Request-line and header limits bound parsing, and duplicate Host headers are rejected.
 * Routes other than /health apply the host policy selected by the configured bind address.
 */
final readonly class DashboardRequestHandler
{
    /**
     * Largest request line (method, target, and version) the dashboard will read; a longer one is
     * refused with `431` instead of being buffered, so a giant URL cannot exhaust memory.
     */
    private const MAX_REQUEST_LINE_BYTES = 8192;

    /**
     * Maximum header-reader iterations, including the terminating blank line; exceeding this returns 431.
     */
    private const MAX_HEADER_LINES = 100;

    /**
     * Total header bytes allowed per request; once the running count crosses this the dashboard
     * answers `431` rather than keep buffering - the second half of the header-size guard.
     */
    private const MAX_HEADER_BYTES = 16384;

    /**
     * Wires up the collaborators the dashboard connections need; the server builds this handler
     * once and reuses the same instance for every accepted client.
     *
     * @param DashboardRequestContext $dashboardRequestContext - Bind settings, launch input, and default project shared across requests.
     * @param DashboardStateFactory   $stateFactory - Builds the dashboard view state rendered into the `/` page.
     * @param DashboardScanRunner     $scanRunner - Runs a scan and returns its HTML for the `/scan` route.
     * @param DashboardHttpResponder  $responder - Writes the finished HTTP response back to the client socket.
     */
    public function __construct(
        private DashboardRequestContext $dashboardRequestContext,
        private DashboardStateFactory $stateFactory,
        private DashboardScanRunner $scanRunner,
        private DashboardHttpResponder $responder,
    ) {
    }

    /**
     * Reads one client request and writes its routed response, or a parser error reply.
     * A failed socket write propagates to the server's per-client error handler.
     *
     * @param resource $client - Connected client stream to read the request from and write the response to.
     *
     * @return void - Nothing is returned; the reply is written straight to the client socket.
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
     * Parses one request line and header block for handleRequest().
     * Returns parsed fields, a ready-made error response, or null for a malformed request line or incomplete input.
     *
     * @param resource $client - Connected client stream positioned at the request line.
     *
     * @return array{method: string, target: string, headers: array<string, string>}|DashboardHttpResponse|null
     *         - Parsed fields, a 431/duplicate-Host 400 response, or null for a malformed request line or incomplete input.
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

        // Reject incomplete request lines and lower-case method names before consuming any headers.
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
     * Routes GET and HEAD requests, applying the configured Host policy except for /health.
     * Returns 405 for other methods, 421 for a denied host, and 404 for an unknown path.
     *
     * @param string                $method - HTTP verb; only GET and HEAD are served, anything else returns 405.
     * @param string                $target - Raw request target (path plus query); parsed for the route and params.
     * @param array<string, string> $headers - Header names normalised to lower case; Host is checked outside /health.
     *
     * @return DashboardHttpResponse - Route output, or 405 for unsupported methods, 421 for a denied host, or 404 for an unknown path.
     */
    private function responseFor(string $method, string $target, array $headers): DashboardHttpResponse
    {

        if ($method !== 'GET' && $method !== 'HEAD') {
            return new DashboardHttpResponse(405, 'Method Not Allowed', 'Method Not Allowed', 'text/plain; charset=UTF-8');
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';


        if ($path !== '/health' && !$this->isHostAllowed($headers['host'] ?? null)) {
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
     * Renders the main dashboard page a user lands on at /, folding the request's query params
     * (paths, fail-on, scope, and the other controls) into the view state before it is turned into HTML.
     *
     * @param array<string, string> $query - Scalar request query values used to seed form controls.
     *
     * @return string - Complete dashboard HTML with query-derived state rendered into its controls.
     */
    private function dashboardHtml(array $query): string
    {
        $state = $this->stateFactory->state($this->dashboardRequestContext->input, $this->dashboardRequestContext->projectRoot, $query);

        return (new DashboardPageRenderer())->dashboardHtml($state);
    }

    /**
     * Extracts string-keyed scalar query values from the request target.
     * Missing or empty query strings return an empty map; array-valued parameters are discarded.
     *
     * @param string $target - Raw request target; only its query string is read, and non-scalar values are dropped.
     *
     * @return array<string, string> - String-keyed scalar values converted to strings; empty when no values survive filtering.
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
     * Reads header lines off the socket up to the blank line that closes the block, enforcing the
     * line and byte caps as it goes and refusing a second Host header. Runs right after the
     * request line is parsed, and its three return shapes decide what request() does next.
     *
     * @param resource $client - Connected client stream positioned at the first header line.
     *
     * @return array<string, string>|DashboardHttpResponse|null - Lower-case header map, 431/duplicate-Host 400, or null for incomplete input.
     */
    private function headers($client): array|DashboardHttpResponse|null
    {
        $headers   = [];
        $lineCount = 0;
        $byteCount = 0;


        while (($line = fgets($client, self::MAX_HEADER_BYTES + 2)) !== false) {
            $lineCount++;
            $byteCount += strlen($line);

            // The client has piled on more header lines or bytes than allowed; stop and answer 431 instead of buffering a possible flood.
            if ($lineCount > self::MAX_HEADER_LINES || $byteCount > self::MAX_HEADER_BYTES) {
                return $this->tooLargeResponse();
            }


            if ($line === "\r\n" || $line === "\n") {
                return $headers;
            }

            $separator = strpos($line, ':');
            // A header line with no colon is malformed rather than fatal, so skip it and keep reading.
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));

            if ($name === '') {
                continue;
            }

            // A second Host header is an ambiguity and a request-smuggling risk, so refuse the whole request with 400.
            if ($name === 'host' && array_key_exists('host', $headers)) {
                return new DashboardHttpResponse(400, 'Bad Request', 'Bad Request', 'text/plain; charset=UTF-8');
            }

            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return null;
    }

    /**
     * Applies the configured bind policy to a Host header; missing or empty values are rejected.
     * A parsed port must match the bound port; if no port is parsed, the bound port is assumed.
     * Loopback binds allow local aliases, wildcard binds allow any name, and other binds require an exact host match.
     *
     * @param ?string $hostHeader - Raw Host header; null or empty is rejected, and an omitted port uses the bound port.
     *
     * @return bool - Whether the Host header satisfies the configured bind policy.
     */
    private function isHostAllowed(?string $hostHeader): bool
    {

        if ($hostHeader === null || $hostHeader === '') {
            return false;
        }

        $host = strtolower($hostHeader);
        $port = $this->dashboardRequestContext->bindPort;

        // Brackets keep embedded colons inside the host, while an optional numeric suffix supplies the port.
        if (preg_match('/^\[(?<host>[^\]]+)\](?::(?<port>\d+))?$/', $host, $matches) === 1) {
            $host = '[' . $matches['host'] . ']';

            if (isset($matches['port'])) {
                $port = (int) $matches['port'];
            }
        } else {
            // Excluding embedded colons keeps unbracketed IPv6 text out of this plain host-and-port parse.
            if (preg_match('/^(?<host>[^:]+)(?::(?<port>\d+))?$/', $host, $matches) === 1) {
                $host = $matches['host'];

                if (isset($matches['port'])) {
                    $port = (int) $matches['port'];
                }
            }
        }


        if ($port !== $this->dashboardRequestContext->bindPort) {
            return false;
        }

        // Accept loopback aliases so localhost URLs work when the server was bound to a numeric loopback address.
        if ($this->isBindHostLoopback()) {
            return in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        }

        // Bound to every interface, so the host name cannot be pinned down; a matching port is all we can meaningfully insist on.
        if ($this->isBindHostWildcard()) {
            return true;
        }

        $bindHost = strtolower($this->dashboardRequestContext->bindHost);

        // A colon-bearing bind value needs brackets to match the Host header form used for an address with embedded colons.
        if (str_contains($bindHost, ':') && !str_starts_with($bindHost, '[')) {
            $bindHost = '[' . $bindHost . ']';
        }

        return $host === $bindHost;
    }

    /**
     * Checks whether the bind host is one of the supported loopback spellings.
     * A match selects the local-alias policy in isHostAllowed().
     *
     * @return bool - Whether the configured bind host is 127.0.0.1, localhost, ::1, or [::1] (case-insensitive).
     */
    private function isBindHostLoopback(): bool
    {
        return in_array(strtolower($this->dashboardRequestContext->bindHost), ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    /**
     * Tells isHostAllowed() whether we bound to a wildcard address, the loosest case, where the
     * host name can't be pinned. A true here is why a 0.0.0.0 dashboard checks only the port.
     *
     * @return bool - Whether the bind host is 0.0.0.0, ::, or [::], enabling unrestricted host names.
     */
    private function isBindHostWildcard(): bool
    {
        return in_array(strtolower($this->dashboardRequestContext->bindHost), ['0.0.0.0', '::', '[::]'], true);
    }

    /**
     * Creates the 431 response used for request-line and header-size limit failures.
     *
     * @return DashboardHttpResponse - New 431 Request Header Fields Too Large response.
     */
    private function tooLargeResponse(): DashboardHttpResponse
    {
        return new DashboardHttpResponse(431, 'Request Header Fields Too Large', 'Request Header Fields Too Large', 'text/plain; charset=UTF-8');
    }
}
