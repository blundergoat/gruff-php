<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Naming\BooleanPrefixRule;
use GruffPhp\Rule\Naming\ClassFileMismatchRule;
use GruffPhp\Rule\Naming\ConfusingNameRule;
use GruffPhp\Rule\Naming\GenericMethodNameRule;
use GruffPhp\Rule\Naming\HungarianNotationRule;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\Naming\IdentifierTokenizer;
use GruffPhp\Rule\Naming\ParameterTypeNameRule;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\Naming\TestNamingConsistencyRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers NamingRulesTest behavior.
 */
final class NamingRulesTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify generic method names detected.
     *
     * @return void No return value.
     */
    public function testGenericMethodNamesDetected(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        self::assertCount(6, $findings);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('GenericMethodFixture::process()', $symbols);
        self::assertContains('GenericMethodFixture::handle()', $symbols);
        self::assertContains('GenericMethodFixture::execute()', $symbols);
    }

    /**
     * Verify qualified method names not flagged.
     *
     * @return void No return value.
     */
    public function testQualifiedMethodNamesNotFlagged(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('GenericMethodFixture::processPayment()', $symbols);
        self::assertNotContains('GenericMethodFixture::handleRequest()', $symbols);
        self::assertNotContains('GenericMethodFixture::calculateTotal()', $symbols);
    }

    /**
     * Verify symfony console execute override not flagged.
     *
     * @return void No return value.
     */
    public function testSymfonyConsoleExecuteOverrideNotFlagged(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('FrameworkOverrideFixture::execute()', $symbols);
    }

    /**
     * Verify single char variables detected.
     *
     * @return void No return value.
     */
    public function testSingleCharVariablesDetected(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertContains('x', $vars);
        self::assertContains('a', $vars);
    }

    /**
     * Verify loop counters excluded.
     *
     * @return void No return value.
     */
    public function testLoopCountersExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertNotContains('i', $vars);
        self::assertNotContains('j', $vars);
    }

    /**
     * Verify accepted abbreviations can suppress short variable findings.
     *
     * @return void No return value.
     */
    public function testAcceptedAbbreviationsCanSuppressShortVariableFindings(): void
    {
        $unit                  = $this->parseFixture('short-variable.php');
        $registry              = RuleRegistry::defaults();
        $config                = AnalysisConfig::fromRegistry($registry)->withAcceptedAbbreviations(['a']);
        $findings              = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $shortVariableFindings = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === ShortVariableRule::ID,
        ));

        $vars = array_map(static fn ($finding): mixed => $finding->metadata['variable'] ?? null, $shortVariableFindings);
        self::assertContains('x', $vars);
        self::assertNotContains('a', $vars);
    }

    /**
     * Verify catch variable excluded.
     *
     * @return void No return value.
     */
    public function testCatchVariableExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertNotContains('e', $vars);
    }

    /**
     * Verify boolean prefix missing.
     *
     * @return void No return value.
     */
    public function testBooleanPrefixMissing(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        self::assertNotSame([], $findings);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('BooleanPrefixFixture::active()', $symbols);
        self::assertContains('BooleanPrefixFixture::enabled()', $symbols);
        self::assertContains('BooleanPrefixFixture::check()', $symbols);
    }

    /**
     * Verify good boolean prefix not flagged.
     *
     * @return void No return value.
     */
    public function testGoodBooleanPrefixNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('BooleanPrefixFixture::isActive()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::hasPermission()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::canEdit()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::containsValue()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::looksLikeTestFile()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::matchesPattern()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::supportsFeature()', $symbols);
    }

    /**
     * Verify non bool method not flagged.
     *
     * @return void No return value.
     */
    public function testNonBoolMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('BooleanPrefixFixture::getName()', $symbols);
    }

    /**
     * Verify hungarian notation detected.
     *
     * @return void No return value.
     */
    public function testHungarianNotationDetected(): void
    {
        $findings = $this->analyseRule('hungarian.php', HungarianNotationRule::ID);

        $vars = array_map(static fn ($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertContains('strName', $vars);
        self::assertContains('arrItems', $vars);
        self::assertContains('intCount', $vars);
        self::assertContains('boolReady', $vars);
        self::assertContains('objUser', $vars);
    }

    /**
     * Verify clean variables not flagged as hungarian.
     *
     * @return void No return value.
     */
    public function testCleanVariablesNotFlaggedAsHungarian(): void
    {
        $findings = $this->analyseRule('hungarian.php', HungarianNotationRule::ID);

        $vars = array_map(static fn ($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertNotContains('name', $vars);
        self::assertNotContains('items', $vars);
        self::assertNotContains('strategy', $vars);
    }

    /**
     * Verify confusing standalone class names.
     *
     * @return void No return value.
     */
    public function testConfusingStandaloneClassNames(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('Helper', $symbols);
        self::assertContains('Util', $symbols);
    }

    /**
     * Verify domain specific class names not flagged.
     *
     * @return void No return value.
     */
    public function testDomainSpecificClassNamesNotFlagged(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('UserService', $symbols);
        self::assertNotContains('OrderRepository', $symbols);
    }

    /**
     * Verify mixed test naming detected.
     *
     * @return void No return value.
     */
    public function testMixedTestNamingDetected(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('MixedTestNamingFixture', $findings[0]->symbol);
    }

    /**
     * Verify consistent test naming not flagged.
     *
     * @return void No return value.
     */
    public function testConsistentTestNamingNotFlagged(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('ConsistentTestNamingFixture', $symbols);
    }

    /**
     * Verify class file mismatch.
     *
     * @return void No return value.
     */
    public function testClassFileMismatch(): void
    {
        $findings = $this->analyseRule('clean.php', ClassFileMismatchRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('CleanNamingFixture', $findings[0]->symbol);
    }

    /**
     * Verify class file mismatch skips non classlike config filenames.
     *
     * @return void No return value.
     */
    public function testClassFileMismatchSkipsNonClasslikeConfigFilenames(): void
    {
        $unit = $this->parser->parse(new SourceFile(
            __DIR__ . '/../../../.php-cs-fixer.dist.php',
            '.php-cs-fixer.dist.php',
        ));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(
            __DIR__ . '/../../..',
            AnalysisConfig::fromRegistry($registry),
        ));
        $mismatches = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === ClassFileMismatchRule::ID,
        ));

        self::assertSame([], $mismatches);
    }

    /**
     * Verify identifier tokenizer splits common identifier shapes.
     *
     * @return void No return value.
     */
    public function testIdentifierTokenizerSplitsCommonIdentifierShapes(): void
    {
        $tokenizer = new IdentifierTokenizer();

        self::assertSame(['http', 'response', 'code'], $tokenizer->tokenize('HTTPResponseCode'));
        self::assertSame(['order', 'item', '2'], $tokenizer->tokenize('order_item2'));
        self::assertSame(['temp'], $tokenizer->tokenize('_temp'));
    }

    /**
     * Verify identifier quality finds placeholder generic and numbered names.
     *
     * @return void No return value.
     */
    public function testIdentifierQualityFindsPlaceholderGenericAndNumberedNames(): void
    {
        $findings = $this->analyseRule('identifier-quality.php', IdentifierQualityRule::ID);
        $reported = [];
        foreach ($findings as $finding) {
            $kind = $finding->metadata['identifierKind'] ?? null;
            $name = $finding->metadata['identifierName'] ?? null;
            $variant = $finding->metadata['variant'] ?? null;

            self::assertIsString($kind);
            self::assertIsString($name);
            self::assertIsString($variant);

            $reported[sprintf('%s:%s', $kind, $name)] = $variant;
        }

        self::assertSame('generic', $reported['class:Thing'] ?? null);
        self::assertSame('placeholder', $reported['method:temp'] ?? null);
        self::assertSame('placeholder', $reported['parameter:foo'] ?? null);
        self::assertSame('generic', $reported['property:stuff'] ?? null);
        self::assertSame('generic', $reported['variable:item'] ?? null);
        self::assertSame('numbered', $reported['variable:item2'] ?? null);
        self::assertSame('generic', $reported['interface:Data'] ?? null);
        self::assertSame('generic', $reported['trait:HelperThing'] ?? null);
        self::assertSame('placeholder', $reported['function:bar'] ?? null);
        self::assertSame('placeholder', $reported['parameter:obj'] ?? null);
        self::assertSame('placeholder', $reported['variable:tmp'] ?? null);
    }

    /**
     * Verify identifier quality exempts common noise sources.
     *
     * @return void No return value.
     */
    public function testIdentifierQualityExemptsCommonNoiseSources(): void
    {
        $findings = $this->analyseRule('identifier-quality.php', IdentifierQualityRule::ID);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertNotContains('provideThings', $names);
        self::assertNotContains('userName', $names);
        self::assertNotContains('api', $names);
        self::assertNotContains('value', $names);
        self::assertNotContains('i', $names);
        self::assertNotContains('e', $names);
        self::assertNotContains('calculateInvoiceTotal', $names);
        self::assertNotContains('invoiceTotal', $names);
    }

    /**
     * Verify identifier quality metadata is specific.
     *
     * @return void No return value.
     */
    public function testIdentifierQualityMetadataIsSpecific(): void
    {
        $findings    = $this->analyseRule('identifier-quality.php', IdentifierQualityRule::ID);
        $itemFinding = array_values(array_filter(
            $findings,
            static fn ($finding): bool => ($finding->metadata['identifierName'] ?? null) === 'item',
        ))[0] ?? null;

        self::assertNotNull($itemFinding);
        self::assertSame('variable', $itemFinding->metadata['identifierKind']);
        self::assertSame('generic', $itemFinding->metadata['variant']);
        self::assertSame(['item'], $itemFinding->metadata['tokens']);
        self::assertSame('item', $itemFinding->metadata['matchedToken']);
        self::assertSame(Severity::Advisory, $itemFinding->severity);
    }

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
            $parameter = $finding->metadata['parameter'] ?? null;
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
        // through `UnionType` to `return null`. After M32 Phase 1 the rule unwraps any
        // union whose non-null arm count is exactly one and recurses, matching `?Foo`.
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);
        $bySymbol = [];
        foreach ($findings as $finding) {
            $symbol = $finding->symbol;
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
        // Configured exemption: parameters whose name appears in
        // ignoredParameterNames are skipped regardless of type. Gruff's own
        // .gruff.yaml uses this for AST-walker conventions ($node, $context, etc.);
        // downstream projects can pick their own list.
        $unit     = $this->parseFixture('parameter-type-name.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(ParameterTypeNameRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)
            ->withRuleSettings(ParameterTypeNameRule::ID, new \GruffPhp\Config\RuleSettings(
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
            ->withRuleSettings(IdentifierQualityRule::ID, new \GruffPhp\Config\RuleSettings(
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
        $tuples = array_map(
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
            new \GruffPhp\Config\RuleSettings(
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
            $name = $finding->metadata['identifierName'] ?? null;

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

    /**
     * Verify clean fixture has no naming findings except file mismatch.
     *
     * @return void No return value.
     */
    public function testCleanFixtureHasNoNamingFindingsExceptFileMismatch(): void
    {
        $unit     = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $namingFindings = array_filter(
            $findings,
            static fn ($finding) => str_starts_with($finding->ruleId, 'naming.') && $finding->ruleId !== ClassFileMismatchRule::ID,
        );
        self::assertSame([], array_values($namingFindings));
    }

    /**
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyseRule(string $fixture, string $ruleId): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter($findings, static fn ($finding) => $finding->ruleId === $ruleId));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return \GruffPhp\Parser\AnalysisUnit Fixture value.
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Naming/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Naming/' . $filename));
    }
}
