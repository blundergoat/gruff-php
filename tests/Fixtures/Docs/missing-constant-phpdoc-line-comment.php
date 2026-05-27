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

    public const PLAIN_NO_COMMENT = 'plain';
}
