<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Naming\ClassFileMismatchRule;
use GruffPhp\Rules\Naming\ConfusingNameRule;
use GruffPhp\Rules\Naming\IdentifierQualityRule;
use GruffPhp\Rules\Naming\ShortVariableRule;
use GruffPhp\Rules\Naming\TestNamingConsistencyRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;

/**
 * Exercises short-variable, confusing-name, test-naming, class/file, and identifier-quality rules through parsed fixtures.
 * Checks reported names, exclusions, metadata, remediation, and configurable loop-body thresholds.
 * Includes closure and arrow-function attribution checks alongside fixture-analysis helpers.
 */
final class NamingRulesTest extends NamingRuleTestCase
{
    /**
     * Ordinary single-character variables remain reportable by the short-variable rule.
     *
     * @return void - Asserts that x and a appear among reported variables.
     */
    public function testSingleCharVariablesDetected(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertContains('x', $vars);
        self::assertContains('a', $vars);
    }

    /**
     * Loop-counter variables remain exempt from short-variable findings.
     *
     * @return void - Asserts that i and j are absent from reported variables.
     */
    public function testLoopCountersExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertNotContains('i', $vars);
        self::assertNotContains('j', $vars);
    }

    /**
     * Catch variables remain exempt from short-variable findings.
     *
     * @return void - Asserts that e is absent from reported variables.
     */
    public function testCatchVariableExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn($finding) => $finding->metadata['variable'] ?? null, $findings);
        self::assertNotContains('e', $vars);
    }

    /**
     * Standalone Helper and Util class names produce confusing-name findings.
     *
     * @return void - Asserts that Helper and Util appear among reported symbols.
     */
    public function testConfusingStandaloneClassNames(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('Helper', $symbols);
        self::assertContains('Util', $symbols);
    }

    /**
     * Domain-specific UserService and OrderRepository class names remain exempt.
     *
     * @return void - Asserts that UserService and OrderRepository are absent from reported symbols.
     */
    public function testDomainSpecificClassNamesNotFlagged(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UserService', $symbols);
        self::assertNotContains('OrderRepository', $symbols);
    }

    /**
     * Mixing test-method naming styles produces one fixture-level consistency finding.
     *
     * @return void - Asserts one finding with symbol MixedTestNamingFixture.
     */
    public function testMixedTestNamingDetected(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('MixedTestNamingFixture', $findings[0]->symbol);
    }

    /**
     * The consistently named fixture remains exempt when its file also contains mixed test naming.
     *
     * @return void - Asserts that ConsistentTestNamingFixture is absent from reported symbols.
     */
    public function testConsistentTestNamingNotFlagged(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('ConsistentTestNamingFixture', $symbols);
    }

    /**
     * The lowercase clean.php fixture receives file-rename-only remediation for its class mismatch.
     *
     * @return void - Asserts one CleanNamingFixture finding with a file-rename-only remediation.
     */
    public function testClassFileMismatch(): void
    {
        $findings = $this->analyseRule('clean.php', ClassFileMismatchRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('CleanNamingFixture', $findings[0]->symbol);
        // The rule offers a class rename only for an uppercase-first alphanumeric stem.
        self::assertSame('Rename the file to CleanNamingFixture.php so it matches the class it declares.', $findings[0]->remediation);
    }

    /**
     * Check rename advice for Widget.php and gadget_widget.php against the Gadget class.
     *
     * @return void - Asserts a class-rename alternative for Widget.php and file-rename-only advice for gadget_widget.php.
     */
    public function testClassFileMismatchOffersClassRenameOnlyForStudlyCapsStem(): void
    {
        $source = "<?php\n\nfinal class Gadget\n{\n}\n";

        $studly = $this->analyseSourceRule($source, ClassFileMismatchRule::ID, 'src/Widget.php');
        $snake  = $this->analyseSourceRule($source, ClassFileMismatchRule::ID, 'src/gadget_widget.php');

        self::assertCount(1, $studly);
        self::assertSame('Rename the file to Gadget.php or the class to Widget.', $studly[0]->remediation);
        self::assertCount(1, $snake);
        self::assertSame('Rename the file to Gadget.php so it matches the class it declares.', $snake[0]->remediation);
    }

    /**
     * Check class/file mismatch handling for the PHP-CS-Fixer configuration file.
     *
     * @return void - Asserts no class/file mismatch findings for .php-cs-fixer.dist.php.
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
     * @return void - Asserts the listed name variants, string metadata fields, and shared remediation text.
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
        // placeholderNames and genericTokens list what the rule reports, so the advice points an intentional name at ignoredNames.
        self::assertSame(
            ['Rename the identifier to describe its domain role or action. If the name is intentional, add it to `rules.naming.identifier-quality.options.ignoredNames` in `.gruff-php.yaml`, or remove the token from `placeholderNames` or `genericTokens` when your project does not treat it as a placeholder.'],
            array_values(array_unique(array_map(static fn($finding): ?string => $finding->remediation, $findings))),
        );
    }

    /**
     * Verify identifier quality exempts common noise sources.
     *
     * @return void - Asserts that each named exclusion is absent from identifierName metadata.
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
     * Verify generic parameter-name exemptions for the helper-signature fixture.
     *
     * @return void - Asserts that value is omitted for stringValue and fingerprint but reported for tag, transform, and describe.
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
     * Build pipe-delimited symbol and identifier rows for identifier-quality findings with a reported name.
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
     * Check metadata for the first reported item identifier.
     *
     * @return void - Asserts variable/generic classification, item tokens and matched token, and advisory severity.
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
     * Check loop-variable reports at body thresholds of four and five statements.
     *
     * @return void - Asserts long-loop item and row reports at 4 but not 5, plus short-loop and map exclusions at 4.
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
     * Check selected naming findings in closure, arrow, and parent-method scopes.
     *
     * @return void - Asserts closure foo/tmp and arrow x reports, with no parent-method foo or x report.
     */
    public function testClosureAndArrowScopesAreCoveredByNamingRules(): void
    {
        $findings = $this->analyseFixture('closure-coverage.php');

        self::assertTrue($this->hasFinding(findings: $findings, ruleId: ShortVariableRule::ID, metadataKey: 'variable', metadataValue: 'x', symbolPrefix: 'arrow@'));
        self::assertTrue($this->hasFinding(findings: $findings, ruleId: IdentifierQualityRule::ID, metadataKey: 'identifierName', metadataValue: 'foo', symbolPrefix: 'closure@'));
        self::assertTrue($this->hasFinding(findings: $findings, ruleId: IdentifierQualityRule::ID, metadataKey: 'identifierName', metadataValue: 'tmp', symbolPrefix: 'closure@'));

        self::assertFalse($this->hasFinding(findings: $findings, ruleId: IdentifierQualityRule::ID, metadataKey: 'identifierName', metadataValue: 'foo', symbolPrefix: 'ClosureCoverageFixture::run()'));
        self::assertFalse($this->hasFinding(findings: $findings, ruleId: ShortVariableRule::ID, metadataKey: 'variable', metadataValue: 'x', symbolPrefix: 'ClosureCoverageFixture::run()'));
    }

    /**
     * Verify that no other naming rule reports on the clean fixture.
     *
     * @return void - Asserts no naming findings remain after class/file mismatch findings are excluded.
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
     * @return list<\GruffPhp\Results\Finding\Finding> - Identifier-quality findings in registry report order; empty when none survive.
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
     * @return list<\GruffPhp\Results\Finding\Finding> - Default-rule findings in registry report order; empty when none survive.
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
     * Check for a finding with the required rule, exact metadata value, and symbol prefix.
     *
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
     * @return array<string, list<string>> - Symbols by variable name, retaining duplicates; skips non-string names and null symbols.
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
