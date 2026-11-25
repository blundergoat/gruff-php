<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\RemediationAction;
use GruffPhp\Rules\Docs\BarePhpdocTagsRule;
use GruffPhp\Rules\Docs\MissingClassPhpdocRule;
use GruffPhp\Rules\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rules\Docs\MissingFilePhpdocRule;
use GruffPhp\Rules\Docs\MissingParamTagRule;
use GruffPhp\Rules\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rules\Docs\MissingPhpdocRule;
use GruffPhp\Rules\Docs\MissingReturnTagRule;
use GruffPhp\Rules\Docs\MissingThrowsTagRule;
use GruffPhp\Rules\Docs\RegexCommentRule;
use GruffPhp\Rules\Docs\TodoDensityRule;
use GruffPhp\Rules\Docs\VarAnnotationDescriptionRule;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Shared\PhysicalCommentAttachment;

/**
 * Covers documentation tag, file, class, property, and constant rules.
 * Exercises fixture findings, declaration exemptions, and local comment attachment.
 * Checks regex diagnostics and promoted-constructor documentation ownership.
 */
final class DocsTagAndStructureRulesTest extends DocsRuleTestCase
{
    /**
     * Verify a fixture method with an undocumented throw is reported.
     *
     * @return void - Asserts the missing-throws findings include PhpdocTagsFixture::throwsWithoutTag().
     */
    public function testMissingThrowsTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingThrowsTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::throwsWithoutTag()', $symbols);
    }

    /**
     * Verify direct throws are reported without attributing nested throws to their enclosing methods.
     *
     * @return void - Asserts two direct-throw findings and excludes four outer methods containing nested throws.
     */
    public function testMissingThrowsTagPrunesNestedFunctionLikeScopes(): void
    {
        $findings = $this->analyseRule('throws-nested-scopes.php', MissingThrowsTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);

        self::assertContains('ScopedThrows::directThrow()', $symbols);
        self::assertContains('freeFunctionDirectThrow()', $symbols);

        self::assertNotContains('ScopedThrows::arrowFunctionThrow()', $symbols);
        self::assertNotContains('ScopedThrows::closureThrow()', $symbols);
        self::assertNotContains('ScopedThrows::anonymousClassThrow()', $symbols);
        self::assertNotContains('ScopedThrows::iifeThrow()', $symbols);
        self::assertCount(2, $findings);
    }

    /**
     * Verify the override-aware throws rule accepts inherited contracts but still requires local PHPDoc.
     *
     * @return void - Asserts inherited throws contracts are accepted while the implementation without local PHPDoc is flagged.
     */
    public function testOverrideAwareThrowsRuleUsesInheritedContractsButLocalPhpdocIsRequired(): void
    {
        $missingPhpdoc        = $this->analyseRule('phpdoc-tags.php', MissingPhpdocRule::ID);
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
     * @return void - Asserts five fixture methods appear in bare-tag findings, including generic, resource, and shape tags.
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
     * @return void - Asserts three fixture methods with tag descriptions are absent from bare-tag findings.
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
     * Verify the TODO-density fixture produces one finding with the expected count.
     *
     * @return void - Asserts exactly one finding has count metadata equal to twelve.
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
     * @return void - Asserts one finding identifies missing in its variable metadata and $missing as its symbol.
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
     * @return void - Asserts $attributedProperty is absent from local var-annotation findings.
     */
    public function testVarAnnotationOnAttributeDecoratedPropertyDoesNotFlag(): void
    {
        // Attributes between the @var docblock and property must preserve the declaration exemption.

        $findings = $this->analyseRule('var-annotation-description.php', VarAnnotationDescriptionRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('$attributedProperty', $symbols);
    }

    /**
     * Verify var annotation on attribute decorated method does not flag.
     *
     * @return void - Asserts $attributedMethodResult is absent from local var-annotation findings.
     */
    public function testVarAnnotationOnAttributeDecoratedMethodDoesNotFlag(): void
    {
        $findings = $this->analyseRule('var-annotation-description.php', VarAnnotationDescriptionRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('$attributedMethodResult', $symbols);
    }

    /**
     * Verify regex-comment findings, diagnostic metadata, and retained finding identities for the fixture.
     *
     * @return void - Asserts expected symbols, regex functions, diagnostics, stable identities, and comment-line classification.
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
        $actions     = array_values(array_unique(array_map(
            static fn ($finding): string => is_string($finding->metadata['remediationAction'] ?? null)
                ? $finding->metadata['remediationAction']
                : '',
            $findings,
        )));
        $configurationKeys = array_values(array_unique(array_map(
            static fn ($finding): string => is_string($finding->metadata['configurationKey'] ?? null)
                ? $finding->metadata['configurationKey']
                : '',
            $findings,
        )));

        $stableIdentities = [];
        // Retained user-facing defects keep their line-independent identities across fixture expansion.
        foreach ($findings as $finding) {
            $stableIdentities[$finding->symbol ?? ''] = $finding->stableIdentity();
        }

        self::assertSame(
            [
                'RegexCommentFixture::hasBlankSeparatedRegexStatementGroup()',
                'RegexCommentFixture::hasBroadPatternsContract()',
                'RegexCommentFixture::hasBroadPatternsContract()',
                'RegexCommentFixture::hasNestedCallableWithoutInnerComment()',
                'RegexCommentFixture::hasPreviousStatementCommentOnly()',
                'RegexCommentFixture::hasTrailingPreviousStatementComment()',
                'RegexCommentFixture::isSeparatedRegexMatch()',
                'RegexCommentFixture::isUndocumentedRegexMatch()',
                'RegexCommentFixture::matchTheRouteUncommentedRegex()',
                'RegexCommentFixture::misleadingNamedWhitespaceFold()',
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
                'preg_match',
                'preg_replace',
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
        self::assertSame([RemediationAction::Apply->value], $actions);
        self::assertSame(['rules.docs.regex-comment.options.functionNames'], $configurationKeys);

        self::assertSame('784f88c1d188c09f', $stableIdentities['RegexCommentFixture::isUndocumentedRegexMatch()'] ?? null);
        self::assertSame('71689c0f4f3d8560', $stableIdentities['RegexCommentFixture::isSeparatedRegexMatch()'] ?? null);
        self::assertSame('05f644587976fa95', $stableIdentities['RegexCommentFixture::matchTheRouteUncommentedRegex()'] ?? null);

        $this->assertRegexCommentLineClassification();
    }

    /**
     * Verify clean fixture has no doc findings.
     *
     * @return void - Asserts the clean fixture has no findings whose rule IDs start with the docs. prefix.
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
     * @return void - Asserts exactly four undocumented symbols and the class, enum, interface, and trait kinds.
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
     * Verify the reported symbols exclude DocumentedClass and AnonymousFactory.
     *
     * @return void - Asserts DocumentedClass and AnonymousFactory are absent from the reported symbols.
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
     * @return void - Asserts one finding names the without-any-docblock.php fixture and reports line one.
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
     * @return void - Asserts the with-file-docblock.php fixture produces no missing-file-PHPDoc findings.
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
     * @return void - Asserts the with-only-class-docblock.php fixture produces no missing-file-PHPDoc findings.
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
     * Verify each promoted-constructor documentation gap has one rule owner.
     *
     * @return void - Asserts exactly four ownership rows across the missing-PHPDoc, parameter, and property rules.
     */
    public function testPromotedConstructorDocumentationHasOneOwner(): void
    {
        $source = <<<'PHP'
<?php
final class NoDocPromotions
{
    public function __construct(
        public string $first,
        private int $second,
    ) {
    }
}
final class ProsePromotion
{
    /** Build the instance from its promoted value. */
    public function __construct(public string $promoted)
    {
    }
}
final class PromotedSkeleton
{
    /**
     * @api
     */
    public function __construct(public string $promoted, string $ordinary)
    {
    }
}
final class PartialPromotions
{
    /**
     * Build the instance from two promoted values.
     *
     * @param string $documentedPromoted - The documented value.
     */
    public function __construct(
        public string $documentedPromoted,
        protected int $missingPromoted,
    ) {
    }
}
final class CompletePromotions
{
    /**
     * Build the fully documented instance.
     *
     * @param string $first - The first value.
     * @param int $second - The second value.
     */
    public function __construct(public string $first, private int $second)
    {
    }
}
final class OrdinarySkeleton
{
    /**
     * @api
     */
    public function __construct(string $ordinary)
    {
    }
}
PHP;

        $findings = [
            ...$this->analyseSourceRule($source, MissingPhpdocRule::ID),
            ...$this->analyseSourceRule($source, MissingParamTagRule::ID),
            ...$this->analyseSourceRule($source, MissingPropertyPhpdocRule::ID),
        ];
        $ownershipRows = array_map(static function (Finding $finding): string {
            $subject = $finding->metadata['parameter'] ?? $finding->metadata['propertyName'] ?? '';

            return sprintf(
                '%s|%s|%s',
                $finding->ruleId,
                $finding->symbol ?? '',
                is_string($subject) ? $subject : '',
            );
        }, $findings);
        sort($ownershipRows);

        self::assertSame(
            [
                'docs.missing-param-tag|PartialPromotions::__construct()|missingPromoted',
                'docs.missing-param-tag|PromotedSkeleton::__construct()|promoted',
                'docs.missing-param-tag|ProsePromotion::__construct()|promoted',
                'docs.missing-phpdoc|NoDocPromotions::__construct()|',
            ],
            $ownershipRows,
        );
    }

    /**
     * Verify missing property PHPDoc flags only undocumented declarations.
     *
     * @return void - Asserts only DocumentedProperty::$undocumented is reported, with kind declared.
     */
    public function testMissingPropertyPhpdocFlagsOnlyUndocumentedDeclarations(): void
    {
        $findings = $this->analyseRule('missing-property-phpdoc.php', MissingPropertyPhpdocRule::ID);

        self::assertSame(
            ['DocumentedProperty::$undocumented'],
            array_map(static fn ($finding): ?string => $finding->symbol, $findings),
        );
        self::assertSame(
            ['declared'],
            array_map(static fn ($finding): mixed => $finding->metadata['kind'] ?? null, $findings),
        );
    }

    /**
     * Verify missing property phpdoc skips anonymous class property.
     *
     * @return void - Asserts every finding has a symbol and none contains the anonymous property's $exempt name.
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
     * @return void - Asserts one class constant and two enum cases are reported with their kinds and Apply remediation.
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
        self::assertSame(
            [RemediationAction::Apply->value],
            array_values(array_unique(array_map(
                static fn ($finding): string => is_string($finding->metadata['remediationAction'] ?? null)
                    ? $finding->metadata['remediationAction']
                    : '',
                $findings,
            ))),
        );
    }

    /**
     * Verify missing constant phpdoc exempts enum cases when enum has docblock.
     *
     * @return void - Asserts DocumentedEnum::FIRST and DocumentedEnum::SECOND are absent from constant findings.
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
     * @return void - Asserts the exact reported constants and selected missing or low-quality comment diagnostics.
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
                'RESET_GROUP_BETA_PATTERN',
                'RESET_AFTER_LOCAL_PATTERN',
                'BLANK_AFTER_GROUP_PATTERN',
                'METHOD_AFTER_GROUP_PATTERN',
                'PHPDOC_AFTER_GROUP_PATTERN',
                'TRAILING_COMMENT_FOLLOWER',
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
        self::assertSame('missing', $byConstant['TRAILING_COMMENT_FOLLOWER']->metadata['commentQuality'] ?? null);
        self::assertArrayNotHasKey('commentKind', $byConstant['TRAILING_COMMENT_FOLLOWER']->metadata);
    }

    /**
     * Verify strict API mode requires PHPDoc for public constants with useful local comments.
     *
     * @return void - Asserts selected strict-mode findings, grouped-comment metadata, and the documented constant's exemption.
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

        // PATIENT_OVERFLOW_PATTERN shares the grouped local comment, but strict mode still requires PHPDoc.
        $patientOverflow = $byConstant['PATIENT_OVERFLOW_PATTERN'];
        self::assertSame('meaningful', $patientOverflow->metadata['commentQuality'] ?? null);
        self::assertTrue($patientOverflow->metadata['requiresApiPhpdoc'] ?? null);
        self::assertTrue($patientOverflow->metadata['groupedLocalComment'] ?? null);
        self::assertStringContainsString('requires PHPDoc for exported constants', $patientOverflow->message);
    }

    /**
     * Verify API path patterns can opt specific exported paths into strict constant PHPDoc.
     *
     * @return void - Asserts a matching path requires API PHPDoc for TELEMETRY_KEY while DOCUMENTED_TELEMETRY_KEY stays exempt.
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
     * Verify comment-only delimiter chains cover a regex while executable segments still report.
     *
     * @return void - Asserts regex finding presence and symbols, plus classification of comment-only, executable, and unclosed lines.
     */
    private function assertRegexCommentLineClassification(): void
    {
        $commentOnlyFindings = $this->analyseSourceRule(<<<'PHP'
<?php
final class CommentOnlyRegex
{
    public function matches(string $subject): bool
    {
        /* Match the supported marker. */
        return preg_match('/marker/', $subject) === 1;
    }
}
PHP, RegexCommentRule::ID);
        $commentChainFindings = $this->analyseSourceRule(<<<'PHP'
<?php
final class CommentChainRegex
{
    public function matches(string $subject): bool
    {
        /* Context. */ // Match the supported marker.
        return preg_match('/marker/', $subject) === 1;
    }
}
PHP, RegexCommentRule::ID);
        $trailingCodeFindings = $this->analyseSourceRule(<<<'PHP'
<?php
final class TrailingCodeRegex
{
    public function matches(string $subject): bool
    {
        /* Match the supported marker. */ $unrelated = 1;
        return preg_match('/marker/', $subject) === 1;
    }
}
PHP, RegexCommentRule::ID);
        $sandwichedCodeFindings = $this->analyseSourceRule(<<<'PHP'
<?php
final class SandwichedCodeRegex
{
    public function matches(string $subject): bool
    {
        /* Context. */ $unrelated = 1; /* Match the supported marker. */
        return preg_match('/marker/', $subject) === 1;
    }
}
PHP, RegexCommentRule::ID);

        self::assertSame([], $commentOnlyFindings);
        self::assertSame([], $commentChainFindings);
        self::assertCount(1, $trailingCodeFindings);
        self::assertSame('TrailingCodeRegex::matches()', $trailingCodeFindings[0]->symbol);
        self::assertCount(1, $sandwichedCodeFindings);
        self::assertSame('SandwichedCodeRegex::matches()', $sandwichedCodeFindings[0]->symbol);
        self::assertTrue(PhysicalCommentAttachment::isCommentOnlyLine('    /* Match the supported marker. */'));
        self::assertTrue(PhysicalCommentAttachment::isCommentOnlyLine('    /* Context. */ // Match the supported marker.'));
        self::assertTrue(PhysicalCommentAttachment::isCommentOnlyLine('    /* Context. */ # Match the supported marker.'));
        self::assertTrue(PhysicalCommentAttachment::isCommentOnlyLine('    /* Context. */ /* Match the supported marker. */'));
        self::assertFalse(PhysicalCommentAttachment::isCommentOnlyLine('    /* Match the supported marker. */ $unrelated = 1;'));
        self::assertFalse(PhysicalCommentAttachment::isCommentOnlyLine('    /* Context. */ $unrelated = 1; /* Match the supported marker. */'));
        self::assertFalse(PhysicalCommentAttachment::isCommentOnlyLine('    /* Unclosed context.'));
    }

    /**
     * Verify BarePhpdocTagsRule accepts a described @param tag ending with a variable and inheritance rules inspect all direct interfaces.
     *
     * @return void - Asserts zero findings for a described @param ending in $limit and for tags documented on a second implemented interface.
     */
    public function testBareTagVariableDescriptionsAndMultiInterfaceInheritance(): void
    {
        $describedParamSource = <<<'PHP'
<?php

/**
 * @param int $value - must stay below $limit
 * @param array{'key(': int} $shape - keyed map whose key contains a parenthesis
 * @return int - Clamped integer value.
 */
function clamp(int $value, int $limit, array $shape): int
{
    return $value < $limit ? $value : $limit;
}

/**
 * @param	int	$tabbed
 */
function bareTabbedParam(int $tabbed): void
{
}

/**
 * Explains @return-value handling in prose without a real return tag.
 */
function proseOnlyReturnMention(): int
{
    return 1;
}
PHP;
        $multiInterfaceSource = <<<'PHP'
<?php

interface FirstContract
{
    public function handle(string $payload): bool;
}

interface SecondContract
{
    /**
     * Handles the incoming payload.
     *
     * @param string $payload - Raw payload text to validate.
     *
     * @return bool - True when the payload is accepted.
     */
    public function handle(string $payload): bool;
}

interface ExtendedContract extends SecondContract
{
    /**
     * Refines the parent contract.
     */
    public function handle(string $payload): bool;
}

final class MultiContractHandler implements FirstContract, SecondContract
{
    /**
     * Executes both contracts.
     */
    public function handle(string $payload): bool
    {
        return $payload !== '';
    }
}
PHP;

        self::assertCount(1, $this->analyseSourceRule($describedParamSource, BarePhpdocTagsRule::ID));
        $missingReturnFindings = $this->analyseSourceRule($describedParamSource, MissingReturnTagRule::ID);
        self::assertSame(['bareTabbedParam()', 'proseOnlyReturnMention()'], array_map(static fn($finding): ?string => $finding->symbol, $missingReturnFindings));
        self::assertSame([], $this->analyseSourceRule($multiInterfaceSource, MissingReturnTagRule::ID));
        self::assertSame([], $this->analyseSourceRule($multiInterfaceSource, MissingParamTagRule::ID));
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
