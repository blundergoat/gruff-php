<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Docs\MissingParamTagRule;
use GruffPhp\Rule\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rule\Docs\MissingReturnTagRule;
use GruffPhp\Rule\Docs\MissingThrowsTagRule;
use GruffPhp\Rule\Docs\StaleParamTagRule;
use GruffPhp\Rule\Docs\TodoDensityRule;
use GruffPhp\Rule\Docs\UselessPhpdocRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class DocsRulesTest extends TestCase
{
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    public function testMissingPublicPhpdocDetected(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::undocumented()', $symbols);
        self::assertContains('MissingPhpdocFixture::trivialUndocumented()', $symbols);
        self::assertContains('MissingPhpdocFixture::privateMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::protectedMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::__toString()', $symbols);
        self::assertContains('AbstractFixture::inheritedHook()', $symbols);

        foreach ($findings as $finding) {
            self::assertSame(\GruffPhp\Finding\Severity::Error, $finding->severity);
        }
    }

    public function testDocumentedMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('MissingPhpdocFixture::documented()', $symbols);
    }

    public function testAccessorsRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::getTitle()', $symbols);
        self::assertContains('MissingPhpdocFixture::setTitle()', $symbols);
        self::assertContains('MissingPhpdocFixture::isActive()', $symbols);
    }

    public function testPrivateAndProtectedMethodsRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::privateMethod()', $symbols);
        self::assertContains('MissingPhpdocFixture::protectedMethod()', $symbols);
    }

    public function testTrivialTypedPublicMethodRequiresPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::trivialUndocumented()', $symbols);
    }

    public function testMagicMethodRequiresPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('MissingPhpdocFixture::__toString()', $symbols);
    }

    public function testRuleInterfaceContractMethodsRequireLocalPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('RuleContractFixture::definition()', $symbols);
        self::assertContains('RuleContractFixture::analyse()', $symbols);
    }

    public function testInternalHelpersAndConventionalReportersRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('InternalHelper::complexUtility()', $symbols);
        self::assertContains('TextReporter::render()', $symbols);
    }

    public function testMissingParamTagDetectedForArrayParameters(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $arrayParamFindings = array_filter(
            $findings,
            static fn ($f): bool => ($f->symbol ?? '') === 'PhpdocTagsFixture::missingArrayParam()',
        );

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $arrayParamFindings);
        self::assertContains('y', $params);
    }

    public function testMissingParamTagDetectedForDocumentedMethodsEvenWhenSignatureTypeDescribesParameter(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $scalarParamFindings = array_filter(
            $findings,
            static fn ($f): bool => ($f->symbol ?? '') === 'PhpdocTagsFixture::missingParam()',
        );

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $scalarParamFindings);
        self::assertContains('y', $params);
    }

    public function testMissingParamTagDetectedWhenThrowsDocOmitsScalarParam(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $scalarParamFindings = array_filter(
            $findings,
            static fn ($f): bool => ($f->symbol ?? '') === 'PhpdocTagsFixture::missingScalarParamWithThrows()',
        );

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $scalarParamFindings);
        self::assertContains('groupId', $params);
    }

    public function testMissingParamTagDetectedForDescriptiveDocblock(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $descriptiveDocFindings = array_filter(
            $findings,
            static fn ($f): bool => ($f->symbol ?? '') === 'PhpdocTagsFixture::missingReturnForDescriptiveDocblock()',
        );

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $descriptiveDocFindings);
        self::assertContains('groupId', $params);
    }

    public function testCompleteParamTagNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::complete()', $symbols);
    }

    public function testGenericParamTagsWithSpacesAreNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::genericParamDocWithSpaces()', $symbols);
    }

    public function testMissingReturnTagDetectedForArrayReturnTypes(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingArrayReturn()', $symbols);
    }

    public function testMissingReturnTagDetectedWhenSignatureFullyDescribesReturn(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturn()', $symbols);
    }

    public function testMissingReturnTagDetectedForDescriptiveDocblock(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturnForDescriptiveDocblock()', $symbols);
    }

    public function testSemanticOnlyDocblockDoesNotRequireSignatureTags(): void
    {
        $paramFindings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);
        $returnFindings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_merge(
            array_map(static fn ($f) => $f->symbol, $paramFindings),
            array_map(static fn ($f) => $f->symbol, $returnFindings),
        );

        self::assertNotContains('PhpdocTagsFixture::apiMarkerOnly()', $symbols);
    }

    public function testStaleParamTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', StaleParamTagRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('oldParam', $findings[0]->metadata['parameter']);
    }

    public function testMissingThrowsTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingThrowsTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::throwsWithoutTag()', $symbols);
    }

    public function testOverrideAwareThrowsRuleUsesInheritedContractsButLocalPhpdocIsRequired(): void
    {
        $missingPhpdoc = $this->analyseRule('phpdoc-tags.php', MissingPublicPhpdocRule::ID);
        $missingThrows = $this->analyseRule('phpdoc-tags.php', MissingThrowsTagRule::ID);
        $missingPhpdocSymbols = array_map(static fn ($finding): ?string => $finding->symbol, $missingPhpdoc);
        $missingThrowsSymbols = array_map(static fn ($finding): ?string => $finding->symbol, $missingThrows);

        self::assertContains('ImplementsDocumentedContract::inheritedThrows()', $missingPhpdocSymbols);
        self::assertNotContains('OverrideDocumentedContract::inheritedThrows()', $missingPhpdocSymbols);
        self::assertNotContains('ImplementsDocumentedContract::inheritedThrows()', $missingThrowsSymbols);
        self::assertNotContains('OverrideDocumentedContract::inheritedThrows()', $missingThrowsSymbols);
    }

    public function testUselessPhpdocDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', UselessPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::uselessDoc()', $symbols);
    }

    public function testUsefulTagDetailsAreNotUselessPhpdoc(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', UselessPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::genericParamDoc()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::arrayShapeDoc()', $symbols);
        self::assertNotContains('PhpdocTagsFixture::describedTagDoc()', $symbols);
        self::assertNotContains(
            'PhpdocTagsFixture::resourceParamDoc()',
            $symbols,
            '`@param resource` adds type info that PHP cannot express in the signature.',
        );
    }

    public function testTodoDensityDetected(): void
    {
        $findings = $this->analyseRule('todo-density.php', TodoDensityRule::ID);

        self::assertCount(1, $findings);
        self::assertSame(6, $findings[0]->metadata['count']);
    }

    public function testCleanFixtureHasNoDocFindings(): void
    {
        $unit = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $docFindings = array_filter($findings, static fn ($f) => str_starts_with($f->ruleId, 'docs.'));
        self::assertSame([], array_values($docFindings));
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
        $path = __DIR__ . '/../../Fixtures/Docs/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Docs/' . $filename));
    }
}
