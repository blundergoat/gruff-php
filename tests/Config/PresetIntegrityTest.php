<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks bundled presets through the config loader and the default rule registry.
 * Each preset must produce an AnalysisConfig instance.
 * Rule keys in each preset's array-valued rules block must belong to that registry.
 */
final class PresetIntegrityTest extends TestCase
{
    /**
     * Verifies that each bundled preset loads as an AnalysisConfig.
     *
     * @param string $preset - Bundled preset name.
     *
     * @return void - Asserts that the loader returns an AnalysisConfig instance.
     */
    #[DataProvider('presetProvider')]
    public function testPresetLoads(string $preset): void
    {
        $path   = self::presetPath($preset);
        $loader = new ConfigLoader(dirname($path), ConfigLoader::packageRoot());

        $config = $loader->load(basename($path), RuleRegistry::defaults());

        self::assertInstanceOf(AnalysisConfig::class, $config);
    }

    /**
     * Checks rule keys from a preset's array-valued rules block against the default registry.
     *
     * @param string $preset - Bundled preset name.
     *
     * @return void - Asserts that the YAML parses as an array and every extracted rule key is registered.
     */
    #[DataProvider('presetProvider')]
    public function testPresetReferencesOnlyKnownRules(string $preset): void
    {
        $registry = RuleRegistry::defaults();
        $knownIds = array_map(static fn($rule): string => $rule->definition()->id, $registry->all());

        $parsed = Yaml::parseFile(self::presetPath($preset));
        self::assertIsArray($parsed, sprintf('Preset %s must parse to a YAML mapping.', $preset));
        $rules = isset($parsed['rules']) && is_array($parsed['rules']) ? array_keys($parsed['rules']) : [];

        foreach ($rules as $ruleId) {
            self::assertContains((string)$ruleId, $knownIds, sprintf('Preset %s references unknown rule id "%s".', $preset, (string)$ruleId));
        }
    }

    /**
     * Provides one PHPUnit data set per name in ConfigLoader::BUNDLED_PRESETS.
     *
     * @return array<string, array{string}> - PHPUnit data set keyed by preset name, each holding that preset's name as the sole argument
     */
    public static function presetProvider(): array
    {
        $cases = [];
        foreach (ConfigLoader::BUNDLED_PRESETS as $preset) {
            $cases[$preset] = [$preset];
        }

        return $cases;
    }

    /**
     * Resolve the on-disk path for a bundled preset.
     *
     * @param string $preset - Bundled preset name used as the YAML filename stem.
     *
     * @return string - absolute filesystem path to the preset's YAML profile under the package root
     */
    private static function presetPath(string $preset): string
    {
        return ConfigLoader::packageRoot() . '/resources/profiles/' . $preset . '.yaml';
    }
}
