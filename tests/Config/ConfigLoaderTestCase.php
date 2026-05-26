<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Provides shared helpers for config loader tests.
 */
abstract class ConfigLoaderTestCase extends TestCase
{
    /**
     * Write a temporary configuration file for loader assertions.
     *
     * Auto-injects `schemaVersion: gruff-php.config.v0.1` when missing so
     * pre-M11 tests keep their compact inline configs without each test
     * needing to repeat the field. Tests that exercise the
     * missing-schemaVersion error path pass `$shouldInjectSchemaVersion = false`
     * so the contents reach disk verbatim; tests that exercise the
     * wrong-schemaVersion error path include a non-canonical value
     * explicitly and the auto-injection sees `schemaVersion` and skips.
     *
     * @param string $contents             Config file contents.
     * @param string $suffix               File suffix.
     * @param bool   $shouldInjectSchemaVersion  When false, write contents verbatim (used by schemaVersion-rejection tests).
     * @return string
     */
    protected function writeTempConfig(string $contents, string $suffix = '.yaml', bool $shouldInjectSchemaVersion = true): string
    {
        if ($shouldInjectSchemaVersion) {
            $contents = $this->ensureSchemaVersion($contents);
        }

        $path = tempnam(sys_get_temp_dir(), 'gruff-config-');

        self::assertIsString($path);

        if ($suffix !== '') {
            self::assertTrue(unlink($path));
            $path .= $suffix;
        }

        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    /**
     * Prepend the canonical schemaVersion when the test contents omit it.
     *
     * @param string $contents Raw config contents supplied by the test.
     * @return string Contents with schemaVersion present.
     */
    private function ensureSchemaVersion(string $contents): string
    {
        if (str_contains($contents, 'schemaVersion')) {
            return $contents;
        }

        $version = 'gruff-php.config.v0.1';
        $trimmed = ltrim($contents);

        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                return (string) json_encode(['schemaVersion' => $version] + $decoded, JSON_THROW_ON_ERROR);
            }
        }

        return sprintf("schemaVersion: %s\n%s", $version, $contents);
    }
}
