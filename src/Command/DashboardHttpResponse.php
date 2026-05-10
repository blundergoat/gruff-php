<?php

declare(strict_types=1);

namespace GruffPhp\Command;

final readonly class DashboardHttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase,
        public string $body,
        public string $contentType,
    ) {
    }
}
