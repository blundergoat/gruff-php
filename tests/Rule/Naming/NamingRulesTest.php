<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Naming\BooleanPrefixRule;
use GruffPhp\Rules\Naming\ClassFileMismatchRule;
use GruffPhp\Rules\Naming\ConfusingNameRule;
use GruffPhp\Rules\Naming\GenericMethodNameRule;
use GruffPhp\Rules\Naming\HungarianNotationRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Naming\ShortVariableRule;
use GruffPhp\Rules\Naming\SuffixHungarianRule;
use GruffPhp\Rules\Naming\TestNamingConsistencyRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;

/**
 * Covers the naming rule pack: generic method names, single-char and Hungarian variables with sensible exemptions, boolean prefixes, class-file
 * mismatch, identifier tokenisation, and identifier quality across closures and arrow scopes.
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

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
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

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
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

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
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

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
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

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
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

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
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
        $symbols  = array_map(static fn($finding) => $finding->symbol, $findings);
        $actions  = array_values(array_unique(array_map(
            static fn($finding): string => is_string($finding->metadata['remediationAction'] ?? null)
                ? $finding->metadata['remediationAction']
                : '',
            $findings,
        )));
        $configurationKeys = array_values(array_unique(array_map(
            static fn($finding): string => is_string($finding->metadata['configurationKey'] ?? null)
                ? $finding->metadata['configurationKey']
                : '',
            $findings,
        )));

        self::assertNotSame([], $findings);
        self::assertContains('BooleanPrefixFixture::active()', $symbols);
        self::assertContains('BooleanPrefixFixture::enabled()', $symbols);
        self::assertContains('BooleanPrefixFixture::status()', $symbols);
        self::assertContains('BooleanPrefixFixture::check()', $symbols);
        self::assertContains('BooleanPrefixFixture::didRun()', $symbols);
        // A prefix followed by a lowercase letter is no word boundary, so these still flag.
        self::assertContains('BooleanPrefixFixture::hasty()', $symbols);
        self::assertContains('BooleanPrefixFixture::isolate()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::valid()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::available()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::resolved()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::printable()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::unrequested()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::assistantRequirementsContext()', $symbols);
        self::assertContains('BooleanStateVocabularyFixture::assistantIntentRequires()', $symbols);
        self::assertContains('printable()', $symbols);

        self::assertSame([RemediationAction::Consider->value], $actions);
        self::assertSame(
            ['rules.naming.boolean-prefix.options.acceptedBooleanNames'],
            $configurationKeys,
        );
    }

    /**
     * Verify good boolean prefix not flagged.
     *
     * @return void
     */
    public function testGoodBooleanPrefixNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('BooleanPrefixFixture::isActive()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::hasPermission()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::canEdit()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::wasReady()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::containsValue()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::looksLikeTestFile()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::matchesPattern()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::supportsFeature()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::allowsGuestAccess()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::requiresReview()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::usesCache()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::acceptsPayload()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::permitsRetry()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::includesArchivedRows()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::excludesInactivePatients()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::enablesPracticeAssistant()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::disablesLegacyFallback()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::supportsSelectedAnswerScope()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::requiresCreditPurchaseAccess()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::usesCodeOwnedAnswer()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::matchesPrompt()', $symbols);
        // An underscore after the prefix is a snake_case word boundary, so these read as predicates.
        self::assertNotContains('BooleanPrefixFixture::has_note_been_actioned()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::is_valid_state()', $symbols);
        self::assertNotContains('BooleanStateVocabularyFixture::paymentRequested()', $symbols);
        self::assertNotContains('BooleanStateVocabularyFixture::printableTodayScheduleRequested()', $symbols);
        self::assertNotContains('BooleanStateVocabularyFixture::scheduleLinkEnabled()', $symbols);
        self::assertNotContains('BooleanStateVocabularyFixture::readOnlyScheduleAllowed()', $symbols);
        self::assertNotContains('BooleanStateVocabularyFixture::declineCodeExplanationRequested()', $symbols);
        self::assertNotContains('BooleanStateVocabularyFixture::assistantIntentRequiresContext()', $symbols);
        self::assertNotContains('report_requested()', $symbols);
        self::assertNotContains('assistant_intent_requires_context()', $symbols);
    }

    /**
     * Verify non bool method not flagged.
     *
     * @return void
     */
    public function testNonBoolMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
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

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
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

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
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

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
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

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
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

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
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
        $unit       = (new PhpFileParser())->parse(new SourceFile(
                                                       __DIR__ . '/../../../.php-cs-fixer.dist.php',
                                                       '.php-cs-fixer.dist.php',
                                                   ));
        $registry   = RuleRegistry::defaults();
        $findings   = $registry->analyse([$unit], new RuleContext(
            __DIR__ . '/../../..',
            AnalysisConfig::fromRegistry($registry),
        ));
        $mismatches = array_values(array_filter(
                                       $findings,
                                       static fn($finding): bool => $finding->ruleId === ClassFileMismatchRule::ID,
                                   ));

        self::assertSame([], $mismatches);
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
        $names    = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

        self::assertNotContains('provideThings', $names);
        self::assertNotContains('userName', $names);
        self::assertNotContains('api', $names);
        self::assertNotContains('i', $names);
        self::assertNotContains('e', $names);
        self::assertNotContains('calculateInvoiceTotal', $names);
        self::assertNotContains('invoiceTotal', $names);
    }

    /**
     * Verify identifier quality skips the lone parameter of single-arg wide-typed non-void helpers.
     *
     * @return void
     */
    public function testIdentifierQualitySkipsGenericByPurposeHelpers(): void
    {
        $findings = $this->analyseRule('identifier-quality-generic-helpers.php', IdentifierQualityRule::ID);
        $symbols  = $this->identifierNamesBySymbol($findings);

        self::assertNotContains('GenericByPurposeHelperFixture::stringValue()|value', $symbols);
        self::assertNotContains('GenericByPurposeHelperFixture::fingerprint()|value', $symbols);
        self::assertContains('GenericByPurposeHelperFixture::tag()|value', $symbols);
        self::assertContains('GenericByPurposeHelperFixture::transform()|value', $symbols);
        self::assertContains('GenericByPurposeHelperFixture::describe()|value', $symbols);
    }

    /**
     * Build `symbol|identifier` rows for identifier-quality findings with a reported name.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Identifier-quality findings from a fixture.
     *
     * @return list<string> - symbol and identifier pairs in finding order; findings without string names are omitted
     */
    private function identifierNamesBySymbol(array $findings): array
    {
        $symbols = [];

        foreach ($findings as $finding) {
            $name   = $finding->metadata['identifierName'] ?? null;
            $symbol = $finding->symbol ?? '';
            if (is_string($name)) {
                $symbols[] = sprintf('%s|%s', $symbol, $name);
            }
        }

        return $symbols;
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
                                        static fn($finding): bool => ($finding->metadata['identifierName'] ?? null) === 'item',
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
        $names    = array_map(static fn($finding): mixed => $finding->metadata['identifierName'] ?? null, $findings);

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
            static fn($finding) => str_starts_with($finding->ruleId, 'naming.') && $finding->ruleId !== ClassFileMismatchRule::ID,
        );
        self::assertSame([], array_values($namingFindings));
    }

    /**
     * Run identifier-quality checks with custom rule options.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Identifier-quality rule option overrides.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - identifier-quality findings only, in discovery order; empty when the options suppress every report
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
                                static fn($finding): bool => $finding->ruleId === IdentifierQualityRule::ID,
                            ));
    }

    /**
     * Analyse naming fixtures and return findings for assertions.
     *
     * @param string $fixture - fixture basename under the naming fixtures dir, parsed then run through the full rule pack
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - every finding the default rule pack raises against the fixture, in discovery order; empty when the
     *                                         fixture is clean
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
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Findings to search for a matching rule, metadata value, and symbol prefix.
     * @param string                          $ruleId - rule the finding must come from; others are skipped
     * @param string                          $metadataKey - finding metadata entry to match on, such as the reported identifier name
     * @param string                          $metadataValue - exact value that metadata entry must hold for a match
     * @param string                          $symbolPrefix - prefix the reported symbol must start with, scoping the match to one declaration
     *
     * @return bool - true when some finding matches the rule, metadata, and symbol prefix; false when none does
     */
    private function hasFinding(
        array  $findings,
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
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Naming findings to group by reported variable name.
     *
     * @return array<string, list<string>> - reported symbols bucketed by variable name; a name maps to repeated symbols when reported more than
     *                       once, and absent keys mean that variable was never flagged
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
