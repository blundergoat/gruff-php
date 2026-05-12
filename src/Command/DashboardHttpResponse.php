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
     */
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase,
        public string $body,
        public string $contentType,
    ) {
    }
}
