<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Rule\RuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the bundled presets against drift: each loads cleanly and references only
 * rule ids that exist in the registry.
 */
final class PresetIntegrityTest extends TestCase
{
    /**
     * Verify every bundled preset loads into a usable config.
     *
     * @param string $preset - Bundled preset name.
     *
     * @return void
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
     * Verify every rule id referenced by a preset exists in the registry.
     *
     * @param string $preset - Bundled preset name.
     *
     * @return void
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
     * Provide the three bundled preset names.
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
     * @param string $preset - Preset name.
     *
     * @return string - absolute filesystem path to the preset's YAML profile under the package root
     */
    private static function presetPath(string $preset): string
    {
        return ConfigLoader::packageRoot() . '/resources/profiles/' . $preset . '.yaml';
    }
}
