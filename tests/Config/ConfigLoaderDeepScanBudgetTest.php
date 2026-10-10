<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers how a project configures the bounded deep-scan budget before any file is parsed.
 *
 * The loader decides whether the guard runs at all and which bounds it carries, so these cases check the family
 * defaults a user gets with no configuration, and the replacement or disabling of either bound from the
 * `deepScanBudget` block. Split from ConfigLoaderTest so neither class exceeds the family public-method threshold.
 */
final class ConfigLoaderDeepScanBudgetTest extends ConfigLoaderTestCase
{
    /**
     * Verify the family deep-scan limits are active by default.
     *
     * @return void
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
     * Verify config may replace or disable either structural-analysis bound.
     *
     * @return void
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
