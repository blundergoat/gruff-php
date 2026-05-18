<?php

/**
 * Covers config loader tests and inline fixture rules.
 */
declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Config\SeverityThreshold;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\FileLengthRule;
use GruffPhp\Rule\TestQuality\TestMethodTooLongRule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers ConfigLoaderTest behavior.
 */
final class ConfigLoaderTest extends ConfigLoaderTestCase
{
    /**
     * Verify loads default rule settings when no config exists.
     *
     * @return void No return value.
     */
    public function testLoadsDefaultRuleSettingsWhenNoConfigExists(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(__DIR__ . '/../Fixtures/Source/empty'))->load(null, $registry);
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertTrue($settings->enabled);
        self::assertSame(400, $settings->numericThreshold('warning'));
        self::assertSame(800, $settings->numericThreshold('error'));
    }

    /**
     * Verify loads explicit threshold overrides.
     *
     * @return void No return value.
     */
    public function testLoadsExplicitThresholdOverrides(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/file-length-warning.yaml',
            $registry,
        );
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertTrue($settings->enabled);
        self::assertSame(3, $settings->numericThreshold('warning'));
        self::assertSame(999, $settings->numericThreshold('error'));
    }

    /**
     * Verify loads default YAML config file.
     *
     * @return void No return value.
     */
    public function testLoadsDefaultYamlConfigFile(): void
    {
        $directory  = sys_get_temp_dir() . '/gruff-config-' . bin2hex(random_bytes(6));
        $configPath = $directory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE;
        self::assertTrue(mkdir($directory));
        self::assertNotFalse(file_put_contents(
            $configPath,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 7\n            error: 70\n",
        ));

        try {
            $config   = (new ConfigLoader($directory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(7, $settings->numericThreshold('warning'));
            self::assertSame(70, $settings->numericThreshold('error'));
        } finally {
            self::assertTrue(unlink($configPath));
            self::assertTrue(rmdir($directory));
        }
    }

    /**
     * Verify loads the legacy default YAML config file when the preferred file is absent.
     *
     * @return void No return value.
     */
    public function testLoadsLegacyDefaultYamlConfigFile(): void
    {
        $directory  = sys_get_temp_dir() . '/gruff-config-legacy-' . bin2hex(random_bytes(6));
        $configPath = $directory . '/' . ConfigLoader::LEGACY_DEFAULT_CONFIG_FILE;
        self::assertTrue(mkdir($directory));
        self::assertNotFalse(file_put_contents(
            $configPath,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 13\n            error: 130\n",
        ));

        try {
            $config   = (new ConfigLoader($directory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(13, $settings->numericThreshold('warning'));
            self::assertSame(130, $settings->numericThreshold('error'));
        } finally {
            self::assertTrue(unlink($configPath));
            self::assertTrue(rmdir($directory));
        }
    }

    /**
     * Verify falls back to package default YAML config file.
     *
     * @return void No return value.
     */
    public function testFallsBackToPackageDefaultYamlConfigFile(): void
    {
        $projectDirectory  = sys_get_temp_dir() . '/gruff-config-project-' . bin2hex(random_bytes(6));
        $fallbackDirectory = sys_get_temp_dir() . '/gruff-config-fallback-' . bin2hex(random_bytes(6));
        $fallbackPath      = $fallbackDirectory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE;
        self::assertTrue(mkdir($projectDirectory));
        self::assertTrue(mkdir($fallbackDirectory));
        self::assertNotFalse(file_put_contents(
            $fallbackPath,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 11\n            error: 110\n",
        ));

        try {
            $config   = (new ConfigLoader($projectDirectory, $fallbackDirectory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(11, $settings->numericThreshold('warning'));
            self::assertSame(110, $settings->numericThreshold('error'));
        } finally {
            self::assertTrue(unlink($fallbackPath));
            self::assertTrue(rmdir($fallbackDirectory));
            self::assertTrue(rmdir($projectDirectory));
        }
    }

    /**
     * Verify falls back to package legacy default YAML config file.
     *
     * @return void No return value.
     */
    public function testFallsBackToPackageLegacyDefaultYamlConfigFile(): void
    {
        $projectDirectory  = sys_get_temp_dir() . '/gruff-config-project-' . bin2hex(random_bytes(6));
        $fallbackDirectory = sys_get_temp_dir() . '/gruff-config-fallback-legacy-' . bin2hex(random_bytes(6));
        $fallbackPath      = $fallbackDirectory . '/' . ConfigLoader::LEGACY_DEFAULT_CONFIG_FILE;
        self::assertTrue(mkdir($projectDirectory));
        self::assertTrue(mkdir($fallbackDirectory));
        self::assertNotFalse(file_put_contents(
            $fallbackPath,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 17\n            error: 170\n",
        ));

        try {
            $config   = (new ConfigLoader($projectDirectory, $fallbackDirectory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(17, $settings->numericThreshold('warning'));
            self::assertSame(170, $settings->numericThreshold('error'));
        } finally {
            self::assertTrue(unlink($fallbackPath));
            self::assertTrue(rmdir($fallbackDirectory));
            self::assertTrue(rmdir($projectDirectory));
        }
    }

    /**
     * Verify loads explicit YAML config file.
     *
     * @return void No return value.
     */
    public function testLoadsExplicitYamlConfigFile(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 9\n            error: 90\n",
            '.yaml',
        );

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertSame(9, $settings->numericThreshold('warning'));
        self::assertSame(90, $settings->numericThreshold('error'));
    }

    /**
     * Verify loads compact path override list options.
     *
     * @return void No return value.
     */
    public function testLoadsCompactPathOverrideListOptions(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    test-quality.test-method-too-long:\n        options:\n            pathOverrides:\n                - 'tests/Console/**=40'\n",
            '.yaml',
        );

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(TestMethodTooLongRule::ID);

        self::assertSame(['tests/Console/**=40'], $settings->option('pathOverrides'));
    }

    /**
     * Verify loads severity threshold with error severity.
     *
     * @return void No return value.
     */
    public function testLoadsSeverityThresholdWithErrorSeverity(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    size.file-length:\n        threshold: 70\n        severity: error\n",
            '.yaml',
        );

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertSame(400, $settings->numericThreshold('warning'));
        self::assertSame(800, $settings->numericThreshold('error'));
        self::assertInstanceOf(SeverityThreshold::class, $settings->severityThreshold);
        self::assertSame(70, $settings->severityThreshold->threshold);
        self::assertSame(Severity::Error, $settings->severityThreshold->severity);
        self::assertNull($settings->highValueThresholdMatch(70));
        $thresholdMatch = $settings->highValueThresholdMatch(71);
        self::assertNotNull($thresholdMatch);
        self::assertSame(70, $thresholdMatch->threshold);
        self::assertSame(Severity::Error, $thresholdMatch->severity);
    }

    /**
     * Verify loads severity threshold with warning severity.
     *
     * @return void No return value.
     */
    public function testLoadsSeverityThresholdWithWarningSeverity(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    size.file-length:\n        threshold: 70\n        severity: warning\n",
            '.yaml',
        );

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertSame(400, $settings->numericThreshold('warning'));
        self::assertSame(800, $settings->numericThreshold('error'));
        self::assertInstanceOf(SeverityThreshold::class, $settings->severityThreshold);
        self::assertSame(70, $settings->severityThreshold->threshold);
        self::assertSame(Severity::Warning, $settings->severityThreshold->severity);
        self::assertNull($settings->highValueThresholdMatch(70));
        $thresholdMatch = $settings->highValueThresholdMatch(71);
        self::assertNotNull($thresholdMatch);
        self::assertSame(70, $thresholdMatch->threshold);
        self::assertSame(Severity::Warning, $thresholdMatch->severity);
    }

    /**
     * Verify loads severity threshold with warning severity for inverse threshold rule.
     *
     * @return void No return value.
     */
    public function testLoadsSeverityThresholdWithWarningSeverityForInverseThresholdRule(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    complexity.maintainability-index:\n        threshold: 45\n        severity: warning\n",
            '.yaml',
        );

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings('complexity.maintainability-index');

        self::assertSame(55, $settings->numericThreshold('warning'));
        self::assertSame(35, $settings->numericThreshold('error'));
        self::assertInstanceOf(SeverityThreshold::class, $settings->severityThreshold);
        self::assertSame(45, $settings->severityThreshold->threshold);
        self::assertSame(Severity::Warning, $settings->severityThreshold->severity);
        self::assertNull($settings->lowValueThresholdMatch(45));
        $thresholdMatch = $settings->lowValueThresholdMatch(44);
        self::assertNotNull($thresholdMatch);
        self::assertSame(45, $thresholdMatch->threshold);
        self::assertSame(Severity::Warning, $thresholdMatch->severity);
    }

    /**
     * Verify inline invalid config shapes are rejected with explicit messages.
     *
     * @param string $configJson      Inline JSON config.
     * @param string $expectedMessage Expected exception message.
     * @return void No return value.
     */
    #[DataProvider('invalidInlineConfigProvider')]
    public function testRejectsInlineInvalidConfig(string $configJson, string $expectedMessage): void
    {
        $path = $this->writeTempConfig($configJson);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidInlineConfigProvider(): array
    {
        return [
            'severity threshold without severity' => [
                '{"rules":{"size.file-length":{"threshold":70}}}',
                'Config key "rules.size.file-length.severity" must be "warning" or "error".',
            ],
            'severity without threshold' => [
                '{"rules":{"size.file-length":{"severity":"error"}}}',
                'Config key "rules.size.file-length.severity" requires "threshold".',
            ],
            'severity threshold on named tuning threshold rule' => [
                '{"rules":{"docs.missing-public-phpdoc":{"threshold":8,"severity":"error"}}}',
                'Config key "rules.docs.missing-public-phpdoc.threshold" is only supported for rules with warning/error thresholds.',
            ],
            'combined severity threshold and threshold map' => [
                '{"rules":{"size.file-length":{"threshold":70,"severity":"error","thresholds":{"warning":7}}}}',
                'Config key "rules.size.file-length" cannot combine "threshold" and "thresholds".',
            ],
            'unsupported minimum PHP version' => [
                '{"minimumPhpVersion": 7.3}',
                'Config key "minimumPhpVersion" must be at least 7.4.',
            ],
            'unknown root key' => [
                '{"plugins": []}',
                'Unknown config key "plugins".',
            ],
            'unknown threshold key' => [
                '{"rules":{"size.file-length":{"thresholds":{"critical":1}}}}',
                'Unknown threshold "rules.size.file-length.thresholds.critical".',
            ],
            'invalid path ignore pattern' => [
                '{"paths":{"ignore":["../outside"]}}',
                'Config key "paths.ignore.0" must be a relative project path pattern.',
            ],
            'unknown selection pillar' => [
                '{"selection":{"pillars":["quality"]}}',
                'Unknown pillar "selection.pillars.quality".',
            ],
            'invalid accepted abbreviation' => [
                '{"allowlists":{"acceptedAbbreviations":["not-valid!"]}}',
                'Config value "allowlists.acceptedAbbreviations" contains invalid identifier "not-valid!".',
            ],
        ];
    }

    /**
     * Verify can disable a rule.
     *
     * @return void No return value.
     */
    public function testCanDisableARule(): void
    {
        $registry = RuleRegistry::defaults();
        $config   = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/disabled-file-length.yaml',
            $registry,
        );

        self::assertFalse($config->ruleSettings(FileLengthRule::ID)->enabled);
    }

    /**
     * Verify loads minimum PHP version.
     *
     * @return void No return value.
     */
    public function testLoadsMinimumPhpVersion(): void
    {
        $path = $this->writeTempConfig('{"minimumPhpVersion": 7.4}');

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());

        self::assertSame(7.4, $config->minimumPhpVersion());
    }

    /**
     * Verify rejects unknown rule ids.
     *
     * @return void No return value.
     */
    public function testRejectsUnknownRuleIds(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown rule id "size.nope".');

        (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/unknown-rule.yaml',
            RuleRegistry::defaults(),
        );
    }

    /**
     * Verify loads path ignores allowlists and rule selection.
     *
     * @return void No return value.
     */
    public function testLoadsPathIgnoresAllowlistsAndRuleSelection(): void
    {
        $path = $this->writeTempConfig(json_encode([
            'paths' => ['ignore' => ['legacy/**', 'generated']],
            'allowlists' => [
                'acceptedAbbreviations' => ['q', 'id'],
                'secretPreviews' => ['abcd...wxyz (redacted, 32 chars)'],
            ],
            'selection' => [
                'pillars' => ['security'],
                'rules' => ['size.file-length'],
                'excludeRules' => ['security.weak-crypto'],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());

        self::assertSame(['legacy/**', 'generated'], $config->ignoredPathPatterns());
        self::assertSame(['q', 'id'], $config->acceptedAbbreviations());
        self::assertSame(['abcd...wxyz (redacted, 32 chars)'], $config->allowedSecretPreviews());
        self::assertTrue($config->ruleSelection()->allows(RuleRegistry::defaults()->get(FileLengthRule::ID)->definition()));

        $weakCrypto = RuleRegistry::defaults()->get('security.weak-crypto')->definition();
        self::assertSame(Pillar::Security, $weakCrypto->pillar);
        self::assertFalse($config->ruleSelection()->allows($weakCrypto));
    }

}
