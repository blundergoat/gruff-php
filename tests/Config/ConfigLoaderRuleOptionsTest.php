<?php

/**
 * Covers config loader rule-option tests and inline fixture rules.
 */
declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers ConfigLoader rule enabled-state and option validation behavior.
 */
final class ConfigLoaderRuleOptionsTest extends ConfigLoaderTestCase
{
    /**
     * Verify honours default enabled from rule definition.
     *
     * @return void No return value.
     */
    public function testHonoursDefaultEnabledFromRuleDefinition(): void
    {
        $ruleRegistry = new RuleRegistry([new FixtureDefaultDisabledRule()]);

        $config = (new ConfigLoader(__DIR__))->load(null, $ruleRegistry);

        self::assertFalse($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    /**
     * Verify can enable default disabled rule via config.
     *
     * @return void No return value.
     */
    public function testCanEnableDefaultDisabledRuleViaConfig(): void
    {
        $ruleRegistry = new RuleRegistry([new FixtureDefaultDisabledRule()]);
        $path         = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"enabled":true}}}',
            FixtureDefaultDisabledRule::ID,
        ));

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), $ruleRegistry);

        self::assertTrue($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    /**
     * Verify loads rule options.
     *
     * @return void No return value.
     */
    public function testLoadsRuleOptions(): void
    {
        $ruleRegistry = new RuleRegistry([new FixtureOptionsRule()]);
        $path         = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":{"patterns":["foo","bar"],"ratio":0.75,"flag":false,"label":"custom","names":["alpha"],"levels":[1,2]}}}}',
            FixtureOptionsRule::ID,
        ));

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), $ruleRegistry);
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
        $ruleRegistry = new RuleRegistry([new FixtureOptionsRule()]);
        $path         = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":{"unknown":[]}}}}',
            FixtureOptionsRule::ID,
        ));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(sprintf('Unknown option "rules.%s.options.unknown".', FixtureOptionsRule::ID));

        (new ConfigLoader(dirname($path)))->load(basename($path), $ruleRegistry);
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
     * @param string $configTemplate  Config JSON template.
     * @param string $messageTemplate Expected exception message template.
     * @return void No return value.
     */
    #[DataProvider('invalidRuleOptionTypeProvider')]
    public function testRejectsInvalidRuleOptionTypeVariants(string $configTemplate, string $messageTemplate): void
    {
        $ruleRegistry = new RuleRegistry([new FixtureOptionsRule()]);
        $path         = $this->writeTempConfig(sprintf($configTemplate, FixtureOptionsRule::ID));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(sprintf($messageTemplate, FixtureOptionsRule::ID));

        (new ConfigLoader(dirname($path)))->load(basename($path), $ruleRegistry);
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
            id:                 self::ID,
            name:               'Fixture default-disabled rule',
            pillar:             Pillar::Naming,
            tier:               RuleTier::V01,
            defaultSeverity:    Severity::Advisory,
            confidence:         Confidence::Low,
            isEnabledByDefault: false,
        );
    }

    /**
     * Return findings produced by the fixture rule.
     *
     * @param AnalysisUnit $analysisUnit    Analysis unit.
     * @param RuleContext  $ruleContext Rule context for the fixture.
     * @return list<\GruffPhp\Finding\Finding> Fixture findings.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
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
     * @param AnalysisUnit $analysisUnit    Analysis unit.
     * @param RuleContext  $ruleContext Rule context for the fixture.
     * @return list<\GruffPhp\Finding\Finding> Fixture findings.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        return [];
    }
}
