<?php

/**
 * Covers config loader rule-option tests and inline fixture rules.
 */
declare(strict_types=1);

namespace GruffPhp\Tests\Config;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Engine\Config\RuleConfigApplier;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use GruffPhp\Rules\Security\SqlConcatenationRule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers rule enablement and typed option loading.
 * Uses fixture definitions alongside selected built-in rules.
 * Checks invalid option errors and warnings for unknown rule IDs.
 */
final class ConfigLoaderRuleOptionsTest extends ConfigLoaderTestCase
{
    /**
     * Verify that loading without an explicit config keeps the fixture disabled.
     *
     * @return void - Asserts the disabled state declared by the fixture's definition.
     */
    public function testHonoursDefaultEnabledFromRuleDefinition(): void
    {
        $ruleRegistry = new RuleRegistry([new FixtureDefaultDisabledRule()]);

        $config = (new ConfigLoader(__DIR__))->load(null, $ruleRegistry);

        self::assertFalse($config->ruleSettings(FixtureDefaultDisabledRule::ID)->enabled);
    }

    /**
     * Verify that config can enable a rule disabled by default.
     *
     * @return void - Asserts that the loaded fixture settings are enabled.
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
     * Verify that configured scalar and list options survive loading.
     *
     * @return void - Asserts the loaded option values and their scalar or list types.
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
     * Verify that an unknown rule ID warns while a known override applies.
     *
     * @return void - Asserts the exact warning and the known fixture's enabled state.
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
                    'dead-code.retired-rule' => ['enabled' => true],
                    FixtureDefaultDisabledRule::ID => ['enabled' => true],
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
     * Verify that SQL interpolation receiver names survive config loading.
     *
     * @return void - Asserts that the loaded receiver list matches the configured names.
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
     * Verify that acceptLineComments and additionalFunctions retain configured values.
     *
     * @return void - Asserts the boolean override and the configured function list.
     */
    public function testLoadsRequestedRuleOptions(): void
    {
        $path = $this->writeTempConfig(json_encode([
            'rules' => [
                MissingPropertyPhpdocRule::ID => ['options' => ['acceptLineComments' => true]],
                DangerousFunctionCallRule::ID => ['options' => ['additionalFunctions' => ['custom_runner']]],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());

        self::assertTrue(
            $config->ruleSettings(MissingPropertyPhpdocRule::ID)->option('acceptLineComments'),
        );
        self::assertSame(
            ['custom_runner'],
            $config->ruleSettings(DangerousFunctionCallRule::ID)->stringListOption('additionalFunctions'),
        );
    }

    /**
     * Provide invalid values for the requested rule options.
     *
     * @return array<string, array{string, string, string}> - Rule id, JSON options object, and exact validation message.
     */
    public static function requestedRuleOptionTypeProvider(): array
    {
        return [
            'line-comment toggle requires a boolean' => [
                MissingPropertyPhpdocRule::ID,
                '{"acceptLineComments":"yes"}',
                'Option "rules.docs.missing-property-phpdoc.options.acceptLineComments" must be boolean.',
            ],
            'additional functions requires a list' => [
                DangerousFunctionCallRule::ID,
                '{"additionalFunctions":"custom_runner"}',
                'Option "rules.security.dangerous-function-call.options.additionalFunctions" must be a list.',
            ],
            'additional functions requires string items' => [
                DangerousFunctionCallRule::ID,
                '{"additionalFunctions":[1]}',
                'Option "rules.security.dangerous-function-call.options.additionalFunctions.0" must be a string.',
            ],
        ];
    }

    /**
     * Verify rejection of invalid line-comment and additional-function option values.
     *
     * @param string $ruleId          - Rule whose option block is configured.
     * @param string $optionsJson     - JSON object containing the invalid option value.
     * @param string $expectedMessage - Expected validation text in the exception message.
     *
     * @return void - Expects ConfigException containing the supplied validation text.
     */
    #[DataProvider('requestedRuleOptionTypeProvider')]
    public function testRejectsInvalidRequestedRuleOptionTypes(
        string $ruleId,
        string $optionsJson,
        string $expectedMessage,
    ): void {
        $path = $this->writeTempConfig(sprintf(
            '{"rules":{"%s":{"options":%s}}}',
            $ruleId,
            $optionsJson,
        ));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new ConfigLoader(dirname($path)))->load(basename($path), RuleRegistry::defaults());
    }

    /**
     * Verify that config loading rejects an option absent from the rule's defaults.
     *
     * @return void - Expects ConfigException naming the unknown option path.
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
     * Verify rejection of a string override for the integer minScopeReferences option.
     *
     * @return void - Expects ConfigException with the integer type validation text.
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
     * @return array<string, array{string, string}> - Named cases pairing config JSON templates with expected message templates.
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
            'empty default list option item' => [
                '{"rules":{"%s":{"options":{"patterns":[123]}}}}',
                'Option "rules.%s.options.patterns.0" must be a string.',
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
     * Verify rejection of invalid scalar and list option values.
     *
     * @param string $configTemplate  - JSON template whose %s placeholder receives the fixture rule ID.
     * @param string $messageTemplate - Expected message template whose %s placeholder receives the fixture rule ID.
     *
     * @return void - Expects ConfigException with the formatted validation message text.
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
 * Supplies a rule disabled by default for loader enablement tests.
 * The tests use its metadata to compare defaults with explicit overrides.
 * Analysis returns no findings.
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
     * @param AnalysisUnit $analysisUnit - Unused; the fixture never inspects the analysis unit.
     * @param RuleContext  $ruleContext  - Unused; the fixture returns no findings regardless of context.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - always empty; this fixture exercises only the loader's enabled-state path and never reports
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        return [];
    }
}

/**
 * Supplies typed defaults for tests of option validation.
 * Includes scalar values plus empty lists and lists of strings or integers.
 * Analysis returns no findings.
 */
final readonly class FixtureOptionsRule implements RuleInterface
{
    /** Fixture rule identifier. */
    public const ID = 'fixture.options';

    /**
     * Return metadata for the fixture rule.
     *
     * @return RuleDefinition - Rule metadata with float, boolean, string, and list defaults for option validation.
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
     * @param AnalysisUnit $analysisUnit - Unused; the fixture never inspects the analysis unit.
     * @param RuleContext  $ruleContext  - Unused; the fixture returns no findings regardless of context.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - always empty; this fixture exercises only the option-validation path and never reports
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        return [];
    }
}
