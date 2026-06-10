<?php

/**
 * Covers config loader rule-option tests and inline fixture rules.
 */
declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Config\RuleConfigApplier;
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
use GruffPhp\Rule\Security\SqlConcatenationRule;
use GruffPhp\Rule\Security\VariableIncludeRule;
use GruffPhp\Rule\TestQuality\ExtendsProductionClassRule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers ConfigLoader rule enabled-state and option validation behavior.
 */
final class ConfigLoaderRuleOptionsTest extends ConfigLoaderTestCase
{
    /**
     * Verify honours default enabled from rule definition.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * Verify unknown rule ids in a rules block warn through the sink and are skipped while known blocks still apply.
     *
     * @return void
     */
    public function testWarnsAndIgnoresUnknownRuleIdBlocks(): void
    {
        $ruleRegistry = new RuleRegistry([new FixtureDefaultDisabledRule()]);
        $warnings     = [];
        $applier      = new RuleConfigApplier(static function (string $line) use (&$warnings): void {
            $warnings[] = $line;
        });

        $config = $applier->apply(
            AnalysisConfig::fromRegistry($ruleRegistry),
            $ruleRegistry,
            [
                'rules' => [
                    'dead-code.retired-rule'         => ['enabled' => true],
                    FixtureDefaultDisabledRule::ID   => ['enabled' => true],
                ],
            ],
        );

        self::assertSame(
            ['gruff-php: ignoring unknown rule id "dead-code.retired-rule" in config (retired or mistyped); remove the "rules.dead-code.retired-rule" block to silence this warning.'],
            $warnings,
        );
        self::assertTrue($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    /**
     * Verify loads the extends-production-class additional test base classes option.
     *
     * @return void
     */
    public function testLoadsAdditionalTestBaseClassesOption(): void
    {
        $path = $this->writeTempConfig(sprintf(
                                           '{"rules":{"%s":{"options":{"additionalTestBaseClasses":["IntegrationTestBase"]}}}}',
                                           ExtendsProductionClassRule::ID,
                                       ));

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(ExtendsProductionClassRule::ID);

        self::assertSame(['IntegrationTestBase'], $settings->stringListOption('additionalTestBaseClasses'));
    }

    /**
     * Verify loads the variable-include constant-path options.
     *
     * @return void
     */
    public function testLoadsVariableIncludeConstantPathOptions(): void
    {
        $path = $this->writeTempConfig(sprintf(
                                           '{"rules":{"%s":{"options":{"treatGlobalConstantsAsFixed":false,"dynamicPathConstants":["ABSPATH"]}}}}',
                                           VariableIncludeRule::ID,
                                       ));

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(VariableIncludeRule::ID);

        self::assertFalse($settings->option('treatGlobalConstantsAsFixed'));
        self::assertSame(['ABSPATH'], $settings->stringListOption('dynamicPathConstants'));
    }

    /**
     * Verify loads the sql-concatenation safe-interpolation-receivers option.
     *
     * @return void
     */
    public function testLoadsSqlConcatenationSafeInterpolationReceiversOption(): void
    {
        $path = $this->writeTempConfig(sprintf(
                                           '{"rules":{"%s":{"options":{"safeInterpolationReceivers":["wpdb","db"]}}}}',
                                           SqlConcatenationRule::ID,
                                       ));

        $config   = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
        $settings = $config->ruleSettings(SqlConcatenationRule::ID);

        self::assertSame(['wpdb', 'db'], $settings->stringListOption('safeInterpolationReceivers'));
    }

    /**
     * Verify rejects unknown rule option key.
     *
     * @return void
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
     * @return void
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
     * Provide invalid rule option type cases for parameterized tests.
     *
     * @return array<string, array{string, string}> - keyed by case label; each value pairs the config JSON template with the expected validation
     *                       message template
     */
    public static function invalidRuleOptionTypeProvider(): array
    {
        return [
            'float option'                   => [
                '{"rules":{"%s":{"options":{"ratio":"high"}}}}',
                'Option "rules.%s.options.ratio" must be numeric.',
            ],
            'boolean option'                 => [
                '{"rules":{"%s":{"options":{"flag":"yes"}}}}',
                'Option "rules.%s.options.flag" must be boolean.',
            ],
            'string option'                  => [
                '{"rules":{"%s":{"options":{"label":false}}}}',
                'Option "rules.%s.options.label" must be a string.',
            ],
            'list option'                    => [
                '{"rules":{"%s":{"options":{"patterns":"foo"}}}}',
                'Option "rules.%s.options.patterns" must be a list.',
            ],
            'empty default list option item' => [
                '{"rules":{"%s":{"options":{"patterns":[123]}}}}',
                'Option "rules.%s.options.patterns.0" must be a string.',
            ],
            'string list item'               => [
                '{"rules":{"%s":{"options":{"names":["alpha",2]}}}}',
                'Option "rules.%s.options.names.1" must be a string.',
            ],
            'integer list item'              => [
                '{"rules":{"%s":{"options":{"levels":[1,"two"]}}}}',
                'Option "rules.%s.options.levels.1" must be an integer.',
            ],
        ];
    }

    /**
     * Verify rejects invalid rule option type variants.
     *
     * @param string $configTemplate - Config JSON template.
     * @param string $messageTemplate - Expected exception message template.
     *
     * @return void
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
 * Fixture rule used by the loader's enabled-state tests; default-disabled with no analysis output.
 */
final readonly class FixtureDefaultDisabledRule implements RuleInterface
{
    /** Fixture rule identifier. */
    public const ID = 'fixture.default-disabled';

    /**
     * Return metadata for the fixture rule.
     *
     * @return RuleDefinition - the default-disabled metadata identifying this fixture rule to the registry and loader
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
     * @param AnalysisUnit $analysisUnit - Analysis unit.
     * @param RuleContext  $ruleContext - Rule context for the fixture.
     *
     * @return list<\GruffPhp\Finding\Finding> - always empty; this fixture exercises only the loader's enabled-state path and never reports
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        return [];
    }
}

/**
 * Fixture rule used by the loader's option-validation tests; exposes typed options for the test config payloads.
 */
final readonly class FixtureOptionsRule implements RuleInterface
{
    /** Fixture rule identifier. */
    public const ID = 'fixture.options';

    /**
     * Return metadata for the fixture rule.
     *
     * @return RuleDefinition - metadata whose defaultOptions declare one option of each type for the validation tests to exercise
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
                                 'ratio'    => 0.5,
                                 'flag'     => true,
                                 'label'    => 'default',
                                 'names'    => ['default'],
                                 'levels'   => [1],
                             ],
        );
    }

    /**
     * Return findings produced by the fixture rule.
     *
     * @param AnalysisUnit $analysisUnit - Analysis unit.
     * @param RuleContext  $ruleContext - Rule context for the fixture.
     *
     * @return list<\GruffPhp\Finding\Finding> - always empty; this fixture exercises only the option-validation path and never reports
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        return [];
    }
}
