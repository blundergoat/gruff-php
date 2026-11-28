<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

/**
 * Carries one dashboard HTTP response from the server to the socket responder.
 *
 * Stores the status code, reason phrase, body bytes, and content type.
 * The responder derives Content-Length from the body and adds the connection and cache headers.
 */
final readonly class DashboardHttpResponse
{
    /**
     * Capture the HTTP response fields returned by the dashboard server.
     *
     * @param int    $statusCode   - Status code written in the HTTP response line.
     * @param string $reasonPhrase - Reason phrase written after the status code.
     * @param string $body         - Payload whose byte length supplies Content-Length; omitted from HEAD responses.
     * @param string $contentType  - Value written in the Content-Type header.
     */
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase,
        public string $body,
        public string $contentType,
    ) {
    }
}
