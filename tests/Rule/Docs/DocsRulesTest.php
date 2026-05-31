<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Docs;

use GruffPhp\Finding\Severity;
use GruffPhp\Rule\Docs\MissingParamTagRule;
use GruffPhp\Rule\Docs\MissingPublicPhpdocRule;
use GruffPhp\Rule\Docs\MissingReturnTagRule;
use GruffPhp\Rule\Docs\ReturnCommentRule;

/**
 * Covers documentation rule enforcement: missing PHPDoc on public/accessor/private/magic/interface-contract methods, and missing param and return tags across array, descriptive, void, and scalar-throws cases.
 */
final class DocsRulesTest extends DocsRuleTestCase
{
    /**
     * Verify missing public phpdoc detected.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
     */
    public function testInternalHelpersAndConventionalReportersRequirePhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('InternalHelper::complexUtility()', $symbols);
        self::assertContains('TextReporter::render()', $symbols);
    }

    /**
     * Verify missing param tag recognises multi-line `@param array{...} $payload` shapes whose
     * closing `$varname` sits on a different physical line than the `@param` token.
     *
     * @return void
     */
    public function testMissingParamTagRecognisesMultiLineArrayShape(): void
    {
        $findings = $this->analyseRule('missing-param-tag-multi-line-array-shape.php', MissingParamTagRule::ID);

        $reported = [];
        foreach ($findings as $finding) {
            $symbol    = $finding->symbol ?? '';
            $parameter = $finding->metadata['parameter'] ?? null;
            if (is_string($parameter)) {
                $reported[] = sprintf('%s|%s', $symbol, $parameter);
            }
        }

        self::assertNotContains('MultiLineArrayShapeFixture::publishToolUse()|topic', $reported);
        self::assertNotContains('MultiLineArrayShapeFixture::publishToolUse()|payload', $reported);
        self::assertContains('MultiLineArrayShapeFixture::publishTurnWithMalformedDoc()|payload', $reported);
    }

    /**
     * Verify missing param tag detected for array parameters.
     *
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
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
     * @return void
     */
    public function testVoidMethodWithDocblockTriggersMissingReturnTag(): void
    {
        // Policy lock: per .goat-flow/lessons/workflow.md "Respect explicit rule style
        // even when it restates native syntax", every documented method without @return
        // must fire - including methods declared void or never. Skipping void was an
        // unintended narrowing; explicit void/never fixtures lock the broader contract
        // so a future agent cannot silently re-narrow it.
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::voidWithDocblock()', $symbols);
        self::assertContains('PhpdocTagsFixture::neverWithDocblock()', $symbols);
    }

    /**
     * Verify a return statement without a direct one-line comment is flagged advisory.
     *
     * @return void
     */
    public function testReturnRequiresDirectOneLineComment(): void
    {
        $findings = $this->analyseRule('control-flow-comments.php', ReturnCommentRule::ID);
        $lines    = array_map(static fn ($finding): ?int => $finding->line, $findings);

        self::assertSame([35, 46], $lines);

        foreach ($findings as $finding) {
            self::assertSame(Severity::Advisory, $finding->severity, 'return-comment findings are advisory');
        }
    }
}
