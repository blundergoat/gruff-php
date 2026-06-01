<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Support\PathHelper;

/**
 * Normalises scalar and list configuration values into validated string lists.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key,
 *               ConfigScalar>>>>
 */
final readonly class StringListConfigParser
{
    /**
     * @param ConfigValue $configValue     Raw config value to normalize.
     * @param string      $path            Config path used in validation messages.
     * @param bool        $hasPathPatterns Whether values are interpreted as path patterns.
     * @param bool        $allowsGlobs     Whether glob-like wildcard patterns are accepted.
     *
     * @return list<string> - deduplicated, reindexed strings in first-seen order; empty when the input list is empty
     * @throws ConfigException When the config value is not a valid string list.
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

        // Dedupe so a repeated pattern is not validated or matched twice; array_values reindexes the
        // gaps array_unique leaves behind so the result satisfies the list<string> contract callers rely on.
        return array_values(array_unique($strings));
    }

    /**
     * Normalize one configured string and validate optional path-pattern rules.
     *
     * @param mixed      $rawConfigValue  One list element; must be a non-empty string or the call throws.
     * @param string     $path            Parent config key, prefixed onto error messages for locatability.
     * @param int|string $index           Element's position in the list, appended to the key to pinpoint a bad entry.
     * @param bool       $hasPathPatterns When true, the value must also pass project-relative path-pattern checks.
     * @param bool       $allowsGlobs     When true, `*` wildcards are allowed; only read under $hasPathPatterns.
     *
     * @return string - canonical form: input trimmed and every backslash folded to `/` for path comparison
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

        // Hand back the canonical form (trimmed, backslashes folded to `/`) so every downstream path
        // comparison sees one separator convention regardless of how the value was written in config.
        return $normalized;
    }

    /**
     * Reject path patterns that can escape the project or use disallowed globs.
     *
     * @param string     $normalized  Already-normalized pattern to vet; must stay inside the project tree.
     * @param string     $path        Parent config key, prefixed onto the rejection message for locatability.
     * @param int|string $index       List position, appended to the key to pinpoint the offending entry.
     * @param bool       $allowsGlobs When false, any `*` in the pattern is rejected as unsupported glob syntax.
     *
     * @return void
     */
    private function assertPathPattern(string $normalized, string $path, int|string $index, bool $allowsGlobs): void
    {
        if (PathHelper::isAbsolute($normalized) || str_contains($normalized, '../') || $normalized === '..') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a relative project path pattern.', $path, $index));
        }

        if (!$allowsGlobs && str_contains($normalized, '*')) {
            throw new ConfigException(sprintf('Config key "%s.%s" does not support glob syntax.', $path, $index));
        }
    }
}
