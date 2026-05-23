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
     * @param string $contents Config file contents.
     * @param string $suffix   File suffix.
     * @return string
     */
    protected function writeTempConfig(string $contents, string $suffix = '.yaml'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-config-');

        self::assertIsString($path);

        if ($suffix !== '') {
            self::assertTrue(unlink($path));
            $path .= $suffix;
        }

        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }
}
