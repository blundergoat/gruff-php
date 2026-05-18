<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Rule\Docs\MissingClassPhpdocRule;
use GruffPhp\Rule\Docs\MissingConstantPhpdocRule;
use GruffPhp\Rule\Docs\MissingFilePhpdocRule;
use GruffPhp\Rule\Docs\MissingPropertyPhpdocRule;
use GruffPhp\Rule\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rule\Docs\MissingThrowsTagRule;
use GruffPhp\Rule\Docs\StaleParamTagRule;
use GruffPhp\Rule\Docs\TodoDensityRule;
use GruffPhp\Rule\Docs\UselessPhpdocRule;
use GruffPhp\Rule\Docs\VarAnnotationDescriptionRule;

/**
 * Covers documentation tag, file, class, property, and constant rules.
 */
final class DocsTagAndStructureRulesTest extends DocsRuleTestCase
{
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
        self::assertSame(12, $findings[0]->metadata['count']);
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
        // Regression: attributes between `@var` and a property keyword used to trip
        // the token-stream heuristic, but the AST-driven path should skip declarations.
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
        $docFindings = array_filter(
            $this->analyseFixture('clean.php'),
            static fn ($finding): bool => str_starts_with($finding->ruleId, 'docs.'),
        );

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
     * @return void No return value.
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
}
