<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Naming;

/**
 * Fixture covering the `naming.identifier-quality` generic-by-purpose helper skip:
 * single-parameter, wide-typed, non-`void`-returning helpers are exempt from the
 * generic-name complaint on their lone parameter.
 */
final class GenericByPurposeHelperFixture
{
    /**
     * Skip: one mixed parameter, non-void return - the generic `$value` is the right name.
     */
    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Fire: same shape but void return - the helper performs a side effect on the value.
     */
    private static function tag(mixed $value): void
    {
        // Pretend we tag the value somewhere.
        unset($value);
    }

    /**
     * Fire: two parameters - not a lone-parameter helper.
     */
    private static function transform(mixed $value, string $tag): string
    {
        return $tag . ':' . (is_string($value) ? $value : '');
    }

    /**
     * Skip: a wide union (>=3 types) on the lone parameter still qualifies.
     */
    private static function fingerprint(int|string|float|bool $value): string
    {
        return (string) $value;
    }

    /**
     * Fire: narrow union on the lone parameter (only two members) is not generic by purpose.
     */
    private static function describe(int|string $value): string
    {
        return (string) $value;
    }
}
