<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Docs\MissingClassPhpdocRule;
use GruffPhp\Rule\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rule\Docs\MissingFilePhpdocRule;
use GruffPhp\Rule\Docs\MissingParamTagRule;
use GruffPhp\Rule\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rule\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rule\Docs\MissingReturnTagRule;
use GruffPhp\Rule\Docs\MissingThrowsTagRule;
use GruffPhp\Rule\Docs\StaleParamTagRule;
use GruffPhp\Rule\Docs\TodoDensityRule;
use GruffPhp\Rule\Docs\UselessPhpdocRule;
use GruffPhp\Rule\Docs\VarAnnotationDescriptionRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers DocsRulesTest behavior.
 */
final class DocsRulesTest extends TestCase
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
     * Verify missing public phpdoc detected.
     *
     * @return void No return value.
     */
    public function testMissingPublicPhpdocDetected(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::undocumented()', $symbols);
        self::assertContains('MissingPhpdocFixture::trivialUndocumented()', $symbols);
        self::assertContains('MissingPhpdocFixture::privateMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::protectedMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::__toString()', $symbols);
        self::assertContains('AbstractFixture::inheritedHook()', $symbols);

        $severityValues = array_values(array_unique(array_map(static fn ($finding): string => $finding->severity->value, $findings)));

        self::assertSame([\GruffPhp\Finding\Severity::Error->value], $severityValues);
    }

    /**
     * Verify documented method not flagged.
     *
     * @return void No return value.
     */
    public function testDocumentedMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('MissingPhpdocFixture::documented()', $symbols);
    }

    /**
     * Verify accessors require phpdoc.
     *
     * @return void No return value.
     */
    public function testAccessorsRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::getTitle()', $symbols);
        self::assertContains('MissingPhpdocFixture::setTitle()', $symbols);
        self::assertContains('MissingPhpdocFixture::isActive()', $symbols);
    }

    /**
     * Verify private and protected methods require phpdoc.
     *
     * @return void No return value.
     */
    public function testPrivateAndProtectedMethodsRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::privateMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::protectedMethod()', $symbols);
    }

    /**
     * Verify trivial typed public method requires phpdoc.
     *
     * @return void No return value.
     */
    public function testTrivialTypedPublicMethodRequiresPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::trivialUndocumented()', $symbols);
    }

    /**
     * Verify magic method requires phpdoc.
     *
     * @return void No return value.
     */
    public function testMagicMethodRequiresPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::__toString()', $symbols);
    }

    /**
     * Verify rule interface contract methods require local phpdoc.
     *
     * @return void No return value.
     */
    public function testRuleInterfaceContractMethodsRequireLocalPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('RuleContractFixture::definition()', $symbols);
        self::assertContains('RuleContractFixture::analyse()', $symbols);
    }

    /**
     * Verify internal helpers and conventional reporters require phpdoc.
     *
     * @return void No return value.
     */
    public function testInternalHelpersAndConventionalReportersRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('InternalHelper::complexUtility()', $symbols);
        self::assertContains('TextReporter::render()', $symbols);
    }

    /**
     * Verify missing param tag detected for array parameters.
     *
     * @return void No return value.
     */
    public function testMissingParamTagDetectedForArrayParameters(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $arrayParamFindings = array_filter(
            $findings,
            static fn ($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingArrayParam()',
        );

        $params = array_map(static fn ($finding) => $finding->metadata['parameter'] ?? null, $arrayParamFindings);
        self::assertContains('y', $params);
    }

    /**
     * Verify missing param tag detected for documented methods even when signature type describes
     * parameter.
     *
     * @return void No return value.
     */
    public function testMissingParamTagDetectedForDocumentedMethodsEvenWhenSignatureTypeDescribesParameter(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $scalarParamFindings = array_filter(
            $findings,
            static fn ($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingParam()',
        );

        $params = array_map(static fn ($finding) => $finding->metadata['parameter'] ?? null, $scalarParamFindings);
        self::assertContains('y', $params);
    }

    /**
     * Verify missing param tag detected when throws doc omits scalar param.
     *
     * @return void No return value.
     */
    public function testMissingParamTagDetectedWhenThrowsDocOmitsScalarParam(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $scalarParamFindings = array_filter(
            $findings,
            static fn ($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingScalarParamWithThrows()',
        );

        $params = array_map(static fn ($finding) => $finding->metadata['parameter'] ?? null, $scalarParamFindings);
        self::assertContains('groupId', $params);
    }

    /**
     * Verify missing param tag detected for descriptive docblock.
     *
     * @return void No return value.
     */
    public function testMissingParamTagDetectedForDescriptiveDocblock(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $descriptiveDocFindings = array_filter(
            $findings,
            static fn ($finding): bool => ($finding->symbol ?? '') === 'PhpdocTagsFixture::missingReturnForDescriptiveDocblock()',
        );

        $params = array_map(static fn ($finding) => $finding->metadata['parameter'] ?? null, $descriptiveDocFindings);
        self::assertContains('groupId', $params);
    }

    /**
     * Verify complete param tag not flagged.
     *
     * @return void No return value.
     */
    public function testCompleteParamTagNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::complete()', $symbols);
    }

    /**
     * Verify generic param tags with spaces are not flagged.
     *
     * @return void No return value.
     */
    public function testGenericParamTagsWithSpacesAreNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::genericParamDocWithSpaces()', $symbols);
    }

    /**
     * Verify missing return tag detected for array return types.
     *
     * @return void No return value.
     */
    public function testMissingReturnTagDetectedForArrayReturnTypes(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingArrayReturn()', $symbols);
    }

    /**
     * Verify missing return tag detected when signature fully describes return.
     *
     * @return void No return value.
     */
    public function testMissingReturnTagDetectedWhenSignatureFullyDescribesReturn(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturn()', $symbols);
    }

    /**
     * Verify missing return tag detected for descriptive docblock.
     *
     * @return void No return value.
     */
    public function testMissingReturnTagDetectedForDescriptiveDocblock(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturnForDescriptiveDocblock()', $symbols);
    }

    /**
     * Verify any documented method requires return tag.
     *
     * @return void No return value.
     */
    public function testAnyDocumentedMethodRequiresReturnTag(): void
    {
        $paramFindings  = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);
        $returnFindings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $paramSymbols  = array_map(static fn ($finding) => $finding->symbol, $paramFindings);
        $returnSymbols = array_map(static fn ($finding) => $finding->symbol, $returnFindings);

        self::assertNotContains('PhpdocTagsFixture::apiMarkerOnly()', $paramSymbols);
        self::assertContains('PhpdocTagsFixture::apiMarkerOnly()', $returnSymbols);
        self::assertContains('PhpdocTagsFixture::privateMissingReturnTag()', $returnSymbols);
        self::assertNotContains('PhpdocTagsFixture::privateCompleteReturnTag()', $returnSymbols);
    }

    /**
     * Verify void method with docblock triggers missing return tag.
     *
     * @return void No return value.
     */
    public function testVoidMethodWithDocblockTriggersMissingReturnTag(): void
    {
        // Policy lock: per .goat-flow/lessons/workflow.md "Respect explicit rule style
        // even when it restates native syntax", every documented method without @return
        // must fire — including methods declared void or never. The pre-M31 short-circuit
        // that skipped void was an unintended narrowing; M32 Phase 2 locks the broader
        // contract with explicit fixtures so a future agent cannot silently re-narrow it.
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::voidWithDocblock()', $symbols);
        self::assertContains('PhpdocTagsFixture::neverWithDocblock()', $symbols);
    }

    /**
     * Verify stale param tag detected.
     *
     * @return void No return value.
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
     * @return void No return value.
     */
    public function testMissingThrowsTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingThrowsTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::throwsWithoutTag()', $symbols);
    }

    /**
     * Verify override aware throws rule uses inherited contracts but local phpdoc is required.
     *
     * @return void No return value.
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
     * Verify useless phpdoc detected.
     *
     * @return void No return value.
     */
    public function testUselessPhpdocDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', UselessPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::uselessDoc()', $symbols);
    }

    /**
     * Verify useful tag details are not useless phpdoc.
     *
     * @return void No return value.
     */
    public function testUsefulTagDetailsAreNotUselessPhpdoc(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', UselessPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::genericParamDoc()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::arrayShapeDoc()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::describedTagDoc()', $symbols);
        self::assertNotContains(
            'PhpdocTagsFixture::resourceParamDoc()',
            $symbols,
            '`@param resource` adds type info that PHP cannot express in the signature.',
        );
    }

    /**
     * Verify todo density detected.
     *
     * @return void No return value.
     */
    public function testTodoDensityDetected(): void
    {
        $findings = $this->analyseRule('todo-density.php', TodoDensityRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(6, $findings[0]->metadata['count']);
    }

    /**
     * Verify var annotations require description.
     *
     * @return void No return value.
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
     * @return void No return value.
     */
    public function testVarAnnotationOnAttributeDecoratedPropertyDoesNotFlag(): void
    {
        // Regression: a property declaration with `@var` in its docblock and a `#[Attr]`
        // between the docblock and the property keyword used to trip the token-stream
        // heuristic. The AST-driven detection skips any docblock attached to a
        // declaration node (Stmt\Property here) regardless of attribute decoration.
        $findings = $this->analyseRule('var-annotation-description.php', VarAnnotationDescriptionRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('$attributedProperty', $symbols);
    }

    /**
     * Verify var annotation on attribute decorated method does not flag.
     *
     * @return void No return value.
     */
    public function testVarAnnotationOnAttributeDecoratedMethodDoesNotFlag(): void
    {
        $findings = $this->analyseRule('var-annotation-description.php', VarAnnotationDescriptionRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('$attributedMethodResult', $symbols);
    }

    /**
     * Verify clean fixture has no doc findings.
     *
     * @return void No return value.
     */
    public function testCleanFixtureHasNoDocFindings(): void
    {
        $unit     = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $docFindings = array_filter($findings, static fn ($finding) => str_starts_with($finding->ruleId, 'docs.'));
        self::assertSame([], array_values($docFindings));
    }

    /**
     * Verify missing class phpdoc flags class interface trait enum.
     *
     * @return void No return value.
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
     * @return void No return value.
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
     * @return void No return value.
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
     * @return void No return value.
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
     * @return void No return value.
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
     * @return void No return value.
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
     * @return void No return value.
     */
    public function testMissingPropertyPhpdocRecordsKindMetadata(): void
    {
        $findings = $this->analyseRule('missing-property-phpdoc.php', MissingPropertyPhpdocRule::ID);

        $kinds = array_map(static fn ($finding): mixed => $finding->metadata['kind'] ?? null, $findings);
        $invalidKinds = array_values(array_filter(
            $kinds,
            static fn (mixed $kind): bool => !is_string($kind) || !in_array($kind, ['declared', 'promoted'], true),
        ));
        $byKind = array_count_values(array_values(array_filter($kinds, 'is_string')));

        self::assertSame([], $invalidKinds);
        self::assertSame(1, $byKind['declared'] ?? 0);
        self::assertSame(3, $byKind['promoted'] ?? 0);
    }

    /**
     * Verify missing property phpdoc skips anonymous class property.
     *
     * @return void No return value.
     */
    public function testMissingPropertyPhpdocSkipsAnonymousClassProperty(): void
    {
        $findings = $this->analyseRule('missing-property-phpdoc.php', MissingPropertyPhpdocRule::ID);

        $symbols = array_map(static fn ($finding): ?string => $finding->symbol, $findings);
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
     * @return void No return value.
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
     * @return void No return value.
     */
    public function testMissingConstantPhpdocExemptsEnumCasesWhenEnumHasDocblock(): void
    {
        $findings = $this->analyseRule('missing-constant-phpdoc.php', MissingConstantPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('DocumentedEnum::FIRST', $symbols);
        self::assertNotContains('DocumentedEnum::SECOND', $symbols);
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
        $path = __DIR__ . '/../../Fixtures/Docs/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Docs/' . $filename));
    }
}
