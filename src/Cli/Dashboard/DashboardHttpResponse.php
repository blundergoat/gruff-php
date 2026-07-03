<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

/**
 * One HTTP reply the dashboard server is about to send back to the browser.
 *
 * Bundles the status, headers, and body for a single response so the responder can write it to the
 * socket in one place — the shape behind every page, scan result, or error the dashboard shows a user.
 */
final readonly class DashboardHttpResponse
{
    /**
     * Capture the HTTP response fields returned by the dashboard server.
     *
     * @param int    $statusCode   - HTTP status code.
     * @param string $reasonPhrase - HTTP reason phrase.
     * @param string $body         - Response body bytes.
     * @param string $contentType  - Response Content-Type header value.
     */
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase,
        public string $body,
        public string $contentType,
    ) {
    }
}
