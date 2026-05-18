<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Naming\AbbreviationAllowlistRule;
use GruffPhp\Rule\Naming\BooleanPrefixRule;
use GruffPhp\Rule\Naming\ClassFileMismatchRule;
use GruffPhp\Rule\Naming\ConfusingNameRule;
use GruffPhp\Rule\Naming\GenericMethodNameRule;
use GruffPhp\Rule\Naming\HungarianNotationRule;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\Naming\IdentifierTokenizer;
use GruffPhp\Rule\Naming\NegativeBooleanRule;
use GruffPhp\Rule\Naming\ParameterTypeNameRule;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\Naming\SuffixHungarianRule;
use GruffPhp\Rule\Naming\TestNamingConsistencyRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;

/**
 * Covers NamingRulesTest behavior.
 */
final class NamingRulesTest extends NamingRuleTestCase
{
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
     * Verify undeclared short lowercase abbreviations are reported.
     *
     * @return void No return value.
     */
    public function testAbbreviationAllowlistDetectsUndeclaredNames(): void
    {
        $findings = $this->abbreviationFindings(['id', 'fqn', 'raw', 'uri']);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('cfg', $names);
        self::assertContains('db', $names);
        self::assertContains('tmp', $names);
        self::assertNotContains('id', $names);
        self::assertNotContains('fqn', $names);
        self::assertNotContains('raw', $names);
        self::assertNotContains('uri', $names);
        self::assertNotContains('key', $names);
        self::assertNotContains('row', $names);
        self::assertNotContains('ex', $names);
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
        self::assertContains('BooleanPrefixFixture::didRun()', $symbols);
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
        self::assertNotContains('BooleanPrefixFixture::wasReady()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::containsValue()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::looksLikeTestFile()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::matchesPattern()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::supportsFeature()', $symbols);
    }

    /**
     * Verify projects can opt in additional boolean prefixes.
     *
     * @return void No return value.
     */
    public function testBooleanPrefixAllowedPrefixesCanBeConfigured(): void
    {
        $unit       = $this->parseFixture('boolean-prefix.php');
        $registry   = RuleRegistry::defaults();
        $settings   = AnalysisConfig::fromRegistry($registry)->ruleSettings(BooleanPrefixRule::ID);
        $prefixes   = $settings->stringListOption('allowedPrefixes');
        $prefixes[] = 'did';
        $config     = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(true, $settings->thresholds, ['allowedPrefixes' => $prefixes]),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $symbols  = array_map(static fn ($finding): ?string => $finding->symbol, array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
        ));

        self::assertNotContains('BooleanPrefixFixture::didRun()', $symbols);
        self::assertContains('BooleanPrefixFixture::active()', $symbols);
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
     * Verify typed bool properties and parameters need boolean-style names.
     *
     * @return void No return value.
     */
    public function testBooleanPrefixDetectsPropertiesAndParameters(): void
    {
        $findings = $this->booleanPrefixPropertyFindings();
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('changedOnly', $names);
        self::assertContains('infectionRun', $names);
        self::assertContains('infectionRunCtor', $names);
        self::assertNotContains('isPest', $names);
        self::assertNotContains('active', $names);
        self::assertNotContains('emitted', $names);
        self::assertNotContains('valid', $names);
        self::assertNotContains('silent', $names);
        self::assertNotContains('interactive', $names);
        self::assertNotContains('flag', $names);
    }

