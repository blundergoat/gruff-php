<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Support\PathHelper;

/**
 * Turns a raw config value into a clean, validated list of strings - the workhorse behind every
 * list-shaped setting a user writes.
 *
 * Config values arrive loosely typed from YAML, so a setting like `ignore:` or `acceptedAbbreviations:`
 * needs checking and tidying before use. This rejects anything that is not a list of non-empty strings,
 * de-duplicates while preserving order, and (for path settings) normalises separators and refuses
 * patterns that would escape the project or use globs where they are not allowed - so a typo in config
 * becomes a clear error rather than a silently wrong scan.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 */
final readonly class StringListConfigParser
{
    /**
     * Validates and cleans one list setting into de-duplicated strings, throwing a clear error when the
     * user wrote something that is not a list of strings.
     *
     * @param ConfigValue $configValue - Raw config value to normalize.
     * @param string      $path - Config key path, used in validation messages so the user knows what to fix.
     * @param bool        $hasPathPatterns - When true, each value is checked as a project-relative path pattern.
     * @param bool        $allowsGlobs - When true, `*` wildcards are accepted in the path patterns.
     *
     * @return list<string> - Deduplicated, reindexed strings in first-seen order; empty when the input list was empty.
     * @throws ConfigException When the config value is not a valid string list.
     */
    public function parse(object|array|string|int|float|bool|null $configValue, string $path, bool $hasPathPatterns, bool $allowsGlobs): array
    {
        // The setting has to be a list; a scalar or a keyed map means the user wrote the wrong shape.
        if (!is_array($configValue) || !array_is_list($configValue)) {
            throw new ConfigException(sprintf('Config key "%s" must be a list of strings.', $path));
        }

        $strings = [];

        // Clean and validate each entry in turn.
        foreach ($configValue as $index => $rawConfigValue) {
            $strings[] = $this->normalizedString($rawConfigValue, $path, $index, hasPathPatterns: $hasPathPatterns, allowsGlobs: $allowsGlobs);
        }

        return array_values(array_unique($strings));
    }

    /**
     * Cleans one list entry to canonical form and, for path settings, checks it is a safe relative
     * pattern - rejecting a blank or non-string entry outright.
     *
     * @param mixed      $rawConfigValue - One list element; must be a non-empty string or the call throws.
     * @param string     $path - Parent config key, prefixed onto error messages for locatability.
     * @param int|string $index - Element's position in the list, appended to the key to pinpoint a bad entry.
     * @param bool       $hasPathPatterns - When true, the value must also pass project-relative path-pattern checks.
     * @param bool       $allowsGlobs - When true, `*` wildcards are allowed; only read under $hasPathPatterns.
     *
     * @return string - Canonical form: input trimmed and every backslash folded to `/` for path comparison.
     */
    private function normalizedString(
        mixed      $rawConfigValue,
        string     $path,
        int|string $index,
        bool       $hasPathPatterns,
        bool       $allowsGlobs,
    ): string {
        // A blank or non-string entry is a config mistake, so reject it and point at the exact index.
        if (!is_string($rawConfigValue) || trim($rawConfigValue) === '') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a non-empty string.', $path, $index));
        }

        $normalized = str_replace('\\', '/', trim($rawConfigValue));

        // Path settings get the extra safety check that the pattern stays inside the project.
        if ($hasPathPatterns) {
            $this->assertPathPattern($normalized, $path, $index, $allowsGlobs);
        }

        return $normalized;
    }

    /**
     * Rejects a path pattern that could escape the project tree, or uses a glob where globs are not
     * allowed, so an ignore rule can never reach outside the user's project.
     *
     * @param string     $normalized - Already-normalized pattern to vet; must stay inside the project tree.
     * @param string     $path - Parent config key, prefixed onto the rejection message for locatability.
     * @param int|string $index - List position, appended to the key to pinpoint the offending entry.
     * @param bool       $allowsGlobs - When false, any `*` in the pattern is rejected as unsupported glob syntax.
     *
     * @return void
     */
    private function assertPathPattern(string $normalized, string $path, int|string $index, bool $allowsGlobs): void
    {
        // An absolute path or a `..` climb would point outside the project, which an ignore pattern must never do.
        if (PathHelper::isAbsolute($normalized) || str_contains($normalized, '../') || $normalized === '..') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a relative project path pattern.', $path, $index));
        }

        // This setting does not support wildcards, so a `*` here is a mistake worth flagging.
        if (!$allowsGlobs && str_contains($normalized, '*')) {
            throw new ConfigException(sprintf('Config key "%s.%s" does not support glob syntax.', $path, $index));
        }
    }
}
