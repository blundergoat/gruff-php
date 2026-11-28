<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Support\PathHelper;

/**
 * Parses list-valued config settings into trimmed, non-empty strings in first-seen order.
 * Duplicate normalised values are removed, and backslashes become forward slashes for every setting.
 *
 * Path settings additionally enforce relative-path restrictions and optional `*` rejection.
 * Invalid list shapes or entries raise a config error identifying the setting.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 */
final readonly class StringListConfigParser
{
    /**
     * Validates and normalizes one list setting into de-duplicated strings, rejecting input that is not a list of non-empty strings.
     *
     * @param ConfigValue $configValue - Raw config value to normalize.
     * @param string      $path - Config key path, used in validation messages so the user knows what to fix.
     * @param bool        $hasPathPatterns - When true, each value is checked as a project-relative path pattern.
     * @param bool        $allowsGlobs - When true, asterisk wildcards are accepted in the path patterns.
     *
     * @return list<string> - Deduplicated, reindexed strings in first-seen order; empty when the input list was empty.
     * @throws ConfigException - When the config value is not a valid string list.
     */
    public function parse(object|array|string|int|float|bool|null $configValue, string $path, bool $hasPathPatterns, bool $allowsGlobs): array
    {

        if (!is_array($configValue) || !array_is_list($configValue)) {
            throw new ConfigException(sprintf('Config key "%s" must be a list of strings.', $path));
        }

        $strings = [];


        foreach ($configValue as $index => $rawConfigValue) {
            $strings[] = $this->normalizedString($rawConfigValue, $path, $index, hasPathPatterns: $hasPathPatterns, allowsGlobs: $allowsGlobs);
        }

        return array_values(array_unique($strings));
    }

    /**
     * Trims one list entry, normalizes its separators, and applies the configured relative-path restrictions.
     * Blank and non-string entries are rejected.
     *
     * @param mixed      $rawConfigValue - One list element; must be a non-empty string or the call throws.
     * @param string     $path - Parent config key, prefixed onto error messages for locatability.
     * @param int|string $index - Element's position in the list, appended to the key to pinpoint a bad entry.
     * @param bool       $hasPathPatterns - When true, the value must also pass project-relative path-pattern checks.
     * @param bool       $allowsGlobs - When true, asterisk wildcards are allowed; only read under $hasPathPatterns.
     *
     * @return string - Trimmed input with every backslash converted to a forward slash.
     */
    private function normalizedString(
        mixed      $rawConfigValue,
        string     $path,
        int|string $index,
        bool       $hasPathPatterns,
        bool       $allowsGlobs,
    ): string {

        if (!is_string($rawConfigValue) || trim($rawConfigValue) === '') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a non-empty string.', $path, $index));
        }

        $normalized = str_replace('\\', '/', trim($rawConfigValue));


        if ($hasPathPatterns) {
            $this->assertPathPattern($normalized, $path, $index, $allowsGlobs);
        }

        return $normalized;
    }

    /**
     * Enforces relative path-pattern restrictions and the setting's policy for asterisk wildcards.
     *
     * @param string     $normalized - Trimmed pattern with forward-slash separators.
     * @param string     $path - Parent config key, prefixed onto the rejection message for locatability.
     * @param int|string $index - List position, appended to the key to pinpoint the offending entry.
     * @param bool       $allowsGlobs - When false, any asterisk in the pattern is rejected as unsupported glob syntax.
     *
     * @return void - Returns when the relative-path restrictions and asterisk policy are satisfied.
     */
    private function assertPathPattern(string $normalized, string $path, int|string $index, bool $allowsGlobs): void
    {
        // Absolute paths, a standalone '..', and any '../' sequence are rejected to keep patterns project-relative.
        if (PathHelper::isAbsolute($normalized) || str_contains($normalized, '../') || $normalized === '..') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a relative project path pattern.', $path, $index));
        }


        if (!$allowsGlobs && str_contains($normalized, '*')) {
            throw new ConfigException(sprintf('Config key "%s.%s" does not support glob syntax.', $path, $index));
        }
    }
}
