<?php

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
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadsDefaultRuleSettingsWhenNoConfigExists(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(__DIR__ . '/../Fixtures/Source/empty'))->load(null, $registry);
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertTrue($settings->enabled);
        self::assertSame(400, $settings->numericThreshold('warning'));
        self::assertSame(800, $settings->numericThreshold('error'));
    }

    public function testLoadsExplicitThresholdOverrides(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/file-length-warning.yaml',
            $registry,
        );
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertTrue($settings->enabled);
        self::assertSame(3, $settings->numericThreshold('warning'));
        self::assertSame(999, $settings->numericThreshold('error'));
    }

    public function testLoadsDefaultYamlConfigFile(): void
    {
        $directory = sys_get_temp_dir() . '/gruff-config-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        self::assertNotFalse(file_put_contents(
            $directory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 7\n            error: 70\n",
        ));

        try {
            $config = (new ConfigLoader($directory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(7, $settings->numericThreshold('warning'));
            self::assertSame(70, $settings->numericThreshold('error'));
        } finally {
            @unlink($directory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
            @rmdir($directory);
        }
    }

    public function testFallsBackToPackageDefaultYamlConfigFile(): void
    {
        $projectDirectory = sys_get_temp_dir() . '/gruff-config-project-' . bin2hex(random_bytes(6));
        $fallbackDirectory = sys_get_temp_dir() . '/gruff-config-fallback-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($projectDirectory));
        self::assertTrue(mkdir($fallbackDirectory));
        self::assertNotFalse(file_put_contents(
            $fallbackDirectory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE,
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 11\n            error: 110\n",
        ));

        try {
            $config = (new ConfigLoader($projectDirectory, $fallbackDirectory))->load(null, RuleRegistry::defaults());
            $settings = $config->ruleSettings(FileLengthRule::ID);

            self::assertSame(11, $settings->numericThreshold('warning'));
            self::assertSame(110, $settings->numericThreshold('error'));
        } finally {
            @unlink($fallbackDirectory . '/' . ConfigLoader::DEFAULT_CONFIG_FILE);
            @rmdir($fallbackDirectory);
            @rmdir($projectDirectory);
        }
    }

    public function testLoadsExplicitYamlConfigFile(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    size.file-length:\n        thresholds:\n            warning: 9\n            error: 90\n",
            '.yaml',
        );

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertSame(9, $settings->numericThreshold('warning'));
        self::assertSame(90, $settings->numericThreshold('error'));
    }

    public function testLoadsSeverityThresholdWithErrorSeverity(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    size.file-length:\n        threshold: 70\n        severity: error\n",
            '.yaml',
        );

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
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

    public function testLoadsSeverityThresholdWithWarningSeverity(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    size.file-length:\n        threshold: 70\n        severity: warning\n",
            '.yaml',
        );

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
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

    public function testLoadsSeverityThresholdWithWarningSeverityForInverseThresholdRule(): void
    {
        $path = $this->writeTempConfig(
            "rules:\n    complexity.maintainability-index:\n        threshold: 45\n        severity: warning\n",
            '.yaml',
        );

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
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

    public function testRejectsSeverityThresholdWithoutSeverity(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"threshold":70}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.size.file-length.severity" must be "warning" or "error".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testRejectsSeverityWithoutThreshold(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"severity":"error"}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.size.file-length.severity" requires "threshold".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testRejectsSeverityThresholdForNamedTuningThresholdRule(): void
    {
        $path = $this->writeTempConfig('{"rules":{"docs.missing-public-phpdoc":{"threshold":8,"severity":"error"}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.docs.missing-public-phpdoc.threshold" is only supported for rules with warning/error thresholds.');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testRejectsCombiningSeverityThresholdAndThresholdMap(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"threshold":70,"severity":"error","thresholds":{"warning":7}}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "rules.size.file-length" cannot combine "threshold" and "thresholds".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testCanDisableARule(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/disabled-file-length.yaml',
            $registry,
        );

        self::assertFalse($config->ruleSettings(FileLengthRule::ID)->enabled);
    }

    public function testLoadsMinimumPhpVersion(): void
    {
        $path = $this->writeTempConfig('{"minimumPhpVersion": 7.4}');

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());

        self::assertSame(7.4, $config->minimumPhpVersion());
    }

    public function testRejectsUnsupportedMinimumPhpVersion(): void
    {
        $path = $this->writeTempConfig('{"minimumPhpVersion": 7.3}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "minimumPhpVersion" must be at least 7.4.');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testRejectsUnknownRuleIds(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown rule id "size.nope".');

        (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/Config/unknown-rule.yaml',
            RuleRegistry::defaults(),
        );
    }

    public function testRejectsUnknownRootKeys(): void
    {
        $path = $this->writeTempConfig('{"plugins": []}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown config key "plugins".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testRejectsUnknownThresholdKeys(): void
    {
        $path = $this->writeTempConfig('{"rules":{"size.file-length":{"thresholds":{"critical":1}}}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown threshold "rules.size.file-length.thresholds.critical".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

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

    public function testRejectsInvalidPathIgnorePattern(): void
    {
        $path = $this->writeTempConfig('{"paths":{"ignore":["../outside"]}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "paths.ignore.0" must be a relative project path pattern.');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testRejectsUnknownSelectionPillar(): void
    {
        $path = $this->writeTempConfig('{"selection":{"pillars":["quality"]}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown pillar "selection.pillars.quality".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testRejectsInvalidAcceptedAbbreviation(): void
    {
        $path = $this->writeTempConfig('{"allowlists":{"acceptedAbbreviations":["not-valid!"]}}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config value "allowlists.acceptedAbbreviations" contains invalid identifier "not-valid!".');

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    public function testHonoursDefaultEnabledFromRuleDefinition(): void
    {
        $registry = new RuleRegistry([new FixtureDefaultDisabledRule()]);

        $config = (new ConfigLoader(__DIR__))->load(null, $registry);

        self::assertFalse($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    public function testCanEnableDefaultDisabledRuleViaConfig(): void
    {
        $registry = new RuleRegistry([new FixtureDefaultDisabledRule()]);
        $path = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"enabled":true}}}',
            FixtureDefaultDisabledRule::ID,
        ));

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), $registry);

        self::assertTrue($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    public function testLoadsRuleOptions(): void
    {
        $registry = new RuleRegistry([new FixtureOptionsRule()]);
        $path = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":{"patterns":["foo","bar"]}}}}',
            FixtureOptionsRule::ID,
        ));

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), $registry);
        $settings = $config->ruleSettings(FixtureOptionsRule::ID);

        self::assertSame(['foo', 'bar'], $settings->stringListOption('patterns'));
    }

    public function testRejectsUnknownRuleOptionKey(): void
    {
        $registry = new RuleRegistry([new FixtureOptionsRule()]);
        $path = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":{"unknown":[]}}}}',
            FixtureOptionsRule::ID,
        ));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(sprintf('Unknown option "rules.%s.options.unknown".', FixtureOptionsRule::ID));

        (new ConfigLoader(dirname($path)))->load(basename($path), $registry);
    }

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

final readonly class FixtureDefaultDisabledRule implements RuleInterface
{
    public const ID = 'fixture.default-disabled';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Fixture default-disabled rule',
            pillar: Pillar::Naming,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
            defaultEnabled: false,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        return [];
    }
}

final readonly class FixtureOptionsRule implements RuleInterface
{
    public const ID = 'fixture.options';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Fixture options rule',
            pillar: Pillar::Naming,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
            defaultOptions: ['patterns' => []],
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        return [];
    }
}
