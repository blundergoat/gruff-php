<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Shares temporary configuration-file setup between config-loader test classes.
 * Checks temporary-file creation and writes through PHPUnit assertions.
 * Supports explicit filename suffixes and optional schema-version injection.
 */
abstract class ConfigLoaderTestCase extends TestCase
{
    /**
     * Writes a temporary config file, optionally adding schemaVersion for compact fixture inputs.
     * Disable injection to test a missing version; existing schemaVersion text leaves contents unchanged.
     *
     * @param string $contents - Config file contents passed through the optional schema helper.
     * @param string $suffix - Filename suffix appended to the generated temporary path; empty retains that path.
     * @param bool $shouldInjectSchemaVersion - Whether to run schema injection before writing the file.
     *
     * @return string - Absolute path of the written configuration file.
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
     * Prepend the canonical schemaVersion when the test contents contain no schemaVersion text.
     *
     * @param string $contents - Raw config contents supplied by the test.
     *
     * @return string - Config text containing the schemaVersion term; existing occurrences leave the text unchanged.
     */
    private function ensureSchemaVersion(string $contents): string
    {
        if (str_contains($contents, 'schemaVersion')) {
            // The test already contains schemaVersion text, often an intentionally wrong value, so leave it untouched.
            return $contents;
        }

        $version = 'gruff-php.config.v0.1';
        $trimmed = ltrim($contents);

        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {

                return (string)json_encode(['schemaVersion' => $version] + $decoded, JSON_THROW_ON_ERROR);
            }
        }


        return sprintf("schemaVersion: %s\n%s", $version, $contents);
    }
}
