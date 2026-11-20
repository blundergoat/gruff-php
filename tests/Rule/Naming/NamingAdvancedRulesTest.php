<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers identifier-quality configuration, accepted abbreviations, and generic value parameters.
 * Pins the mutation fixture's finding metadata and messages.
 * Checks callback-body and local-reference thresholds through reported identifier names.
 */
final class NamingAdvancedRulesTest extends NamingRuleTestCase
{
    /**
     * Verify identifier quality can be tuned with config and accepted abbreviations.
     *
     * @return void - Asserts ignored and accepted names are absent while foo and temp remain reported.
     */
    public function testIdentifierQualityCanBeTunedWithConfigAndAcceptedAbbreviations(): void
    {
        $unit     = $this->parseFixture('identifier-quality.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(IdentifierQualityRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)
            ->withAcceptedAbbreviations(['api'])
            ->withRuleSettings(IdentifierQualityRule::ID, new RuleSettings(
                enabled:    true,
                thresholds: $settings->thresholds,
                options:    array_merge($settings->options, [
                    'placeholderNames' => ['foo', 'bar', 'baz', 'tmp', 'temp'],
                    'ignoredNames' => ['thing', 'data', 'helperthing'],
                ]),
            ));

        $findings           = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $identifierFindings = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === IdentifierQualityRule::ID,
        ));
        $names = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $identifierFindings);

        self::assertNotContains('obj', $names);
        self::assertNotContains('Thing', $names);
        self::assertNotContains('Data', $names);
        self::assertNotContains('HelperThing', $names);
        self::assertNotContains('api', $names);
        self::assertContains('foo', $names);
        self::assertContains('temp', $names);
    }

    /**
     * Verify plain value parameters are not hidden behind the generic-name ignore list.
     *
     * @return void - Asserts value and inputValue parameters are reported with the generic variant.
     */
    public function testIdentifierQualityFlagsGenericValueParameters(): void
    {
        $findings = $this->analyseSourceRule(<<<'PHP'
<?php
declare(strict_types=1);
namespace GruffPhp\Tests\Fixtures\Naming;
final class Formatter
{
    private function formatNumber(int|float $value): string
    {
        return (string) $value;
    }

    private function formatInputNumber(int|float $inputValue): string
    {
        return (string) $inputValue;
    }
}
PHP, IdentifierQualityRule::ID);

        $reported = [];
        foreach ($findings as $index => $finding) {
            $kind    = $finding->metadata['identifierKind'] ?? null;
            $name    = $finding->metadata['identifierName'] ?? null;
            $variant = $finding->metadata['variant'] ?? null;

            self::assertIsString($kind, "finding #{$index} is missing string metadata.identifierKind");
            self::assertIsString($name, "finding #{$index} is missing string metadata.identifierName");

            $reported[sprintf('%s:%s', $kind, $name)] = $variant;
        }

        self::assertSame('generic', $reported['parameter:value'] ?? null);
        self::assertSame('generic', $reported['parameter:inputValue'] ?? null);
    }

    /**
     * Verify identifier quality mutation fixture reports stable kinds and messages.
     *
     * @return void - Asserts the ordered finding tuples, including symbols, metadata, and messages.
     */
    public function testIdentifierQualityMutationFixtureFindingsAreStable(): void
    {
        $findings = $this->analyseRule('identifier-quality-mutation-cases.php', IdentifierQualityRule::ID);
        $tuples   = array_map(
            static fn ($finding): array => [
                $finding->symbol,
                $finding->metadata['identifierKind'] ?? null,
                $finding->metadata['identifierName'] ?? null,
                $finding->metadata['variant'] ?? null,
                $finding->metadata['matchedToken'] ?? null,
                $finding->message,
            ],
            $findings,
        );

        self::assertSame([
            ['Temp', 'class', 'Temp', 'placeholder', 'temp', 'Class name "Temp" is placeholder and does not communicate clear intent.'],
            ['$stuff', 'property', 'stuff', 'generic', 'stuff', 'Property name "stuff" is generic and does not communicate clear intent.'],
            ['$Temp', 'property', 'Temp', 'placeholder', 'temp', 'Property name "Temp" is placeholder and does not communicate clear intent.'],
            ['Temp::temp()', 'method', 'temp', 'placeholder', 'temp', 'Method name "temp" is placeholder and does not communicate clear intent.'],
            ['Temp::temp()', 'parameter', 'foo', 'placeholder', 'foo', 'Parameter name "foo" is placeholder and does not communicate clear intent.'],
            ['Temp::temp()', 'parameter', 'obj', 'placeholder', 'obj', 'Parameter name "obj" is placeholder and does not communicate clear intent.'],
            ['Temp::temp()', 'variable', 'item', 'generic', 'item', 'Variable name "item" is generic and does not communicate clear intent.'],
            ['Temp::temp()', 'variable', 'item2', 'numbered', '2', 'Variable name "item2" is numbered and does not communicate clear intent.'],
            ['Temp::loopAndCatchVariables()', 'variable', 'value', 'generic', 'value', 'Variable name "value" is generic and does not communicate clear intent.'],
            ['Temp::countedVariables()', 'variable', 'item', 'generic', 'item', 'Variable name "item" is generic and does not communicate clear intent.'],
            ['Temp::countedVariables()', 'variable', 'thing', 'generic', 'thing', 'Variable name "thing" is generic and does not communicate clear intent.'],
            ['Data', 'interface', 'Data', 'generic', 'data', 'Interface name "Data" is generic and does not communicate clear intent.'],
            ['HelperThing', 'trait', 'HelperThing', 'generic', 'helper thing', 'Trait name "HelperThing" is generic and does not communicate clear intent.'],
            ['Stuff', 'enum', 'Stuff', 'generic', 'stuff', 'Enum name "Stuff" is generic and does not communicate clear intent.'],
            ['bar()', 'function', 'bar', 'placeholder', 'bar', 'Function name "bar" is placeholder and does not communicate clear intent.'],
            ['bar()', 'parameter', 'obj', 'placeholder', 'obj', 'Parameter name "obj" is placeholder and does not communicate clear intent.'],
            ['bar()', 'variable', 'tmp', 'placeholder', 'tmp', 'Variable name "tmp" is placeholder and does not communicate clear intent.'],
        ], $tuples);
    }

    /**
     * Verify generic names on sole parameters of short inline iteration callbacks are exempt.
     * Check that the fixture's at-threshold and non-iteration callbacks report their generic parameters.
     *
     * @return void - Asserts item is absent and entry and data are present in the callback fixture's findings.
     */
    public function testIdentifierQualityAppliesLoopThresholdToIterationCallbackParameters(): void
    {
        $findings = $this->analyseRule('identifier-quality-callback-params.php', IdentifierQualityRule::ID);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        // Short array_filter/array_map callbacks: the sole $item parameter is a loop variable below the threshold.
        self::assertNotContains('item', $names);
        // A callback body at the threshold still demands a meaningful name.
        self::assertContains('entry', $names);
        // A generic parameter on a non-iteration callback is not exempt.
        self::assertContains('data', $names);
    }

    /**
     * Verify identifier quality honours local-variable reference thresholds.
     *
     * @return void - Asserts which fixture locals remain reported when minScopeReferences is two.
     */
    public function testIdentifierQualityHonoursLocalVariableReferenceThreshold(): void
    {
        $unit     = $this->parseFixture('identifier-quality-mutation-cases.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(IdentifierQualityRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            IdentifierQualityRule::ID,
            new RuleSettings(
                enabled:    true,
                thresholds: $settings->thresholds,
                options:    array_merge($settings->options, ['minScopeReferences' => 2]),
            ),
        );
        $findings = array_values(array_filter(
            $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config)),
            static fn ($finding): bool => $finding->ruleId === IdentifierQualityRule::ID,
        ));
        $reported = [];
        foreach ($findings as $index => $finding) {
            $symbol = $finding->symbol;
            $name   = $finding->metadata['identifierName'] ?? null;

            self::assertIsString($symbol, "finding #{$index} is missing a string symbol");
            self::assertIsString($name, "finding #{$index} is missing string metadata.identifierName");

            $reported[] = sprintf('%s:%s', $symbol, $name);
        }

        self::assertContains('Temp::temp():item', $reported);
        self::assertContains('Temp::temp():item2', $reported);
        self::assertContains('Temp::loopAndCatchVariables():value', $reported);
        self::assertContains('Temp::countedVariables():item', $reported);
        self::assertContains('bar():tmp', $reported);
        self::assertNotContains('Temp::countedVariables():thing', $reported);
        self::assertNotContains('Temp::loopAndCatchVariables():item', $reported);
        self::assertNotContains('Temp::loopAndCatchVariables():thing', $reported);
        self::assertNotContains('Temp::loopAndCatchVariables():tmp', $reported);
    }
}
