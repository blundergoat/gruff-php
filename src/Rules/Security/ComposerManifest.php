<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

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
     * Reports whether a display path refers to a Composer manifest.
     *
     * @param string $displayPath - File display path, as reported by the source file.
     *
     * @return bool - True when the path's basename is exactly `composer.json`.
     */
    public static function isManifest(string $displayPath): bool
    {
        $normalizedPath = str_replace('\\', '/', $displayPath);
        $slashPosition  = strrpos($normalizedPath, '/');
        $basename       = $slashPosition === false ? $normalizedPath : substr($normalizedPath, $slashPosition + 1);

        // Gate on the basename alone so a composer.json in any directory qualifies; the path prefix is irrelevant.
        return $basename === self::FILENAME;
    }

    /**
     * Decodes manifest JSON into an associative array, tolerating malformed input.
     *
     * @param string $source - Raw manifest contents.
     *
     * @return array<string, mixed>|null - Decoded top-level object, or null when the source is not a JSON object.
     */
    public static function decode(string $source): ?array
    {
        try {
            $decoded = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Malformed JSON is not the rules' problem to report, so signal "no manifest to scan" with null.
            return null;
        }

        if (!is_array($decoded)) {
            // Valid JSON decoding to a scalar or null is not a manifest object; callers skip it like a parse failure.
            return null;
        }

        /** @var array<string, mixed> $decoded A decoded JSON object always has string keys; the is_array guard cannot express that to PHPStan. */
        // Top-level object reached the caller; per-key shape is still each rule's responsibility to validate.
        return $decoded;
    }

    /**
     * Resolves the 1-based line number of the first occurrence of a token.
     *
     * Used to anchor a finding near the offending key without embedding any
     * value in the finding payload.
     *
     * @param string $source - Raw manifest contents.
     * @param string $needle - Token to locate (for example a quoted package name).
     *
     * @return int - 1-based line number, or 1 when the token is not found.
     */
    public static function lineOf(string $source, string $needle): int
    {
        if ($needle === '') {
            // An empty needle can never anchor a finding precisely, so fall back to the top of the file.
            return 1;
        }

        $position = strpos($source, $needle);
        if ($position === false) {
            // Token absent (e.g. the key was renamed since decode); anchor the finding at line 1 rather than guess.
            return 1;
        }

        // Count newlines before the match and add 1 to convert the 0-based offset into a 1-based line number.
        return substr_count($source, "\n", 0, $position) + 1;
    }

    /**
     * Resolves a package's 1-based line inside one dependency section, so the warning points to the manifest entry the user needs to edit.
     *
     * @param string $source      - Raw manifest contents; empty text cannot contain a dependency and falls back to line 1.
     * @param string $sectionName - Dependency section name such as `require`; an empty or absent section falls back to line 1.
     * @param string $packageName - Decoded package key to locate; an empty or absent key in that section falls back to line 1.
     *
     * @return int - exact 1-based package-key line, or 1 when the section or key cannot be located reliably
     */
    public static function lineOfDependencyInSection(string $source, string $sectionName, string $packageName): int
    {
        // Missing names cannot identify the dependency entry a user needs to edit, so keep the existing top-of-file fallback.
        if ($sectionName === '' || $packageName === '') {
            return 1;
        }

        $sectionPattern = sprintf('/"%s"\s*:\s*\{/', preg_quote($sectionName, '/'));

        // If the requested dependency section is absent, there is no reliable package location to show the user.
        if (preg_match($sectionPattern, $source, $sectionMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return 1;
        }

        [$sectionHeader, $sectionHeaderPosition] = $sectionMatch[0];
        $sectionBodyPosition                     = $sectionHeaderPosition + strlen($sectionHeader);
        $sectionEndPosition                      = strpos($source, '}', $sectionBodyPosition);

        // Composer dependency sections are flat objects; without their closing brace, the package search cannot stay inside the chosen section.
        if ($sectionEndPosition === false) {
            return 1;
        }

        $packagePattern = sprintf('/"%s"\s*:/', preg_quote($packageName, '/'));

        // A package not found at or after this section's opening brace cannot anchor a warning in the requested section.
        if (preg_match(
            pattern: $packagePattern,
            subject: $source,
            matches: $packageMatch,
            flags:   PREG_OFFSET_CAPTURE,
            offset:  $sectionBodyPosition,
        ) !== 1) {
            return 1;
        }

        $packagePosition = $packageMatch[0][1];

        // A same-named key after this section belongs elsewhere, so line 1 is safer than pointing the user at the wrong manifest entry.
        if ($packagePosition >= $sectionEndPosition) {
            return 1;
        }

        // Convert the package key's byte offset into the exact 1-based line shown in CLI and JSON findings.
        return substr_count($source, "\n", 0, $packagePosition) + 1;
    }
}
