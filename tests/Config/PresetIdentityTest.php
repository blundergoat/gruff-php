<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Compares the recommended preset with registry defaults through a selected configuration snapshot.
 * Covers per-rule settings, the minimum PHP version, and accepted abbreviations.
 * Other configuration fields and config-file discovery are outside this comparison.
 */
final class PresetIdentityTest extends TestCase
{
    /**
     * Verify the recommended preset matches registry defaults for the fields selected by snapshot().
     *
     * @return void - Asserts equality of the selected rule settings, PHP version, and accepted abbreviations.
     */
    public function testExtendsRecommendedEqualsNoConfig(): void
    {
        $registry = RuleRegistry::defaults();
        $noConfig = AnalysisConfig::fromRegistry($registry);

        $directory = sys_get_temp_dir() . '/gruff-preset-identity-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));

        try {
            file_put_contents($directory . '/.gruff-php.yaml', "schemaVersion: gruff-php.config.v0.1\nextends: gruff.recommended\n");
            $extended = (new ConfigLoader($directory, ConfigLoader::packageRoot()))->load(null, $registry);

            self::assertSame($this->snapshot($noConfig), $this->snapshot($extended));
        } finally {
            unlink($directory . '/.gruff-php.yaml');
            rmdir($directory);
        }
    }

    /**
     * Build the rule-settings, PHP-version, and abbreviation snapshot used by the preset identity assertion.
     *
     * @param AnalysisConfig $config - Configuration supplying the rule settings, PHP version, and accepted abbreviations.
     *
     * @return array<string, mixed> - Deterministic snapshot for equality comparison.
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
        ];
    }
}
