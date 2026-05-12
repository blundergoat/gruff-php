<?php

declare(strict_types=1);

namespace GruffPhp\Command;

/**
 * Carries status, headers, and body for dashboard HTTP replies.
 */
final readonly class DashboardHttpResponse
{
    /**
     * Capture the HTTP response fields returned by the dashboard server.
     *
     * @param int $statusCode HTTP status code.
     * @param string $reasonPhrase HTTP reason phrase.
     * @param string $body Response body bytes.
     * @param string $contentType Response Content-Type header value.
     */
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase,
        public string $body,
        public string $contentType,
    ) {
    }
}
