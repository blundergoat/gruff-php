<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Rule\RuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Anchors the "no behaviour change" guarantee: `extends: gruff.recommended` with no
 * overrides resolves to the same config as running with no config file at all.
 */
final class PresetIdentityTest extends TestCase
{
    /**
     * Verify extends: gruff.recommended equals the registry-default (no-config) result.
     *
     * @return void
     */
    public function testExtendsRecommendedEqualsNoConfig(): void
    {
        $registry = RuleRegistry::defaults();
        $noConfig = AnalysisConfig::fromRegistry($registry);

        $dir = sys_get_temp_dir() . '/gruff-preset-identity-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir));

        try {
            file_put_contents($dir . '/.gruff-php.yaml', "schemaVersion: gruff-php.config.v0.1\nextends: gruff.recommended\n");
            $extended = (new ConfigLoader($dir, ConfigLoader::packageRoot()))->load(null, $registry);

            self::assertSame($this->snapshot($noConfig), $this->snapshot($extended));
        } finally {
            unlink($dir . '/.gruff-php.yaml');
            rmdir($dir);
        }
    }

    /**
     * Build a comparable snapshot of a config's rule settings and global knobs.
     *
     * @param AnalysisConfig $config Config to snapshot.
     * @return array<string, mixed> Deterministic snapshot for equality comparison.
     */
    private function snapshot(AnalysisConfig $config): array
    {
        $rules = [];
        foreach ($config->rules() as $ruleId => $settings) {
            $rules[$ruleId] = [
                'enabled' => $settings->enabled,
                'thresholds' => $settings->thresholds,
                'options' => $settings->options,
                'severity' => $settings->severityThreshold?->severity->value,
                'threshold' => $settings->severityThreshold?->threshold,
                'excludeFromScore' => $settings->excludeFromScore,
            ];
        }
        ksort($rules);

        return [
            'rules' => $rules,
            'minimumPhpVersion' => $config->minimumPhpVersion(),
            'acceptedAbbreviations' => $config->acceptedAbbreviations(),
            'allowedSecretPreviews' => $config->allowedSecretPreviews(),
        ];
    }
}
