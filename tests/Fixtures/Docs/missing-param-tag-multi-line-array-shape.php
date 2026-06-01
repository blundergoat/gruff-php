<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Docs;

/**
 * Fixture covering the multi-line `@param array{...} $payload` shape that previously tripped
 * `docs.missing-param-tag` because the per-line regex never saw the closing `$payload`.
 */
final class MultiLineArrayShapeFixture
{
    /**
     * Publish a tool-use envelope to the event sink.
     *
     * @param string $topic - Sink topic to dispatch to.
     * @param array{
     *     id: string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     latencyMs: int,
     * } $payload Envelope describing the tool-use call.
     *
     * @return void
     */
    public function publishToolUse(string $topic, array $payload): void
    {
        unset($topic, $payload);
    }

    /**
     * Publish a turn envelope to the event sink. The @param block intentionally omits the closing
     * `$variable` so the rule must still flag the missing tag for the actual parameter.
     *
     * @param string $topic - Sink topic to dispatch to.
     * @param array{
     *     id: string,
     *     name: string,
     * }
     *
     * @return void
     */
    public function publishTurnWithMalformedDoc(string $topic, array $payload): void
    {
        unset($topic, $payload);
    }
}
