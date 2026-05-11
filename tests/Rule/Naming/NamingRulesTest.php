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

final class NamingRulesTest extends TestCase
{
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    public function testGenericMethodNamesDetected(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        self::assertCount(6, $findings);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('GenericMethodFixture::process()', $symbols);
        self::assertContains('GenericMethodFixture::handle()', $symbols);
        self::assertContains('GenericMethodFixture::execute()', $symbols);
    }

    public function testQualifiedMethodNamesNotFlagged(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('GenericMethodFixture::processPayment()', $symbols);
        self::assertNotContains('GenericMethodFixture::handleRequest()', $symbols);
        self::assertNotContains('GenericMethodFixture::calculateTotal()', $symbols);
    }

    public function testSymfonyConsoleExecuteOverrideNotFlagged(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('FrameworkOverrideFixture::execute()', $symbols);
    }

    public function testSingleCharVariablesDetected(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertContains('x', $vars);
        self::assertContains('a', $vars);
    }

    public function testLoopCountersExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertNotContains('i', $vars);
        self::assertNotContains('j', $vars);
    }

    public function testAcceptedAbbreviationsCanSuppressShortVariableFindings(): void
    {
        $unit = $this->parseFixture('short-variable.php');
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)->withAcceptedAbbreviations(['a']);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $shortVariableFindings = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === ShortVariableRule::ID,
        ));

        $vars = array_map(static fn ($finding): mixed => $finding->metadata['variable'] ?? null, $shortVariableFindings);
        self::assertContains('x', $vars);
        self::assertNotContains('a', $vars);
    }

    public function testCatchVariableExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertNotContains('e', $vars);
    }

    public function testBooleanPrefixMissing(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        self::assertNotSame([], $findings);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('BooleanPrefixFixture::active()', $symbols);
        self::assertContains('BooleanPrefixFixture::enabled()', $symbols);
        self::assertContains('BooleanPrefixFixture::check()', $symbols);
    }

    public function testGoodBooleanPrefixNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('BooleanPrefixFixture::isActive()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::hasPermission()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::canEdit()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::containsValue()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::matchesPattern()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::supportsFeature()', $symbols);
    }

    public function testNonBoolMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('BooleanPrefixFixture::getName()', $symbols);
    }

    public function testHungarianNotationDetected(): void
    {
        $findings = $this->analyseRule('hungarian.php', HungarianNotationRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertContains('strName', $vars);
        self::assertContains('arrItems', $vars);
        self::assertContains('intCount', $vars);
        self::assertContains('boolReady', $vars);
        self::assertContains('objUser', $vars);
    }

    public function testCleanVariablesNotFlaggedAsHungarian(): void
    {
        $findings = $this->analyseRule('hungarian.php', HungarianNotationRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertNotContains('name', $vars);
        self::assertNotContains('items', $vars);
        self::assertNotContains('strategy', $vars);
    }

    public function testConfusingStandaloneClassNames(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('Helper', $symbols);
        self::assertContains('Util', $symbols);
    }

    public function testDomainSpecificClassNamesNotFlagged(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('UserService', $symbols);
        self::assertNotContains('OrderRepository', $symbols);
    }

    public function testMixedTestNamingDetected(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('MixedTestNamingFixture', $findings[0]->symbol);
    }

    public function testConsistentTestNamingNotFlagged(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('ConsistentTestNamingFixture', $symbols);
    }

    public function testClassFileMismatch(): void
    {
        $findings = $this->analyseRule('clean.php', ClassFileMismatchRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('CleanNamingFixture', $findings[0]->symbol);
    }

    public function testIdentifierTokenizerSplitsCommonIdentifierShapes(): void
    {
        $tokenizer = new IdentifierTokenizer();

        self::assertSame(['http', 'response', 'code'], $tokenizer->tokenize('HTTPResponseCode'));
        self::assertSame(['order', 'item', '2'], $tokenizer->tokenize('order_item2'));
        self::assertSame(['temp'], $tokenizer->tokenize('_temp'));
    }

    public function testIdentifierQualityFindsPlaceholderGenericAndNumberedNames(): void
    {
        $findings = $this->analyseRule('identifier-quality.php', IdentifierQualityRule::ID);
        $reported = [];

        foreach ($findings as $finding) {
            $identifierKind = $finding->metadata['identifierKind'] ?? null;
            $identifierName = $finding->metadata['identifierName'] ?? null;
            self::assertIsString($identifierKind);
            self::assertIsString($identifierName);
            $reported[$identifierKind . ':' . $identifierName] = $finding->metadata['variant'] ?? null;
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

    public function testIdentifierQualityExemptsCommonNoiseSources(): void
    {
        $findings = $this->analyseRule('identifier-quality.php', IdentifierQualityRule::ID);
        $names = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertNotContains('provideThings', $names);
        self::assertNotContains('userName', $names);
        self::assertNotContains('api', $names);
        self::assertNotContains('value', $names);
        self::assertNotContains('i', $names);
        self::assertNotContains('e', $names);
        self::assertNotContains('calculateInvoiceTotal', $names);
        self::assertNotContains('invoiceTotal', $names);
    }

    public function testIdentifierQualityMetadataIsSpecific(): void
    {
        $findings = $this->analyseRule('identifier-quality.php', IdentifierQualityRule::ID);
        $itemFinding = null;

        foreach ($findings as $finding) {
            if (($finding->metadata['identifierName'] ?? null) === 'item') {
                $itemFinding = $finding;
                break;
            }
        }

        self::assertNotNull($itemFinding);
        self::assertSame('variable', $itemFinding->metadata['identifierKind']);
        self::assertSame('generic', $itemFinding->metadata['variant']);
        self::assertSame(['item'], $itemFinding->metadata['tokens']);
        self::assertSame('item', $itemFinding->metadata['matchedToken']);
        self::assertSame(Severity::Advisory, $itemFinding->severity);
    }

    public function testParameterNamesMatchObjectTypeNames(): void
    {
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);

        $reported = [];
        foreach ($findings as $finding) {
            $parameter = $finding->metadata['parameter'] ?? '';
            self::assertIsString($parameter);
            $reported[$parameter] = $finding->metadata['expectedName'] ?? null;
        }

        self::assertSame('bookingSession', $reported['session'] ?? null);
        self::assertSame('bookingIntent', $reported['intent'] ?? null);
        self::assertSame('bookingRequestContext', $reported['requestContext'] ?? null);
    }

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

    public function testParameterTypeNameFlagsUnionWithNullSymmetrically(): void
    {
        // Regression: `Foo|null` used to be silently ignored because shortTypeName fell
        // through `UnionType` to `return null`. After M32 Phase 1 the rule unwraps any
        // union whose non-null arm count is exactly one and recurses, matching `?Foo`.
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);

        $bySymbol = [];
        foreach ($findings as $finding) {
            $symbol = $finding->symbol;
            self::assertIsString($symbol);
            $bySymbol[$symbol] = $finding->metadata['expectedName'] ?? null;
        }

        self::assertSame('bookingSession', $bySymbol['ParameterTypeNameFixture::unionNullableLeft()'] ?? null);
        self::assertSame('bookingSession', $bySymbol['ParameterTypeNameFixture::unionNullableRight()'] ?? null);
    }

    public function testParameterTypeNameLeavesTrueUnionAndIntersectionArmSilent(): void
    {
        $findings = $this->analyseRule('parameter-type-name.php', ParameterTypeNameRule::ID);

        $symbols = array_map(static fn ($finding): mixed => $finding->symbol, $findings);
        self::assertNotContains('ParameterTypeNameFixture::realUnion()', $symbols);
        self::assertNotContains('ParameterTypeNameFixture::realIntersectionNullable()', $symbols);
    }

    public function testIdentifierQualityCanBeTunedWithConfigAndAcceptedAbbreviations(): void
    {
        $unit = $this->parseFixture('identifier-quality.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(IdentifierQualityRule::ID);
        $config = AnalysisConfig::fromRegistry($registry)
            ->withAcceptedAbbreviations(['api'])
            ->withRuleSettings(IdentifierQualityRule::ID, new \GruffPhp\Config\RuleSettings(
                enabled: true,
                thresholds: $settings->thresholds,
                options: array_merge($settings->options, [
                    'placeholderNames' => ['foo', 'bar', 'baz', 'tmp', 'temp'],
                    'ignoredNames' => ['thing', 'data', 'helperthing'],
                ]),
            ));

        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
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

    public function testCleanFixtureHasNoNamingFindingsExceptFileMismatch(): void
    {
        $unit = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $namingFindings = array_filter(
            $findings,
            static fn ($f) => str_starts_with($f->ruleId, 'naming.') && $f->ruleId !== ClassFileMismatchRule::ID,
        );
        self::assertSame([], array_values($namingFindings));
    }

    /**
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyseRule(string $fixture, string $ruleId): array
    {
        $unit = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter($findings, static fn ($f) => $f->ruleId === $ruleId));
    }

    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Naming/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Naming/' . $filename));
    }
}
