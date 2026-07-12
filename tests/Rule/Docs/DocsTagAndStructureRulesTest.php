<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Rules\Docs\BarePhpdocTagsRule;
use GruffPhp\Rules\Docs\MissingClassPhpdocRule;
use GruffPhp\Rules\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rules\Docs\MissingFilePhpdocRule;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rules\Docs\MissingThrowsTagRule;
use GruffPhp\Rules\Docs\RegexCommentRule;
use GruffPhp\Rules\Docs\StaleParamTagRule;
use GruffPhp\Rules\Docs\TodoDensityRule;
use GruffPhp\Rules\Docs\VarAnnotationDescriptionRule;
use GruffPhp\Rules\RuleRegistry;

/**
 * Covers documentation tag, file, class, property, and constant rules.
 */
final class DocsTagAndStructureRulesTest extends DocsRuleTestCase
{
    /**
     * Verify stale param tag detected.
     *
     * @return void
     */
    public function testStaleParamTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', StaleParamTagRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('oldParam', $findings[0]->metadata['parameter']);
    }

    /**
     * Verify missing throws tag detected.
     *
     * @return void
     */
    public function testMissingThrowsTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingThrowsTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::throwsWithoutTag()', $symbols);
    }

    /**
     * Verify only same-scope throws require @throws; nested-scope throws belong to their own contracts.
     *
     * @return void
     */
    public function testMissingThrowsTagPrunesNestedFunctionLikeScopes(): void
    {
        $findings = $this->analyseRule('throws-nested-scopes.php', MissingThrowsTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        // Direct throws in a method and a free function still report...
        self::assertContains('ScopedThrows::directThrow()', $symbols);
        self::assertContains('freeFunctionDirectThrow()', $symbols);
        // ...while arrow-function, closure, anonymous-class, and IIFE throws never charge the outer method.
        self::assertNotContains('ScopedThrows::arrowFunctionThrow()', $symbols);
        self::assertNotContains('ScopedThrows::closureThrow()', $symbols);
        self::assertNotContains('ScopedThrows::anonymousClassThrow()', $symbols);
        self::assertNotContains('ScopedThrows::iifeThrow()', $symbols);
        self::assertCount(2, $findings);
    }

    /**
     * Verify override aware throws rule uses inherited contracts but local phpdoc is required.
     *
     * @return void
     */
    public function testOverrideAwareThrowsRuleUsesInheritedContractsButLocalPhpdocIsRequired(): void
    {
        $missingPhpdoc        = $this->analyseRule('phpdoc-tags.php', MissingPublicPhpdocRule::ID);
        $missingThrows        = $this->analyseRule('phpdoc-tags.php', MissingThrowsTagRule::ID);
        $missingPhpdocSymbols = array_map(static fn ($finding): ?string => $finding->symbol, $missingPhpdoc);
        $missingThrowsSymbols = array_map(static fn ($finding): ?string => $finding->symbol, $missingThrows);

        self::assertContains('ImplementsDocumentedContract::inheritedThrows()', $missingPhpdocSymbols);
        self::assertNotContains('OverrideDocumentedContract::inheritedThrows()', $missingPhpdocSymbols);
        self::assertNotContains('ImplementsDocumentedContract::inheritedThrows()', $missingThrowsSymbols);
        self::assertNotContains('OverrideDocumentedContract::inheritedThrows()', $missingThrowsSymbols);
    }

    /**
     * Verify bare PHPDoc tags are detected.
     *
     * @return void
     */
    public function testBarePhpdocTagsDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', BarePhpdocTagsRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::uselessDoc()', $symbols);
        self::assertContains('PhpdocTagsFixture::genericParamDoc()', $symbols);
        self::assertContains('PhpdocTagsFixture::genericParamDocWithSpaces()', $symbols);
        self::assertContains('PhpdocTagsFixture::resourceParamDoc()', $symbols);
        self::assertContains('PhpdocTagsFixture::arrayShapeDoc()', $symbols);
    }

    /**
     * Verify descriptive PHPDoc tags are not bare PHPDoc.
     *
     * @return void
     */
    public function testDescriptivePhpdocTagsAreNotBarePhpdoc(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', BarePhpdocTagsRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::complete()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::describedTagDoc()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::describedReturnTagDoc()', $symbols);
    }

    /**
     * Verify todo density detected.
     *
     * @return void
     */
    public function testTodoDensityDetected(): void
    {
        $findings = $this->analyseRule('todo-density.php', TodoDensityRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(12, $findings[0]->metadata['count']);
    }

    /**
     * Verify var annotations require description.
     *
     * @return void
     */
    public function testVarAnnotationsRequireDescription(): void
    {
        $findings = $this->analyseRule('var-annotation-description.php', VarAnnotationDescriptionRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('missing', $findings[0]->metadata['variable'] ?? null);
        self::assertSame('$missing', $findings[0]->symbol);
    }

    /**
     * Verify var annotation on attribute decorated property does not flag.
     *
     * @return void
     */
    public function testVarAnnotationOnAttributeDecoratedPropertyDoesNotFlag(): void
    {
        // Regression: attributes between `@var` and a property keyword used to trip
        // the token-stream heuristic, but the AST-driven path should skip declarations.
        $findings = $this->analyseRule('var-annotation-description.php', VarAnnotationDescriptionRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('$attributedProperty', $symbols);
    }

    /**
     * Verify var annotation on attribute decorated method does not flag.
     *
     * @return void
     */
    public function testVarAnnotationOnAttributeDecoratedMethodDoesNotFlag(): void
    {
        $findings = $this->analyseRule('var-annotation-description.php', VarAnnotationDescriptionRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('$attributedMethodResult', $symbols);
    }

    /**
     * Verify call, statement-owner, match-arm, and narrow callable coverage while retained calls report.
     *
     * @return void
     */
    public function testRegexCommentRequiresImmediatePurposeComment(): void
    {
        $findings = $this->analyseRule('regex-comment.php', RegexCommentRule::ID);

        $symbols = array_map(static fn ($finding): ?string => $finding->symbol, $findings);
        sort($symbols);

        $functionNames = array_map(
            static fn ($finding): mixed => $finding->metadata['function'] ?? null,
            $findings,
        );
        sort($functionNames);

        $messages = array_values(array_unique(array_map(static fn ($finding): string => $finding->message, $findings)));
        sort($messages);

        $ruleIds     = array_values(array_unique(array_map(static fn ($finding): string => $finding->ruleId, $findings)));
        $severities  = array_values(array_unique(array_map(static fn ($finding): string => $finding->severity->value, $findings)));
        $confidences = array_values(array_unique(array_map(static fn ($finding): string => $finding->confidence->value, $findings)));

        $stableIdentities = [];
        // Retained user-facing defects keep their line-independent identities across fixture expansion.
        foreach ($findings as $finding) {
            $stableIdentities[$finding->symbol ?? ''] = $finding->stableIdentity();
        }

        self::assertSame(
            [
                'RegexCommentFixture::hasBroadPatternsContract()',
                'RegexCommentFixture::hasBroadPatternsContract()',
                'RegexCommentFixture::hasNestedCallableWithoutInnerComment()',
                'RegexCommentFixture::hasPreviousStatementCommentOnly()',
                'RegexCommentFixture::hasTrailingPreviousStatementComment()',
                'RegexCommentFixture::isSeparatedRegexMatch()',
                'RegexCommentFixture::isUndocumentedRegexMatch()',
                'RegexCommentFixture::matchTheRouteUncommentedRegex()',
                'RegexCommentFixture::safelyValidateText()',
                'RegexCommentFixture::unrelatedReplacementUnderWhitespaceContract()',
            ],
            $symbols,
        );

        self::assertSame(
            [
                'preg_match',
                'preg_match',
                'preg_match',
                'preg_match',
                'preg_match',
                'preg_match',
                'preg_match',
                'preg_replace',
                'preg_replace',
                'preg_replace',
            ],
            $functionNames,
        );

        self::assertSame(
            [
                'preg_match() should have a one-line comment above it explaining what the regex checks.',
                'preg_replace() should have a one-line comment above it explaining what the regex checks.',
            ],
            $messages,
        );

        self::assertSame(
            [RegexCommentRule::ID],
            $ruleIds,
        );
        self::assertSame(
            ['advisory'],
            $severities,
        );
        self::assertSame(
            ['medium'],
            $confidences,
        );

        self::assertSame('784f88c1d188c09f', $stableIdentities['RegexCommentFixture::isUndocumentedRegexMatch()'] ?? null);
        self::assertSame('71689c0f4f3d8560', $stableIdentities['RegexCommentFixture::isSeparatedRegexMatch()'] ?? null);
        self::assertSame('05f644587976fa95', $stableIdentities['RegexCommentFixture::matchTheRouteUncommentedRegex()'] ?? null);
    }

    /**
     * Verify clean fixture has no doc findings.
     *
     * @return void
     */
    public function testCleanFixtureHasNoDocFindings(): void
    {
        $docFindings = array_filter(
            $this->analyseFixture('clean.php'),
            static fn ($finding): bool => str_starts_with($finding->ruleId, 'docs.'),
        );

        self::assertSame([], array_values($docFindings));
    }

    /**
     * Verify missing class phpdoc flags class interface trait enum.
     *
     * @return void
     */
    public function testMissingClassPhpdocFlagsClassInterfaceTraitEnum(): void
    {
        $findings = $this->analyseRule('missing-class-phpdoc.php', MissingClassPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        sort($symbols);

        self::assertSame(
            [
                'UndocumentedClass',
                'UndocumentedEnum',
                'UndocumentedInterface',
                'UndocumentedTrait',
            ],
            $symbols,
        );

        $kinds = array_map(static fn ($finding) => $finding->metadata['classKind'], $findings);
        sort($kinds);
        self::assertSame(['class', 'enum', 'interface', 'trait'], $kinds);
    }

    /**
     * Verify missing class phpdoc skips anonymous and documented classes.
     *
     * @return void
     */
    public function testMissingClassPhpdocSkipsAnonymousAndDocumentedClasses(): void
    {
        $findings = $this->analyseRule('missing-class-phpdoc.php', MissingClassPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('DocumentedClass', $symbols);
        self::assertNotContains('AnonymousFactory', $symbols);
    }

    /**
     * Verify missing file phpdoc flags files without any docblock.
     *
     * @return void
     */
    public function testMissingFilePhpdocFlagsFilesWithoutAnyDocblock(): void
    {
        $findings = $this->analyseRule(
            'missing-file-phpdoc/without-any-docblock.php',
            MissingFilePhpdocRule::ID,
        );

        self::assertCount(1, $findings);
        self::assertSame('tests/Fixtures/Docs/missing-file-phpdoc/without-any-docblock.php', $findings[0]->symbol);
        self::assertSame(1, $findings[0]->line);
    }

    /**
     * Verify missing file phpdoc skips file with explicit file docblock.
     *
     * @return void
     */
    public function testMissingFilePhpdocSkipsFileWithExplicitFileDocblock(): void
    {
        $findings = $this->analyseRule(
            'missing-file-phpdoc/with-file-docblock.php',
            MissingFilePhpdocRule::ID,
        );

        self::assertSame([], $findings);
    }

    /**
     * Verify missing file phpdoc skips single documented class file.
     *
     * @return void
     */
    public function testMissingFilePhpdocSkipsSingleDocumentedClassFile(): void
    {
        $findings = $this->analyseRule(
            'missing-file-phpdoc/with-only-class-docblock.php',
            MissingFilePhpdocRule::ID,
        );

        self::assertSame([], $findings);
    }

    /**
     * Verify missing property phpdoc flags declared and promoted properties.
     *
     * @return void
     */
    public function testMissingPropertyPhpdocFlagsDeclaredAndPromotedProperties(): void
    {
        $findings = $this->analyseRule('missing-property-phpdoc.php', MissingPropertyPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        sort($symbols);

        self::assertSame(
            [
                'DocumentedProperty::$undocumented',
                'PromotedPropertyNoDoc::__construct($first)',
                'PromotedPropertyNoDoc::__construct($second)',
                'PromotedPropertyWithPartialDoc::__construct($undocumentedPromoted)',
            ],
            $symbols,
        );
    }

    /**
     * Verify missing property phpdoc records kind metadata.
     *
     * @return void
     */
    public function testMissingPropertyPhpdocRecordsKindMetadata(): void
    {
        $findings = $this->analyseRule('missing-property-phpdoc.php', MissingPropertyPhpdocRule::ID);

        $kinds        = array_map(static fn ($finding): mixed => $finding->metadata['kind'] ?? null, $findings);
        $invalidKinds = array_values(array_filter(
            $kinds,
            static fn (mixed $kind): bool => !is_string($kind) || !in_array($kind, ['declared', 'promoted'], true),
        ));
        $byKind = array_count_values(array_values(array_filter($kinds, 'is_string')));

        self::assertSame([], $invalidKinds);
        self::assertSame(1, $byKind['declared'] ?? 0);
        $expectedPromotedPropertyFindings = 3;
        self::assertSame($expectedPromotedPropertyFindings, $byKind['promoted'] ?? 0);
    }

    /**
     * Verify missing property phpdoc skips anonymous class property.
     *
     * @return void
     */
    public function testMissingPropertyPhpdocSkipsAnonymousClassProperty(): void
    {
        $findings = $this->analyseRule('missing-property-phpdoc.php', MissingPropertyPhpdocRule::ID);

        $symbols       = array_map(static fn ($finding): ?string => $finding->symbol, $findings);
        $leakedSymbols = array_values(array_filter(
            $symbols,
            static fn (?string $symbol): bool => $symbol !== null && str_contains($symbol, '$exempt'),
        ));

        self::assertNotContains(null, $symbols, 'Finding symbol should be present.');
        self::assertSame([], $leakedSymbols);
    }

    /**
     * Verify missing constant phpdoc flags class constants and enum cases.
     *
     * @return void
     */
    public function testMissingConstantPhpdocFlagsClassConstantsAndEnumCases(): void
    {
        $findings = $this->analyseRule('missing-constant-phpdoc.php', MissingConstantPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        sort($symbols);

        self::assertSame(
            [
                'DocumentedConstants::UNDOCUMENTED',
                'UndocumentedEnum::FIRST',
                'UndocumentedEnum::SECOND',
            ],
            $symbols,
        );

        $kinds = array_map(static fn ($finding) => $finding->metadata['kind'], $findings);
        sort($kinds);
        self::assertSame(['class-constant', 'enum-case', 'enum-case'], $kinds);
    }

    /**
     * Verify missing constant phpdoc exempts enum cases when enum has docblock.
     *
     * @return void
     */
    public function testMissingConstantPhpdocExemptsEnumCasesWhenEnumHasDocblock(): void
    {
        $findings = $this->analyseRule('missing-constant-phpdoc.php', MissingConstantPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('DocumentedEnum::FIRST', $symbols);
        self::assertNotContains('DocumentedEnum::SECOND', $symbols);
    }

    /**
     * Verify local comments cover only the intended constant and group shapes.
     *
     * @return void
     */
    public function testMissingConstantPhpdocAllowsUsefulLocalCommentsByDefault(): void
    {
        $findings = $this->analyseRule('missing-constant-phpdoc-line-comment.php', MissingConstantPhpdocRule::ID);

        $byConstant = $this->constantFindingsByName($findings);

        self::assertSame(
            [
                'PLAIN_NO_COMMENT',
                'PRIVATE_NO_COMMENT',
                'PRIVATE_USELESS_COMMENT',
                'MAX_PAGES',
                'PRIVATE_TODO_COMMENT',
                'PRIVATE_DETACHED_COMMENT',
                'PATIENT_OVERFLOW_PATTERN',
                'COMPARISON_ZETA_PATTERN',
                'VISIBILITY_PROTECTED_PATTERN',
                'SINGLE_MATCHER_FOLLOWER',
                'RESET_AFTER_LOCAL_PATTERN',
                'BLANK_AFTER_GROUP_PATTERN',
                'METHOD_AFTER_GROUP_PATTERN',
                'PHPDOC_AFTER_GROUP_PATTERN',
                'SEARCH_RESULT_LIMIT',
                'DATE_OF_BIRTH_PATTERN',
            ],
            array_keys($byConstant),
        );

        $plain = $byConstant['PLAIN_NO_COMMENT'];
        self::assertArrayNotHasKey('commentKind', $plain->metadata);
        self::assertSame('missing', $plain->metadata['commentQuality'] ?? null);
        self::assertStringContainsString('has no nearby comment explaining its purpose', $plain->message);

        self::assertSame('line', $byConstant['PRIVATE_USELESS_COMMENT']->metadata['commentKind'] ?? null);
        self::assertSame('low-quality', $byConstant['PRIVATE_USELESS_COMMENT']->metadata['commentQuality'] ?? null);
        self::assertStringContainsString('does not explain the constant\'s purpose', $byConstant['PRIVATE_USELESS_COMMENT']->message);
        self::assertArrayNotHasKey('commentKind', $byConstant['PRIVATE_DETACHED_COMMENT']->metadata);
    }

    /**
     * Verify strict API mode requires PHPDoc for public constants with useful local comments.
     *
     * @return void
     */
    public function testMissingConstantPhpdocCanRequirePhpdocForApiConstants(): void
    {
        $findings = $this->analyseRule(
            'missing-constant-phpdoc-line-comment.php',
            MissingConstantPhpdocRule::ID,
            $this->configWithMissingConstantOptions(['requirePhpdocForApiConstants' => true]),
        );

        $byConstant = $this->constantFindingsByName($findings);

        self::assertArrayHasKey('CSV_BYTE_CAP', $byConstant);
        self::assertArrayHasKey('TELEMETRY_KEY', $byConstant);
        self::assertArrayHasKey('ROLE_USER', $byConstant);
        self::assertArrayHasKey('ROLE_ASSISTANT', $byConstant);
        self::assertArrayHasKey('PATIENT_NAME_PATTERN', $byConstant);
        self::assertArrayHasKey('PATIENT_REFERENCE_PATTERN', $byConstant);
        self::assertArrayHasKey('PATIENT_OVERFLOW_PATTERN', $byConstant);
        self::assertArrayNotHasKey('DOCUMENTED_TELEMETRY_KEY', $byConstant);

        $telemetry = $byConstant['TELEMETRY_KEY'];
        self::assertSame('line', $telemetry->metadata['commentKind'] ?? null);
        self::assertSame('meaningful', $telemetry->metadata['commentQuality'] ?? null);
        self::assertTrue($telemetry->metadata['requiresApiPhpdoc'] ?? null);
        self::assertStringContainsString('requires PHPDoc for exported constants', $telemetry->message);

        self::assertTrue($byConstant['ROLE_ASSISTANT']->metadata['groupedLocalComment'] ?? null);

        $patientReference = $byConstant['PATIENT_REFERENCE_PATTERN'];
        self::assertSame('line', $patientReference->metadata['commentKind'] ?? null);
        self::assertSame('meaningful', $patientReference->metadata['commentQuality'] ?? null);
        self::assertTrue($patientReference->metadata['requiresApiPhpdoc'] ?? null);
        self::assertTrue($patientReference->metadata['groupedLocalComment'] ?? null);

        $patientOverflow = $byConstant['PATIENT_OVERFLOW_PATTERN'];
        self::assertSame('missing', $patientOverflow->metadata['commentQuality'] ?? null);
        self::assertTrue($patientOverflow->metadata['requiresApiPhpdoc'] ?? null);
        self::assertArrayNotHasKey('groupedLocalComment', $patientOverflow->metadata);
    }

    /**
     * Verify API path patterns can opt specific exported paths into strict constant PHPDoc.
     *
     * @return void
     */
    public function testMissingConstantPhpdocCanRequirePhpdocForApiPathPatterns(): void
    {
        $findings = $this->analyseRule(
            'missing-constant-phpdoc-line-comment.php',
            MissingConstantPhpdocRule::ID,
            $this->configWithMissingConstantOptions(['apiPathPatterns' => ['tests/Fixtures/Docs/*']]),
        );

        $byConstant = $this->constantFindingsByName($findings);

        self::assertArrayHasKey('TELEMETRY_KEY', $byConstant);
        self::assertTrue($byConstant['TELEMETRY_KEY']->metadata['requiresApiPhpdoc'] ?? null);
        self::assertArrayNotHasKey('DOCUMENTED_TELEMETRY_KEY', $byConstant);
    }

    /**
     * Key constant PHPDoc findings by the reported constant name.
     *
     * @param list<\GruffPhp\Results\Finding\Finding> $findings - Findings from a missing-constant-phpdoc fixture.
     *
     * @return array<string, \GruffPhp\Results\Finding\Finding> - findings keyed by constant name; findings without a string name are omitted
     */
    private function constantFindingsByName(array $findings): array
    {
        $byConstant = [];

        foreach ($findings as $finding) {
            $constantName = $finding->metadata['constantName'] ?? null;
            if (is_string($constantName)) {
                $byConstant[$constantName] = $finding;
            }
        }

        return $byConstant;
    }

    /**
     * Build a config override for docs.missing-constant-phpdoc options.
     *
     * @param array<string, bool|list<string>> $options - Option overrides merged with rule defaults.
     *
     * @return AnalysisConfig - Config with the requested missing-constant-phpdoc option values.
     */
    private function configWithMissingConstantOptions(array $options): AnalysisConfig
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $settings = $config->ruleSettings(MissingConstantPhpdocRule::ID);

        return $config->withRuleSettings(
            MissingConstantPhpdocRule::ID,
            new RuleSettings(true, $settings->thresholds, array_merge($settings->options, $options)),
        );
    }
}
