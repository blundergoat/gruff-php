<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Docs;

/**
 * Fixture covering `docs.missing-constant-phpdoc` line-comment reword.
 */
final class LineCommentedConstantFixture
{
    // Maximum byte length accepted by the streaming CSV parser before back-pressure kicks in.
    public const CSV_BYTE_CAP = 65536;

    // Stable telemetry key used by staff dashboards.
    public const TELEMETRY_KEY = 'practice_assistant.turn.completed';

    /**
     * Stable telemetry key used by staff dashboards.
     */
    public const DOCUMENTED_TELEMETRY_KEY = 'practice_assistant.turn.completed.documented';

    // Supported message roles stored in assistant chat history.
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public const PLAIN_NO_COMMENT = 'plain';

    // Cache namespace used for per-session prompt payloads.
    private const PRIVATE_CACHE_PREFIX = 'practice-assistant:';

    private const PRIVATE_NO_COMMENT = 'missing';

    // constant
    private const PRIVATE_USELESS_COMMENT = 'constant';

    // Max pages.
    private const MAX_PAGES = 3;

    // TODO
    private const PRIVATE_TODO_COMMENT = 'todo';

    // Detached comments do not document the next private constant.

    private const PRIVATE_DETACHED_COMMENT = 'detached';

    /*
     * Shared metadata keys written into every cache payload.
     */
    private const PAYLOAD_VERSION_KEY = 'version',
        IDEMPOTENCY_KEY = 'idempotencyKey';
}
