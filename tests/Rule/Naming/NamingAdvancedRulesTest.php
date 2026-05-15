<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\Naming\ParameterTypeNameRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;

/**
 * Covers configurable and mutation-focused naming rule behavior.
 */
final class NamingAdvancedRulesTest extends NamingRuleTestCase
{
    /**
     * Verify parameter names match object type names.
     *
     * @return void No return value.
     */
    public function testParameterNamesMatchObjectTypeNames(): void
    {
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);
        $reported = [];
        foreach ($findings as $finding) {
            $parameter    = $finding->metadata['parameter'] ?? null;
            $expectedName = $finding->metadata['expectedName'] ?? null;

            self::assertIsString($parameter);
            self::assertIsString($expectedName);

            $reported[$parameter] = $expectedName;
        }

        self::assertSame('bookingSession', $reported['session'] ?? null);
        self::assertSame('bookingIntent', $reported['intent'] ?? null);
        self::assertSame('bookingRequestContext', $reported['requestContext'] ?? null);
    }

    /**
     * Verify parameter type name exempts already specific and builtin names.
     *
     * @return void No return value.
     */
    public function testParameterTypeNameExemptsAlreadySpecificAndBuiltinNames(): void
    {
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);
        $reported = array_map(static fn ($finding): mixed => $finding->metadata['parameter'] ?? null, $findings);

        self::assertNotContains('bookingSession', $reported);
        self::assertNotContains('bookingIntent', $reported);
        self::assertNotContains('bookingRequestContext', $reported);
        self::assertNotContains('entityManager', $reported);
        self::assertNotContains('name', $reported);
        self::assertNotContains('items', $reported);
        self::assertNotContains('dateTimeImmutable', $reported);
    }

    /**
     * Verify parameter type name flags union with null symmetrically.
     *
     * @return void No return value.
     */
    public function testParameterTypeNameFlagsUnionWithNullSymmetrically(): void
    {
        // Regression: `Foo|null` used to be silently ignored because shortTypeName fell
        // through `UnionType` to `return null`; single non-null unions now match `?Foo`.
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);
        $bySymbol = [];
        foreach ($findings as $finding) {
            $symbol       = $finding->symbol;
            $expectedName = $finding->metadata['expectedName'] ?? null;

            self::assertIsString($symbol);
            self::assertIsString($expectedName);

            $bySymbol[$symbol] = $expectedName;
        }

        self::assertSame('bookingSession', $bySymbol['ParameterTypeNameFixture::unionNullableLeft()'] ?? null);
        self::assertSame('bookingSession', $bySymbol['ParameterTypeNameFixture::unionNullableRight()'] ?? null);
    }

    /**
     * Verify parameter type name leaves true union and intersection arm silent.
     *
     * @return void No return value.
     */
    public function testParameterTypeNameLeavesTrueUnionAndIntersectionArmSilent(): void
    {
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);

        $symbols = array_map(static fn ($finding): mixed => $finding->symbol, $findings);
        self::assertNotContains('ParameterTypeNameFixture::realUnion()', $symbols);
        self::assertNotContains('ParameterTypeNameFixture::realIntersectionNullable()', $symbols);
    }

    /**
     * Verify parameter type name respects ignored parameter names option.
     *
     * @return void No return value.
     */
    public function testParameterTypeNameRespectsIgnoredParameterNamesOption(): void
    {
        $unit     = $this->parseFixture('parameter-type-name.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(ParameterTypeNameRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)
            ->withRuleSettings(ParameterTypeNameRule::ID, new RuleSettings(
                enabled:    true,
                thresholds: $settings->thresholds,
                options:    array_merge($settings->options, [
                    'ignoredParameterNames' => ['session', 'thing'],
                ]),
            ));

        $findings          = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $parameterFindings = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === ParameterTypeNameRule::ID,
        ));
        $parameters = array_map(static fn ($finding): mixed => $finding->metadata['parameter'] ?? null, $parameterFindings);

        self::assertNotContains('session', $parameters);
        self::assertNotContains('thing', $parameters);
        self::assertContains('intent', $parameters);
        self::assertContains('requestContext', $parameters);
    }

    /**
     * Verify identifier quality can be tuned with config and accepted abbreviations.
     *
     * @return void No return value.
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
     * Verify identifier quality mutation fixture reports stable kinds and messages.
     *
     * @return void No return value.
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
     * Verify identifier quality honours local-variable reference thresholds.
     *
     * @return void No return value.
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
        foreach ($findings as $finding) {
            $symbol = $finding->symbol;
            $name   = $finding->metadata['identifierName'] ?? null;

            self::assertIsString($symbol);
            self::assertIsString($name);

            $reported[] = sprintf('%s:%s', $symbol, $name);
        }

        self::assertContains('Temp::temp():item', $reported);
        self::assertContains('Temp::temp():item2', $reported);
        self::assertContains('Temp::countedVariables():item', $reported);
        self::assertContains('bar():tmp', $reported);
        self::assertNotContains('Temp::countedVariables():thing', $reported);
        self::assertNotContains('Temp::loopAndCatchVariables():item', $reported);
        self::assertNotContains('Temp::loopAndCatchVariables():thing', $reported);
        self::assertNotContains('Temp::loopAndCatchVariables():tmp', $reported);
    }
}
