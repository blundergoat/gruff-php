<?php

declare(strict_types=1);

namespace Fixtures\Size;

final readonly class ReadonlySessionPayloadFixture
{
    public function __construct(
        public string $sessionId,
        public string $payloadVersion,
        public string $idempotencyKey,
        public string $tenantId,
        public string $actorId,
        public string $conversationId,
        public string $practiceId,
        public string $source,
        public string $mode,
        public string $locale,
        public string $timezone,
        public string $traceId,
        public string $spanId,
        public string $createdAt,
        public string $expiresAt,
        public string $checksum,
    ) {
    }

    public function cacheKey(): string
    {
        return $this->sessionId . ':' . $this->idempotencyKey;
    }
}