    /**
     * Verify the state adjective allowlist controls bool property and parameter exemptions.
     *
     * @return void No return value.
     */
    public function testBooleanPrefixStateAdjectiveAllowlistCanBeConfigured(): void
    {
        $findings = $this->booleanPrefixPropertyFindings(['stateAdjectiveAllowlist' => ['active']]);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('emitted', $names);
        self::assertContains('interactive', $names);
        self::assertNotContains('active', $names);
        self::assertNotContains('isPest', $names);
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
        $unit = (new PhpFileParser())->parse(new SourceFile(
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
        foreach ($findings as $index => $finding) {
            $kind    = $finding->metadata['identifierKind'] ?? null;
            $name    = $finding->metadata['identifierName'] ?? null;
            $variant = $finding->metadata['variant'] ?? null;

            self::assertIsString($kind, "finding #{$index} is missing string metadata.identifierKind");
            self::assertIsString($name, "finding #{$index} is missing string metadata.identifierName");
            self::assertIsString($variant, "finding #{$index} is missing string metadata.variant");

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
     * Verify generic foreach variables report only once the configured body threshold is reached.
     *
     * @return void No return value.
     */
    public function testIdentifierQualityFlagsGenericLoopVariablesAtConfiguredBodyThreshold(): void
    {
        $findings = $this->identifierQualityFindingsWithOptions(['loopBodyThreshold' => 4]);
        $reported = $this->reportedVariableSymbolsByName($findings);

        self::assertContains('LoopVariableFixture::longItemLoop()', $reported['item'] ?? []);
        self::assertContains('LoopVariableFixture::longRowLoop()', $reported['row'] ?? []);
        self::assertNotContains('LoopVariableFixture::shortItemLoop()', $reported['item'] ?? []);
        self::assertNotContains('LoopVariableFixture::longMapLoop()', $reported['key'] ?? []);
        self::assertNotContains('LoopVariableFixture::longMapLoop()', $reported['value'] ?? []);

        $raisedThresholdFindings = $this->identifierQualityFindingsWithOptions(['loopBodyThreshold' => 5]);
        $raisedReported          = $this->reportedVariableSymbolsByName($raisedThresholdFindings);
        self::assertNotContains('LoopVariableFixture::longItemLoop()', $raisedReported['item'] ?? []);
        self::assertNotContains('LoopVariableFixture::longRowLoop()', $raisedReported['row'] ?? []);
    }

    /**
     * Verify closure and arrow scopes are checked without leaking into the parent method.
     *
     * @return void No return value.
     */
    public function testClosureAndArrowScopesAreCoveredByNamingRules(): void
    {
        $findings = $this->analyseFixture('closure-coverage.php');

        self::assertTrue($this->hasFinding($findings, ShortVariableRule::ID, 'variable', 'x', 'arrow@'));
        self::assertTrue($this->hasFinding($findings, IdentifierQualityRule::ID, 'identifierName', 'foo', 'closure@'));
        self::assertTrue($this->hasFinding($findings, IdentifierQualityRule::ID, 'identifierName', 'tmp', 'closure@'));
        self::assertTrue($this->hasFinding($findings, HungarianNotationRule::ID, 'variable', 'strName', 'closure@'));
        self::assertTrue($this->hasFinding($findings, BooleanPrefixRule::ID, 'identifierName', 'changedOnly', 'closure@'));
        self::assertTrue($this->hasFinding($findings, ParameterTypeNameRule::ID, 'parameter', 'session', 'closure@'));

        self::assertFalse($this->hasFinding($findings, IdentifierQualityRule::ID, 'identifierName', 'foo', 'ClosureCoverageFixture::run()'));
        self::assertFalse($this->hasFinding($findings, ShortVariableRule::ID, 'variable', 'x', 'ClosureCoverageFixture::run()'));
    }

    /**
     * Verify suffix-Hungarian notation is detected while conversion idioms stay clear.
     *
     * @return void No return value.
     */
    public function testSuffixHungarianDetectsTrailingTypeTokens(): void
    {
        $findings = $this->analyseRule('suffix-hungarian.php', SuffixHungarianRule::ID);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertContains('rawString', $names);
        self::assertContains('useMap', $names);
        self::assertNotContains('cache', $names);
        self::assertNotContains('nameAsString', $names);
    }

    /**
     * Verify negative boolean flags are reported and CLI mirror keys can exempt them.
     *
     * @return void No return value.
     */
    public function testNegativeBooleanDetectsFlagsAndHonoursCliMirrorAllowlist(): void
    {
        $findings = $this->negativeBooleanFindings();
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);
        $keys     = array_map(static fn ($finding): mixed => $finding->metadata['allowlistKey'] ?? null, $findings);

        self::assertContains('noConfig', $names);
        self::assertContains('disableCache', $names);
        self::assertContains('skipValidation', $names);
        self::assertNotContains('nonNull', $names);
        self::assertNotContains('notFound', $names);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::noConfig', $keys);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::configure::disableCache', $keys);
        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::configure::skipValidation', $keys);
        self::assertContains('Fixtures\\Naming\\CliMirrorOptions::noConfig', $keys);

        $allowlistedFindings = $this->negativeBooleanFindings(['Fixtures\\Naming\\CliMirrorOptions::noConfig']);
        $allowlistedKeys     = array_map(static fn ($finding): mixed => $finding->metadata['allowlistKey'] ?? null, $allowlistedFindings);

        self::assertContains('Fixtures\\Naming\\NegativeBooleanFixture::noConfig', $allowlistedKeys);
        self::assertNotContains('Fixtures\\Naming\\CliMirrorOptions::noConfig', $allowlistedKeys);
    }

    /**
     * Verify overlapping naming findings keep the documented primary rule.
     *
     * @return void No return value.
     */
    public function testDuplicateNamingFindingsRespectDeferralOrder(): void
    {
        $unit     = $this->parseFixture('duplicate-deferral.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(IdentifierQualityRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            IdentifierQualityRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, [
                'genericTokens' => ['cb', 'str', 'x'],
            ])),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $rules    = $this->rulesByIdentifierName($findings);

        self::assertContains(IdentifierQualityRule::ID, $rules['cb'] ?? []);
        self::assertNotContains(AbbreviationAllowlistRule::ID, $rules['cb'] ?? []);

        self::assertContains(NegativeBooleanRule::ID, $rules['disableCache'] ?? []);
        self::assertNotContains(BooleanPrefixRule::ID, $rules['disableCache'] ?? []);

        self::assertContains(IdentifierQualityRule::ID, $rules['strX'] ?? []);
        self::assertNotContains(HungarianNotationRule::ID, $rules['strX'] ?? []);
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
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function identifierQualityFindingsWithOptions(array $options): array
    {
        $unit     = $this->parseFixture('identifier-quality.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(IdentifierQualityRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            IdentifierQualityRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, $options)),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === IdentifierQualityRule::ID,
        ));
    }

