<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

/**
 * The request layer of the `gruff-php dashboard` server: it takes one raw client socket,
 * parses the HTTP request line and headers off it, and decides what the browser gets back.
 * One instance handles every connection in turn (the server reuses it), so its `handleRequest`
 * runs each time the user or their browser touches the local dashboard the `dashboard` command opened.
 *
 * It stays deliberately defensive because the port is reachable locally: the request line
 * and header block are size-capped, a duplicate `Host` header is refused, and the `Host`
 * value is matched against the bound address so another site cannot DNS-rebind and drive a
 * scan. Allowed `GET`/`HEAD` requests route to the dashboard page, a `/scan` run, a `/health` probe, or a `404`.
 */
final readonly class DashboardRequestHandler
{
    /**
     * Largest request line (method, target, and version) the dashboard will read; a longer one is
     * refused with `431` instead of being buffered, so a giant URL cannot exhaust memory.
     */
    private const MAX_REQUEST_LINE_BYTES = 8192;

    /**
     * Most header lines one request may carry before the dashboard stops reading and answers `431`,
     * capping how much a single client can pile onto the header block.
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
     * @param DashboardRequestContext $dashboardRequestContext - Shared request context (bind host/port, project root, console input) every route reads.
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
     * The per-connection entry point the dashboard server calls once per client: read one request
     * off the socket, decide the reply, and write it back. Even a broken or dropped request still
     * gets a clean response, so the browser never hangs waiting on this connection.
     *
     * @param resource $client - Connected client stream to read the request from and write the response to.
     *
     * @return void - Nothing is returned; the reply is written straight to the client socket.
     */
    public function handleRequest($client): void
    {
        $request = $this->request($client);

        // The request could not be made sense of - the socket dropped mid-line, or the request line was malformed - so hand back a plain 400 and let the connection go.
        if ($request === null) {
            $this->responder->write($client, new DashboardHttpResponse(400, 'Bad Request', 'Bad Request', 'text/plain; charset=UTF-8'), false);

            return;
        }

        // Reading the request already settled on an error reply (an oversize request line or header block, or a duplicate `Host`); send that pre-built response verbatim rather than routing it.
        if ($request instanceof DashboardHttpResponse) {
            $this->responder->write($client, $request, false);

            return;
        }

        // A well-formed request: route it to a page, scan, or probe, and send headers only when the browser asked with `HEAD`.
        $this->responder->write(
            $client,
            $this->responseFor($request['method'], $request['target'], $request['headers']),
            $request['method'] === 'HEAD',
        );
    }

    /**
     * Reads and parses one request off the socket - the request line first, then the header block -
     * and returns one of three shapes. Those three outcomes are exactly the arms `handleRequest()`
     * dispatches on: a routable request, a ready-made error reply, or a drop.
     *
     * @param resource $client - Connected client stream positioned at the request line.
     *
     * @return array{method: string, target: string, headers: array<string, string>}|DashboardHttpResponse|null - the parsed request (method, target, headers) when well-formed; a DashboardHttpResponse to send verbatim when a size limit or duplicate Host was hit; null when the connection dropped or the request line was malformed
     */
    private function request($client): array|DashboardHttpResponse|null
    {
        $requestLine = fgets($client, self::MAX_REQUEST_LINE_BYTES + 2);

        // fgets handed back a non-string, meaning the client closed the socket before sending a request line; there is nothing to serve.
        if (!is_string($requestLine)) {
            return null;
        }

        // The request line ran past the byte cap (an absurdly long URL, or a client that never sent a newline); refuse it before reading any further.
        if (strlen($requestLine) > self::MAX_REQUEST_LINE_BYTES) {
            return $this->tooLargeResponse();
        }

        // Split the request line into method, target, and HTTP version; a line that does not fit this shape is not a request we can route, so drop it.
        if (!preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?\r?\n$/', $requestLine, $matches)) {
            return null;
        }

        $headers = $this->headers($client);

        // The header reader hit end-of-stream before the blank line that ends the block, so the request was cut off mid-flight and cannot be served.
        if ($headers === null) {
            return null;
        }

        // Reading the headers itself produced an error reply (oversize block, or a duplicate `Host`); pass that response straight back up to be sent.
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
     * Turns a validated request into its response: reject the wrong verb, gate on the `Host` header,
     * then match the URL path to the dashboard page, a scan, a health probe, or a not-found. This is
     * where a browser opening `http://localhost:<port>/` versus clicking through to `/scan` gets sent
     * down different branches.
     *
     * @param string                $method - HTTP verb; only GET and HEAD are served, anything else returns 405.
     * @param string                $target - Raw request target (path plus query); parsed for the route and params.
     * @param array<string, string> $headers - Lower-cased request headers; the Host entry gates access before any route.
     *
     * @return DashboardHttpResponse - the routed reply: a 200 page/health/scan body for an allowed GET/HEAD, or the matching error (405 wrong verb, 421 disallowed host, 404 unknown path)
     */
    private function responseFor(string $method, string $target, array $headers): DashboardHttpResponse
    {
        // The dashboard only ever reads, so any verb other than GET or HEAD (a form POST, a scripted PUT) is turned away with a flat 405.
        if ($method !== 'GET' && $method !== 'HEAD') {
            return new DashboardHttpResponse(405, 'Method Not Allowed', 'Method Not Allowed', 'text/plain; charset=UTF-8');
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // Every route except the open `/health` probe must prove it is aimed at this dashboard; a mismatched `Host` header (the tell-tale of a DNS-rebinding attempt from another site) is refused with 421.
        if ($path !== '/health' && !$this->isHostAllowed($headers['host'] ?? null)) {
            return new DashboardHttpResponse(421, 'Misdirected Request', 'Misdirected Request', 'text/plain; charset=UTF-8');
        }

        $query = $this->query($target);

        // Map the request path to a body: a liveness `ok`, an empty favicon, the full dashboard page, a fresh scan render, or a 404 for anything the dashboard does not serve.
        return match ($path) {
            '/health' => new DashboardHttpResponse(200, 'OK', 'ok', 'text/plain; charset=UTF-8'),
            '/favicon.ico' => new DashboardHttpResponse(204, 'No Content', '', 'text/plain; charset=UTF-8'),
            '/' => new DashboardHttpResponse(200, 'OK', $this->dashboardHtml($query), 'text/html; charset=UTF-8'),
            '/scan' => new DashboardHttpResponse(200, 'OK', $this->scanRunner->scanHtml($this->dashboardRequestContext, $query), 'text/html; charset=UTF-8'),
            default => new DashboardHttpResponse(404, 'Not Found', 'Not Found', 'text/plain; charset=UTF-8'),
        };
    }

    /**
     * Renders the main dashboard page a user lands on at `/`, folding the request's query params
     * (paths, fail-on, scope, and the other controls) into the view state before it is turned into HTML.
     *
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
     * Pulls the query string off the request target and keeps only the safe, scalar params, so a
     * link like `/?paths=src&failOn=error` becomes the map the page seeds its controls from.
     *
     * @param string $target - Raw request target; only its query string is read, and non-scalar values are dropped.
     *
     * @return array<string, string> - scalar query params keyed by name, each stringified; empty when the target has no query string
     */
    private function query(string $target): array
    {
        $rawQuery = parse_url($target, PHP_URL_QUERY);

        // A bare `/` with no `?...` after it - the usual first visit - carries no filters, so start the page from an empty set.
        if (!is_string($rawQuery) || $rawQuery === '') {
            return [];
        }

        parse_str($rawQuery, $query);
        $clean = [];

        // Walk every submitted param, keeping only the ones safe to treat as plain text for the view.
        foreach ($query as $key => $queryValue) {
            // Drop anything array-shaped or otherwise non-scalar (say `?path[]=a&path[]=b`), which the page cannot use as a single value.
            if (!is_string($key) || !is_scalar($queryValue)) {
                continue;
            }

            $clean[$key] = (string) $queryValue;
        }

        return $clean;
    }

    /**
     * Reads header lines off the socket up to the blank line that closes the block, enforcing the
     * line and byte caps as it goes and refusing a second `Host` header. Runs right after the
     * request line is parsed, and its three return shapes decide what `request()` does next.
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

        // Pull header lines one at a time until the client sends the blank line that closes the block, or the socket runs dry.
        while (($line = fgets($client, self::MAX_HEADER_BYTES + 2)) !== false) {
            $lineCount++;
            $byteCount += strlen($line);

            // The client has piled on more header lines or bytes than allowed; stop and answer 431 instead of buffering a possible flood.
            if ($lineCount > self::MAX_HEADER_LINES || $byteCount > self::MAX_HEADER_BYTES) {
                return $this->tooLargeResponse();
            }

            // A blank line is the end-of-headers marker, so hand back everything gathered up to here.
            if ($line === "\r\n" || $line === "\n") {
                return $headers;
            }

            $separator = strpos($line, ':');
            // A header line with no colon is malformed rather than fatal, so skip it and keep reading.
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            // The text before the colon was empty, so there is no name to key this header under; skip it.
            if ($name === '') {
                continue;
            }

            // A second `Host` header is an ambiguity and a request-smuggling risk, so refuse the whole request with 400.
            if ($name === 'host' && array_key_exists('host', $headers)) {
                return new DashboardHttpResponse(400, 'Bad Request', 'Bad Request', 'text/plain; charset=UTF-8');
            }

            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return null;
    }

    /**
     * The dashboard's front-door guard: decide whether a request's `Host` header truly names this
     * server. It normalises the header (IPv6 brackets, optional port), checks the port, then matches
     * the name against loopback, wildcard, or the exact bound host. A `no` here is what turns a
     * DNS-rebinding page in another tab into a 421 instead of a served scan.
     *
     * @param ?string $hostHeader - Raw Host header, or null when absent; missing or empty is rejected as not allowed.
     *
     * @return bool - true when the Host header's name and port match this dashboard's bind target; false denies the request as a likely cross-origin or DNS-rebinding attempt
     */
    private function isHostAllowed(?string $hostHeader): bool
    {
        // With no `Host` header at all (a bare client that never sent one), there is no way to prove the request was meant for this dashboard, so deny it.
        if ($hostHeader === null || $hostHeader === '') {
            return false;
        }

        $host = strtolower($hostHeader);
        $port = $this->dashboardRequestContext->bindPort;

        // A `Host` such as `[::1]:8080` is an IPv6 address in brackets; pull the address and any trailing port apart so each can be compared on its own.
        if (preg_match('/^\[(?<host>[^\]]+)\](?::(?<port>\d+))?$/', $host, $matches) === 1) {
            $host = '[' . $matches['host'] . ']';
            // The bracketed form also carried a `:port` after the `]`, so adopt that port for the comparison below.
            if (isset($matches['port'])) {
                $port = (int) $matches['port'];
            }
        } else {
            // Otherwise it is an ordinary `host` or `host:port`; split the name from any explicit port the same way.
            if (preg_match('/^(?<host>[^:]+)(?::(?<port>\d+))?$/', $host, $matches) === 1) {
                $host = $matches['host'];
                // This plain host spelled out its own port, so prefer that over the bound default.
                if (isset($matches['port'])) {
                    $port = (int) $matches['port'];
                }
            }
        }

        // The port named in the `Host` header is not the one we actually bound, so this request is aimed elsewhere; reject it to block port-confusion tricks.
        if ($port !== $this->dashboardRequestContext->bindPort) {
            return false;
        }

        // Bound to loopback, so only the canonical local names (`127.0.0.1`, `localhost`, `[::1]`) legitimately reach this server.
        if ($this->isBindHostLoopback()) {
            return in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        }

        // Bound to every interface, so the host name cannot be pinned down; a matching port is all we can meaningfully insist on.
        if ($this->isBindHostWildcard()) {
            return true;
        }

        $bindHost = strtolower($this->dashboardRequestContext->bindHost);

        // The configured bind host is a bare IPv6 address, so wrap it in brackets to match the shape a `Host` header carries.
        if (str_contains($bindHost, ':') && !str_starts_with($bindHost, '[')) {
            $bindHost = '[' . $bindHost . ']';
        }

        return $host === $bindHost;
    }

    /**
     * Tells `isHostAllowed()` whether we bound to loopback, the strictest case, where only local
     * names count. A `true` here is why a dashboard on `127.0.0.1` won't answer to any outside name.
     *
     * @return bool - true when the configured bind host is any loopback spelling (127.0.0.1, localhost, ::1, [::1]), which narrows the allowed Host names to those
     */
    private function isBindHostLoopback(): bool
    {
        return in_array(strtolower($this->dashboardRequestContext->bindHost), ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    /**
     * Tells `isHostAllowed()` whether we bound to a wildcard address, the loosest case, where the
     * host name can't be pinned. A `true` here is why a `0.0.0.0` dashboard checks only the port.
     *
     * @return bool - true when bound to a wildcard address (0.0.0.0, ::, [::]), meaning the request Host name cannot be pinned and only its port is checked
     */
    private function isBindHostWildcard(): bool
    {
        return in_array(strtolower($this->dashboardRequestContext->bindHost), ['0.0.0.0', '::', '[::]'], true);
    }

    /**
     * The single `431` reply the dashboard returns whenever a request overruns a size cap, so the
     * over-long request line and the over-budget header block both give the client the same answer.
     *
     * @return DashboardHttpResponse - the shared 431 Request Header Fields Too Large reply, reused for both an over-long request line and an over-budget header block
     */
    private function tooLargeResponse(): DashboardHttpResponse
    {
        return new DashboardHttpResponse(431, 'Request Header Fields Too Large', 'Request Header Fields Too Large', 'text/plain; charset=UTF-8');
    }
}
