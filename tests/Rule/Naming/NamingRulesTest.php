<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Naming\BooleanPrefixRule;
use GruffPhp\Rule\Naming\ClassFileMismatchRule;
use GruffPhp\Rule\Naming\ConfusingNameRule;
use GruffPhp\Rule\Naming\GenericMethodNameRule;
use GruffPhp\Rule\Naming\HungarianNotationRule;
use GruffPhp\Rule\Naming\IdentifierQualityRule;
use GruffPhp\Rule\Naming\IdentifierTokenizer;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\Naming\SuffixHungarianRule;
use GruffPhp\Rule\Naming\TestNamingConsistencyRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;

/**
 * Covers the naming rule pack: generic method names, single-char and Hungarian variables with sensible exemptions, boolean prefixes, class-file mismatch, identifier tokenisation, and identifier quality across closures and arrow scopes.
 */
final class NamingRulesTest extends NamingRuleTestCase
{
    /**
     * Verify generic method names detected.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
     */
    public function testLoopCountersExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertNotContains('i', $vars);
        self::assertNotContains('j', $vars);
    }

    /**
     * Verify catch variable excluded.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * Verify non bool method not flagged.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
     */
    public function testIdentifierTokenizerSplitsCommonIdentifierShapes(): void
    {
        $identifierTokenizer = new IdentifierTokenizer();

        self::assertSame(['http', 'response', 'code'], $identifierTokenizer->tokenize('HTTPResponseCode'));
        self::assertSame(['order', 'item', '2'], $identifierTokenizer->tokenize('order_item2'));
        self::assertSame(['temp'], $identifierTokenizer->tokenize('_temp'));
    }

    /**
     * Verify identifier quality finds placeholder generic and numbered names.
     *
     * @return void
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
        self::assertSame('generic', $reported['variable:value'] ?? null);
        self::assertSame('generic', $reported['interface:Data'] ?? null);
        self::assertSame('generic', $reported['trait:HelperThing'] ?? null);
        self::assertSame('placeholder', $reported['function:bar'] ?? null);
        self::assertSame('placeholder', $reported['parameter:obj'] ?? null);
        self::assertSame('placeholder', $reported['variable:tmp'] ?? null);
    }

    /**
     * Verify identifier quality exempts common noise sources.
     *
     * @return void
     */
    public function testIdentifierQualityExemptsCommonNoiseSources(): void
    {
        $findings = $this->analyseRule('identifier-quality.php', IdentifierQualityRule::ID);
        $names    = array_map(static fn ($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertNotContains('provideThings', $names);
        self::assertNotContains('userName', $names);
        self::assertNotContains('api', $names);
        self::assertNotContains('i', $names);
        self::assertNotContains('e', $names);
        self::assertNotContains('calculateInvoiceTotal', $names);
        self::assertNotContains('invoiceTotal', $names);
    }

    /**
     * Verify identifier quality metadata is specific.
     *
     * @return void
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
     * @return void
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
     * @return void
     */
    public function testClosureAndArrowScopesAreCoveredByNamingRules(): void
    {
        $findings = $this->analyseFixture('closure-coverage.php');

        self::assertTrue($this->hasFinding(findings: $findings, ruleId: ShortVariableRule::ID, metadataKey: 'variable', metadataValue: 'x', symbolPrefix: 'arrow@'));
        self::assertTrue($this->hasFinding(findings: $findings, ruleId: IdentifierQualityRule::ID, metadataKey: 'identifierName', metadataValue: 'foo', symbolPrefix: 'closure@'));
        self::assertTrue($this->hasFinding(findings: $findings, ruleId: IdentifierQualityRule::ID, metadataKey: 'identifierName', metadataValue: 'tmp', symbolPrefix: 'closure@'));
        self::assertTrue($this->hasFinding(findings: $findings, ruleId: HungarianNotationRule::ID, metadataKey: 'variable', metadataValue: 'strName', symbolPrefix: 'closure@'));
        self::assertTrue($this->hasFinding(findings: $findings, ruleId: BooleanPrefixRule::ID, metadataKey: 'identifierName', metadataValue: 'changedOnly', symbolPrefix: 'closure@'));

        self::assertFalse($this->hasFinding(findings: $findings, ruleId: IdentifierQualityRule::ID, metadataKey: 'identifierName', metadataValue: 'foo', symbolPrefix: 'ClosureCoverageFixture::run()'));
        self::assertFalse($this->hasFinding(findings: $findings, ruleId: ShortVariableRule::ID, metadataKey: 'variable', metadataValue: 'x', symbolPrefix: 'ClosureCoverageFixture::run()'));
    }

    /**
     * Verify suffix-Hungarian notation is detected while conversion idioms stay clear.
     *
     * @return void
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
     * Verify clean fixture has no naming findings except file mismatch.
     *
     * @return void
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
     * Run identifier-quality checks with custom rule options.
     *
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
     * Analyse naming fixtures and return findings for assertions.
     *
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
     * @return bool True when the expected finding is present.
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
     * Group naming findings by reported variable name.
     *
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
