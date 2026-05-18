<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Modernisation;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Modernisation\MixedTypeOveruseRule;
use GruffPhp\Rule\Modernisation\PhpDocMixedOveruseRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers PhpDocMixedOveruseRuleTest behavior.
 */
final class PhpDocMixedOveruseRuleTest extends TestCase
{
    /** Project root used by filesystem and CLI tests. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';
    /** Fixture path used by this test case. */
    private const FIXTURE = 'tests/Fixtures/Modernisation/phpdoc-mixed-overuse.php';

    /**
     * Verify fixture produces expected phpdoc mixed findings.
     *
     * @return void No return value.
     */
    public function testFixtureProducesExpectedPhpdocMixedFindings(): void
    {
        $findings = $this->phpdocMixedFindings($this->analyseFixture());

        self::assertCount(22, $findings, 'Expected 22 phpdoc-mixed findings on the fixture.');

        $methodsFlagged = array_values(array_unique(array_map(
            static fn (Finding $finding): string => $finding->symbol ?? '',
            $findings,
        )));
        sort($methodsFlagged);

        self::assertSame(
            [
                '$propertyUnionMixed',
                '$uppercasePsalmVar',
                'PhpDocMixedOveruseFixture::arrayBagSuffixIsNotAllowed()',
                'PhpDocMixedOveruseFixture::arrayShapeMixedReturn()',
                'PhpDocMixedOveruseFixture::listSuffixIsNotAllowed()',
                'PhpDocMixedOveruseFixture::methodPropertyTag()',
                'PhpDocMixedOveruseFixture::methodTypeAliasTag()',
                'PhpDocMixedOveruseFixture::methodVarTag()',
                'PhpDocMixedOveruseFixture::mixedAfterArrayBagTag()',
                'PhpDocMixedOveruseFixture::mixedAfterCoveredStandaloneTag()',
                'PhpDocMixedOveruseFixture::mixedAfterNonMixedTag()',
                'PhpDocMixedOveruseFixture::mixedAfterUnscannedTag()',
                'PhpDocMixedOveruseFixture::mixedInCollection()',
                'PhpDocMixedOveruseFixture::mixedInIterable()',
                'PhpDocMixedOveruseFixture::prefixedListIsNotAllowed()',
                'PhpDocMixedOveruseFixture::unionWithMixed()',
                'PhpDocMixedOveruseFixture::untypedSignatureMixedDoc()',
                'PhpDocMixedOveruseFixture::uppercaseMixedReturnTag()',
                'RAW_PAYLOAD',
                'functionUnionMixedDocOnMixedSignature()',
                'untypedFunctionMixedParam()',
                'untypedFunctionMixedReturn()',
            ],
            $methodsFlagged,
        );
    }