    /**
     * @param list<string> $acceptedAbbreviations
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function abbreviationFindings(array $acceptedAbbreviations): array
    {
        $unit     = $this->parseFixture('abbreviation-allowlist.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withAcceptedAbbreviations($acceptedAbbreviations);
        $settings = $config->ruleSettings(IdentifierQualityRule::ID);
        $config   = $config->withRuleSettings(
            IdentifierQualityRule::ID,
            new RuleSettings(false, $settings->thresholds, $settings->options),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === AbbreviationAllowlistRule::ID,
        ));
    }

    /**
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyseFixture(string $fixture): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();

        return $registry->analyse([$unit], new RuleContext(
            __DIR__ . '/../../..',
            AnalysisConfig::fromRegistry($registry),
        ));
    }

    /**
     * @param list<\GruffPhp\Finding\Finding> $findings
     */
    private function hasFinding(
        array $findings,
        string $ruleId,
        string $metadataKey,
        string $metadataValue,
        string $symbolPrefix,
    ): bool {
        foreach ($findings as $finding) {
            if ($finding->ruleId !== $ruleId || ($finding->metadata[$metadataKey] ?? null) !== $metadataValue) {
                continue;
            }

            if ($finding->symbol !== null && str_starts_with($finding->symbol, $symbolPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function booleanPrefixPropertyFindings(array $options = []): array
    {
        $unit     = $this->parseFixture('boolean-prefix-properties.php');
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(BooleanPrefixRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, $options)),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === BooleanPrefixRule::ID,
        ));
    }

    /**
     * @param list<string> $cliMirrorAllowlist
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function negativeBooleanFindings(array $cliMirrorAllowlist = []): array
    {
        $unit     = $this->parseFixture('negative-boolean.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings(NegativeBooleanRule::ID);
        $config   = $config->withRuleSettings(
            NegativeBooleanRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, ['cliMirrorAllowlist' => $cliMirrorAllowlist])),
        );
        $booleanSettings = $config->ruleSettings(BooleanPrefixRule::ID);
        $config          = $config->withRuleSettings(
            BooleanPrefixRule::ID,
            new RuleSettings(false, $booleanSettings->thresholds, $booleanSettings->options),
        );
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === NegativeBooleanRule::ID,
        ));
    }

    /**
     * @param list<\GruffPhp\Finding\Finding> $findings
     * @return array<string, list<string>>
     */
    private function rulesByIdentifierName(array $findings): array
    {
        $rules = [];

        foreach ($findings as $finding) {
            $name = $finding->metadata['identifierName']
                ?? $finding->metadata['variable']
                ?? $finding->metadata['parameter']
                ?? null;

            if (!is_string($name)) {
                continue;
            }

            $rules[$name][] = $finding->ruleId;
        }

        return $rules;
    }

    /**
     * @param list<\GruffPhp\Finding\Finding> $findings
     * @return array<string, list<string>>
     */
    private function reportedVariableSymbolsByName(array $findings): array
    {
        $reported = [];

        foreach ($findings as $finding) {
            if (($finding->metadata['identifierKind'] ?? null) !== 'variable') {
                continue;
            }

            $name = $finding->metadata['identifierName'] ?? null;
            if (!is_string($name) || $finding->symbol === null) {
                continue;
            }

            $reported[$name][] = $finding->symbol;
        }

        return $reported;
    }
}
