<?php

/**
 * Covers config loader tests and inline fixture rules.
 */
declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Config\SeverityThreshold;
use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\FileLengthRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers ConfigLoaderTest behavior.
 */
final class ConfigLoaderTest extends TestCase
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
        $directory = sys_get_temp_dir() . '/gruff-config-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        self::assertNotFalse(file_put_contents(
            $directory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 7\n            error: 70\n",
        ));

        try {
            $config   = (new ConfigLoader($directory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(7, $settings->numericThreshold('warning'));
            self::assertSame(70, $settings->numericThreshold('error'));
        } finally {
            @unlink($directory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
            @rmdir($directory);
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
        self::assertTrue(mkdir($projectDirectory));
        self::assertTrue(mkdir($fallbackDirectory));
        self::assertNotFalse(file_put_contents(
            $fallbackDirectory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 11\n            error: 110\n",
        ));

        try {
            $config   = (new ConfigLoader($projectDirectory, $fallbackDirectory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(11, $settings->numericThreshold('warning'));
            self::assertSame(110, $settings->numericThreshold('error'));
        } finally {
            @unlink($fallbackDirectory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
            @rmdir($fallbackDirectory);
            @rmdir($projectDirectory);
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
     * Verify rejects severity threshold without severity.
     *
     * @return void No return value.
     */
    public function testRejectsSeverityThresholdWithoutSeverity(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"threshold":70}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.size.file-length.severity" must be "warning" or "error".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify rejects severity without threshold.
     *
     * @return void No return value.
     */
    public function testRejectsSeverityWithoutThreshold(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"severity":"error"}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.size.file-length.severity" requires "threshold".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify rejects severity threshold for named tuning threshold rule.
     *
     * @return void No return value.
     */
    public function testRejectsSeverityThresholdForNamedTuningThresholdRule(): void
    {
        $path = $this->writeTempConfig('{"rules":{"docs.missing-public-phpdoc":{"threshold":8,"severity":"error"}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.docs.missing-public-phpdoc.threshold" is only supported for rules with warning/error thresholds.');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify rejects combining severity threshold and threshold map.
     *
     * @return void No return value.
     */
    public function testRejectsCombiningSeverityThresholdAndThresholdMap(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"threshold":70,"severity":"error","thresholds":{"warning":7}}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.size.file-length" cannot combine "threshold" and "thresholds".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
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
     * Verify rejects unsupported minimum PHP version.
     *
     * @return void No return value.
     */
    public function testRejectsUnsupportedMinimumPhpVersion(): void
    {
        $path = $this->writeTempConfig('{"minimumPhpVersion": 7.3}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "minimumPhpVersion" must be at least 7.4.');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
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
     * Verify rejects unknown root keys.
     *
     * @return void No return value.
     */
    public function testRejectsUnknownRootKeys(): void
    {
        $path = $this->writeTempConfig('{"plugins": []}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown config key "plugins".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify rejects unknown threshold keys.
     *
     * @return void No return value.
     */
    public function testRejectsUnknownThresholdKeys(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"thresholds":{"critical":1}}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown threshold "rules.size.file-length.thresholds.critical".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
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

    /**
     * Verify rejects invalid path ignore pattern.
     *
     * @return void No return value.
     */
    public function testRejectsInvalidPathIgnorePattern(): void
    {
        $path = $this->writeTempConfig('{"paths":{"ignore":["../outside"]}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "paths.ignore.0" must be a relative project path pattern.');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify rejects unknown selection pillar.
     *
     * @return void No return value.
     */
    public function testRejectsUnknownSelectionPillar(): void
    {
        $path = $this->writeTempConfig('{"selection":{"pillars":["quality"]}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown pillar "selection.pillars.quality".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify rejects invalid accepted abbreviation.
     *
     * @return void No return value.
     */
    public function testRejectsInvalidAcceptedAbbreviation(): void
    {
        $path = $this->writeTempConfig('{"allowlists":{"acceptedAbbreviations":["not-valid!"]}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config value "allowlists.acceptedAbbreviations" contains invalid identifier "not-valid!".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify honours default enabled from rule definition.
     *
     * @return void No return value.
     */
    public function testHonoursDefaultEnabledFromRuleDefinition(): void
    {
        $registry = new RuleRegistry([new FixtureDefaultDisabledRule()]);

        $config = (new ConfigLoader(__DIR__))->load(null, $registry);

        self::assertFalse($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    /**
     * Verify can enable default disabled rule via config.
     *
     * @return void No return value.
     */
    public function testCanEnableDefaultDisabledRuleViaConfig(): void
    {
        $registry = new RuleRegistry([new FixtureDefaultDisabledRule()]);
        $path     = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"enabled":true}}}',
            FixtureDefaultDisabledRule::ID,
        ));

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), $registry);

        self::assertTrue($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    /**
     * Verify loads rule options.
     *
     * @return void No return value.
     */
    public function testLoadsRuleOptions(): void
    {
        $registry = new RuleRegistry([new FixtureOptionsRule()]);
        $path     = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":{"patterns":["foo","bar"],"ratio":0.75,"flag":false,"label":"custom","names":["alpha"],"levels":[1,2]}}}}',
            FixtureOptionsRule::ID,
        ));

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), $registry);
        $settings = $config->ruleSettings(FixtureOptionsRule::ID);

        self::assertSame(['foo', 'bar'], $settings->stringListOption('patterns'));
        self::assertSame(0.75, $settings->option('ratio'));
        self::assertFalse($settings->option('flag'));
        self::assertSame('custom', $settings->option('label'));
        self::assertSame(['alpha'], $settings->option('names'));
        self::assertSame([1, 2], $settings->option('levels'));
    }

    /**
     * Verify rejects unknown rule option key.
     *
     * @return void No return value.
     */
    public function testRejectsUnknownRuleOptionKey(): void
    {
        $registry = new RuleRegistry([new FixtureOptionsRule()]);
        $path     = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":{"unknown":[]}}}}',
            FixtureOptionsRule::ID,
        ));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(sprintf('Unknown option "rules.%s.options.unknown".', FixtureOptionsRule::ID));

        (new ConfigLoader(dirname($path)))->load(basename($path), $registry);
    }

    /**
     * Verify rejects invalid rule option type.
     *
     * @return void No return value.
     */
    public function testRejectsInvalidRuleOptionType(): void
    {
        $path = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":{"minScopeReferences":"two"}}}}',
            IdentifierQualityRule::ID,
        ));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(sprintf('Option "rules.%s.options.minScopeReferences" must be an integer.', IdentifierQualityRule::ID));

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidRuleOptionTypeProvider(): array
    {
        return [
            'float option' => [
                '{"rules":{"%s":{"options":{"ratio":"high"}}}}',
                'Option "rules.%s.options.ratio" must be numeric.',
            ],
            'boolean option' => [
                '{"rules":{"%s":{"options":{"flag":"yes"}}}}',
                'Option "rules.%s.options.flag" must be boolean.',
            ],
            'string option' => [
                '{"rules":{"%s":{"options":{"label":false}}}}',
                'Option "rules.%s.options.label" must be a string.',
            ],
            'list option' => [
                '{"rules":{"%s":{"options":{"patterns":"foo"}}}}',
                'Option "rules.%s.options.patterns" must be a list.',
            ],
            'string list item' => [
                '{"rules":{"%s":{"options":{"names":["alpha",2]}}}}',
                'Option "rules.%s.options.names.1" must be a string.',
            ],
            'integer list item' => [
                '{"rules":{"%s":{"options":{"levels":[1,"two"]}}}}',
                'Option "rules.%s.options.levels.1" must be an integer.',
            ],
        ];
    }

    /**
     * Verify rejects invalid rule option type variants.
     *
     * @param string $configTemplate Config JSON template.
     * @param string $messageTemplate Expected exception message template.
     * @return void No return value.
     */
    #[DataProvider('invalidRuleOptionTypeProvider')]
    public function testRejectsInvalidRuleOptionTypeVariants(string $configTemplate, string $messageTemplate): void
    {
        $registry = new RuleRegistry([new FixtureOptionsRule()]);
        $path     = $this->writeTempConfig(sprintf($configTemplate, FixtureOptionsRule::ID));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(sprintf($messageTemplate, FixtureOptionsRule::ID));

        (new ConfigLoader(dirname($path)))->load(basename($path), $registry);
    }

    /**
     * Write a temporary configuration file for loader assertions.
     *
     * @param string $contents Config file contents.
     * @param string $suffix File suffix.
     * @return string Fixture value.
     */
    private function writeTempConfig(string $contents, string $suffix = '.yaml'): string
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

/**
 * Covers FixtureDefaultDisabledRule behavior.
 */
final readonly class FixtureDefaultDisabledRule implements RuleInterface
{
    /** Fixture rule identifier. */
    public const ID = 'fixture.default-disabled';

    /**
     * Return metadata for the fixture rule.
     *
     * @return RuleDefinition Fixture value.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Fixture default-disabled rule',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Low,
            defaultEnabled:  false,
        );
    }

    /**
     * Return findings produced by the fixture rule.
     *
     * @param AnalysisUnit $unit Analysis unit.
     * @param RuleContext $context Rule context for the fixture.
     * @return list<\GruffPhp\Finding\Finding> Fixture findings.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        return [];
    }
}

/**
 * Covers FixtureOptionsRule behavior.
 */
final readonly class FixtureOptionsRule implements RuleInterface
{
    /** Fixture rule identifier. */
    public const ID = 'fixture.options';

    /**
     * Return metadata for the fixture rule.
     *
     * @return RuleDefinition Fixture value.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Fixture options rule',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Low,
            defaultOptions:  [
                'patterns' => [],
                'ratio' => 0.5,
                'flag' => true,
                'label' => 'default',
                'names' => ['default'],
                'levels' => [1],
            ],
        );
    }

    /**
     * Return findings produced by the fixture rule.
     *
     * @param AnalysisUnit $unit Analysis unit.
     * @param RuleContext $context Rule context for the fixture.
     * @return list<\GruffPhp\Finding\Finding> Fixture findings.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        return [];
    }
}
