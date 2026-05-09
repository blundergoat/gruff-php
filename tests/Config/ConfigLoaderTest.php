<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
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

    private function writeTempConfig(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-config-');

        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }
}