    /**
     * Verify unstructured array bags with mixed leaves are allowed.
     *
     * @return void No return value.
     */
    public function testUnstructuredArrayBagsWithMixedLeavesAreAllowed(): void
    {
        $findings       = $this->phpdocMixedFindings($this->analyseFixture());
        $allowedSymbols = [
            '$listOfMixedVar',
            'PhpDocMixedOveruseFixture::arrayShapeMixedParam()',
            'PhpDocMixedOveruseFixture::isMixedOnlyInReturnDescription()',
            'PhpDocMixedOveruseFixture::nestedArrayShapeMixed()',
            'PhpDocMixedOveruseFixture::phpstanReturnMixed()',
            'PhpDocMixedOveruseFixture::psalmParamMixed()',
        ];

        $unexpectedFindings = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => in_array($finding->symbol, $allowedSymbols, true),
        ));

        self::assertSame([], $unexpectedFindings);
    }

    /**
     * Verify standalone mixed param does not double fire with signature rule.
     *
     * @return void No return value.
     */
    public function testStandaloneMixedParamDoesNotDoubleFireWithSignatureRule(): void
    {
        $findings = $this->analyseFixture();

        $standaloneMixedParam = array_values(array_filter(
            $this->phpdocMixedFindings($findings),
            static fn (Finding $finding): bool => $finding->symbol === 'PhpDocMixedOveruseFixture::standaloneMixedParam()',
        ));
        self::assertSame([], $standaloneMixedParam, 'Standalone @param mixed must not fire when the signature already declares mixed.');

        $standaloneMixedReturn = array_values(array_filter(
            $this->phpdocMixedFindings($findings),
            static fn (Finding $finding): bool => $finding->symbol === 'PhpDocMixedOveruseFixture::standaloneMixedReturn()',
        ));
        self::assertSame([], $standaloneMixedReturn);

        $standaloneMixedVar = array_values(array_filter(
            $this->phpdocMixedFindings($findings),
            static fn (Finding $finding): bool => $finding->symbol === '$standaloneMixedVar',
        ));
        self::assertSame([], $standaloneMixedVar);
    }

    /**
     * Verify untyped signature mixed doc still fires.
     *
     * @return void No return value.
     */
    public function testUntypedSignatureMixedDocStillFires(): void
    {
        $findings = $this->phpdocMixedFindings($this->analyseFixture());

        $untyped = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->symbol === 'PhpDocMixedOveruseFixture::untypedSignatureMixedDoc()',
        ));

        self::assertCount(1, $untyped, 'PHPDoc-only mixed must flag when the signature is untyped.');
        self::assertSame('param', $untyped[0]->metadata['tagKind'] ?? null);
        self::assertSame('x', $untyped[0]->metadata['paramName'] ?? null);
    }

    /**
     * Verify template generic does not flag.
     *
     * @return void No return value.
     */
    public function testTemplateGenericDoesNotFlag(): void
    {
        $findings = $this->phpdocMixedFindings($this->analyseFixture());

        $template = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->symbol === 'PhpDocMixedOveruseFixture::templateGeneric()',
        ));

        self::assertSame([], $template, '@template T should not be confused with mixed.');
    }

    /**
     * Verify throws only does not flag.
     *
     * @return void No return value.
     */
    public function testThrowsOnlyDoesNotFlag(): void
    {
        $findings = $this->phpdocMixedFindings($this->analyseFixture());

        $throws = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->symbol === 'PhpDocMixedOveruseFixture::throwsOnly()',
        ));

        self::assertSame([], $throws, '@throws is never scanned.');
    }

    /**
     * Verify findings are advisory and modernisation pillar.
     *
     * @return void No return value.
     */
    public function testFindingsAreAdvisoryAndModernisationPillar(): void
    {
        $findings = $this->phpdocMixedFindings($this->analyseFixture());

        self::assertNotEmpty($findings);
        $severityValues  = array_values(array_unique(array_map(static fn ($finding): string => $finding->severity->value, $findings)));
        $pillarValues    = array_values(array_unique(array_map(static fn ($finding): string => $finding->pillar->value, $findings)));
        $missingTagKinds = array_values(array_filter($findings, static fn ($finding): bool => ($finding->metadata['tagKind'] ?? null) === null));
        $missingSnippets = array_values(array_filter($findings, static fn ($finding): bool => ($finding->metadata['snippet'] ?? '') === ''));

        self::assertSame([Severity::Advisory->value], $severityValues);
        self::assertSame([Pillar::Modernisation->value], $pillarValues);
        self::assertSame([], $missingTagKinds);
        self::assertSame([], $missingSnippets);
    }

    /**
     * Verify signature mixed rule still fires independently.
     *
     * @return void No return value.
     */
    public function testSignatureMixedRuleStillFiresIndependently(): void
    {
        $findings = $this->analyseFixture();

        $signatureMixed = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === MixedTypeOveruseRule::ID,
        ));

        $symbols = array_map(static fn (Finding $finding): string => $finding->symbol ?? '', $signatureMixed);

        self::assertContains(
            'standaloneMixedParam()',
            $symbols,
            'modernisation.mixed-type-overuse should still report signature mixed alongside the new PHPDoc rule.',
        );
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function phpdocMixedFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === PhpDocMixedOveruseRule::ID,
        ));
    }

    /**
     * @return list<Finding>
     */
    private function analyseFixture(): array
    {
        $unit     = $this->unitForPath(self::FIXTURE);
        $registry = RuleRegistry::defaults();

        return $registry->analyse(
            [$unit],
            new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)),
        );
    }

    /**
     * Parse the requested path into an analysis unit.
     *
     * @param string $path Filesystem path.
     * @return AnalysisUnit Fixture value.
     */
    private function unitForPath(string $path): AnalysisUnit
    {
        $sourceFile = new SourceFile(self::PROJECT_ROOT . '/' . $path, $path);

        return (new PhpFileParser())->parse($sourceFile);
    }
}
