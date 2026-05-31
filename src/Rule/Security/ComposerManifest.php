<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

/**
 * Shared parsing helpers for the Composer dependency-posture security rules.
 *
 * The rules scan a project's `composer.json` as text (no Composer execution, no
 * registry calls). This helper centralises the "is this the manifest?" gate, the
 * defensive JSON decode, and line-number resolution so each rule stays small.
 */
final class ComposerManifest
{
    /**
     * Canonical manifest filename the dependency rules scan.
     */
    public const FILENAME = 'composer.json';

    /**
     * Decide whether a display path refers to a Composer manifest.
     *
     * @param string $displayPath File display path, as reported by the source file.
     * @return bool True when the path's basename is exactly `composer.json`.
     */
    public static function isManifest(string $displayPath): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);
        $slashPosition  = strrpos($normalizedPath, '/');
        $basename       = $slashPosition === false ? $normalizedPath : substr($normalizedPath, $slashPosition + 1);

        return $basename === self::FILENAME;
    }

    /**
     * Decode manifest JSON into an associative array, tolerating malformed input.
     *
     * @param string $source Raw manifest contents.
     * @return array<string, mixed>|null Decoded top-level object, or null when the source is not a JSON object.
     */
    public static function decode(string $source): ?array
    {
        try {
            $decoded = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded A decoded JSON object always has string keys; the is_array guard cannot express that to PHPStan. */
        return $decoded;
    }

    /**
     * Resolve the 1-based line number of the first occurrence of a token.
     *
     * Used to anchor a finding near the offending key without embedding any
     * value in the finding payload.
     *
     * @param string $source Raw manifest contents.
     * @param string $needle Token to locate (for example a quoted package name).
     * @return int 1-based line number, or 1 when the token is not found.
     */
    public static function lineOf(string $source, string $needle): int
    {
        if ($needle === '') {
            return 1;
        }

        $position = strpos($source, $needle);
        if ($position === false) {
            return 1;
        }

        return substr_count($source, "\n", 0, $position) + 1;
    }
}
