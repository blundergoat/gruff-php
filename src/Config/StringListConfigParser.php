<?php

declare(strict_types=1);

namespace GruffPhp\Config;

/**
 * Normalises scalar and list configuration values into validated string lists.
 *
 * @phpstan-type ConfigScalar bool|float|int|object|string|null
 * @phpstan-type ConfigValue ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar|array<array-key, ConfigScalar>>>>
 */
final readonly class StringListConfigParser
{
    /**
     * @param ConfigValue $value           Raw config value to normalize.
     * @param string      $path            Config path used in validation messages.
     * @param bool        $hasPathPatterns Whether values are interpreted as path patterns.
     * @param bool        $allowsGlobs     Whether glob-like wildcard patterns are accepted.
     * @return list<string>
     * @throws ConfigException When the config value is not a valid string list.
     */
    public function parse(object|array|string|int|float|bool|null $value, string $path, bool $hasPathPatterns, bool $allowsGlobs): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be a list of strings.', $path));
        }

        $strings = [];

        foreach ($value as $index => $rawConfigValue) {
            $strings[] = $this->normalizedString($rawConfigValue, $path, $index, hasPathPatterns: $hasPathPatterns, allowsGlobs: $allowsGlobs);
        }

        return array_values(array_unique($strings));
    }

    /**
     * Normalize one configured string and validate optional path-pattern rules.
     *
     * @return string Trimmed string with directory separators normalized.
     */
    private function normalizedString(
        mixed $rawConfigValue,
        string $path,
        int|string $index,
        bool $hasPathPatterns,
        bool $allowsGlobs,
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
     * Reject path patterns that can escape the project or use disallowed globs.
     *
     * @return void
     */
    private function assertPathPattern(string $normalized, string $path, int|string $index, bool $allowsGlobs): void
    {
        if (str_starts_with($normalized, '/') || str_contains($normalized, '../') || $normalized === '..') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a relative project path pattern.', $path, $index));
        }

        if (!$allowsGlobs && str_contains($normalized, '*')) {
            throw new ConfigException(sprintf('Config key "%s.%s" does not support glob syntax.', $path, $index));
        }
    }
}
