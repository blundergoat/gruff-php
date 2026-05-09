<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Finding\Pillar;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\FileLengthRule;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadsDefaultRuleSettingsWhenNoConfigExists(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(__DIR__ . '/../Fixtures/M03'))->load(null, $registry);
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertTrue($settings->enabled);
        self::assertSame(400, $settings->numericThreshold('warning'));
        self::assertSame(800, $settings->numericThreshold('error'));
    }

    public function testLoadsExplicitThresholdOverrides(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/M03/Config/file-length-warning.json',
            $registry,
        );
        $settings = $config->ruleSettings(FileLengthRule::ID);

        self::assertTrue($settings->enabled);
        self::assertSame(3, $settings->numericThreshold('warning'));
        self::assertSame(999, $settings->numericThreshold('error'));
    }

    public function testCanDisableARule(): void
    {
        $registry = RuleRegistry::defaults();
        $config = (new ConfigLoader(__DIR__ . '/../..'))->load(
            'tests/Fixtures/M03/Config/disabled-file-length.json',
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
            'tests/Fixtures/M03/Config/unknown-rule.json',
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

    private function writeTempConfig(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-config-');

        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }
}
