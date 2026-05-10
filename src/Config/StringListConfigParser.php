<?php

declare(strict_types=1);

namespace GruffPhp\Config;

final readonly class StringListConfigParser
{
    /**
     * @param array<array-key, mixed>|bool|float|int|object|string|null $value
     * @return list<string>
     * @throws ConfigException
     */
    public function parse(object|array|string|int|float|bool|null $value, string $path, bool $pathPatterns, bool $allowGlobs): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be a list of strings.', $path));
        }

        $strings = [];

        foreach ($value as $index => $item) {
            $strings[] = $this->normalizedString($item, $path, $index, pathPatterns: $pathPatterns, allowGlobs: $allowGlobs);
        }

        return array_values(array_unique($strings));
    }

    private function normalizedString(
        mixed $item,
        string $path,
        int|string $index,
        bool $pathPatterns,
        bool $allowGlobs,
    ): string {
        if (!is_string($item) || trim($item) === '') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a non-empty string.', $path, $index));
        }

        $normalized = str_replace('\\', '/', trim($item));

        if ($pathPatterns) {
            $this->assertPathPattern($normalized, $path, $index, $allowGlobs);
        }

        return $normalized;
    }

    private function assertPathPattern(string $normalized, string $path, int|string $index, bool $allowGlobs): void
    {
        if (str_starts_with($normalized, '/') || str_contains($normalized, '../') || $normalized === '..') {
            throw new ConfigException(sprintf('Config key "%s.%s" must be a relative project path pattern.', $path, $index));
        }

        if (!$allowGlobs && str_contains($normalized, '*')) {
            throw new ConfigException(sprintf('Config key "%s.%s" does not support glob syntax.', $path, $index));
        }
    }
}
