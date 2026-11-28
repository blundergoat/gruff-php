<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers default and explicit deep-scan budget configuration.
 * Checks the default line and byte limits with the budget enabled.
 * Checks a disabled configured budget with replacement limits and a config override marker.
 */
final class ConfigLoaderDeepScanBudgetTest extends ConfigLoaderTestCase
{
    /**
     * Verify the family deep-scan limits are active by default.
     *
     * @return void - Asserts an enabled budget, 20,000 lines, 2,000,000 bytes, and the default override marker.
     */
    public function testLoadsDefaultDeepScanBudget(): void
    {
        $config = (new ConfigLoader(__DIR__ . '/../Fixtures/Source/empty'))->load(null, RuleRegistry::defaults());

        self::assertSame([
            'enabled' => true,
            'maxLines' => 20_000,
            'maxBytes' => 2_000_000,
            'override' => 'default',
        ], $config->deepScanBudget());
    }

    /**
     * Verify config can disable the deep-scan budget while replacing both limits.
     *
     * @return void - Asserts a disabled budget, limits of 123 lines and 456 bytes, and the config override marker.
     */
    public function testLoadsDeepScanBudgetConfig(): void
    {
        $path = $this->writeTempConfig(
            "deepScanBudget:\n    enabled: false\n    maxLines: 123\n    maxBytes: 456\n",
        );

        try {
            $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());

            self::assertSame([
                'enabled' => false,
                'maxLines' => 123,
                'maxBytes' => 456,
                'override' => 'config',
            ], $config->deepScanBudget());
        } finally {
            self::assertTrue(unlink($path));
        }
    }
}
