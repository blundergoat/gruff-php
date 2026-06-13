<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\DeadCode;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\DeadCode\UnusedPrivateConstantRule;
use GruffPhp\Rules\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rules\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers dead-code detection: unused/used private methods (with magic and protected exclusions), unused/used/never-read private properties
 * (including promoted constructor properties), and unused private constants across class-like scopes.
 */
final class DeadCodeRulesTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify unused private methods detected.
     *
     * @return void
     */
    public function testUnusedPrivateMethodsDetected(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        self::assertCount(2, $findings);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('UnusedPrivateMethodFixture::unusedPrivate()', $symbols);
        self::assertContains('UnusedPrivateMethodFixture::alsoUnused()', $symbols);
    }

    /**
     * Verify used private method not flagged.
     *
     * @return void
     */
    public function testUsedPrivateMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::usedPrivate()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::comparePromptRowsByLabel()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::comparePromptRowsByScore()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::normalisePromptRow()', $symbols);
        self::assertNotContains('UnusedPrivateMethodFixture::formatPromptRow()', $symbols);
    }

    /**
     * Verify magic methods excluded.
     *
     * @return void
     */
    public function testMagicMethodsExcluded(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::__construct()', $symbols);
    }

    /**
     * Verify protected method not checked.
     *
     * @return void
     */
    public function testProtectedMethodNotChecked(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::protectedMethod()', $symbols);
    }

    /**
     * Verify unused private property detected.
     *
     * @return void
     */
    public function testUnusedPrivatePropertyDetected(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        self::assertGreaterThanOrEqual(2, count($findings));

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('UnusedPrivatePropertyFixture::$totallyUnused', $symbols);
        self::assertContains('UnusedPrivatePropertyFixture::$neverRead', $symbols);
    }

    /**
     * Verify used property not flagged.
     *
     * @return void
     */
    public function testUsedPropertyNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivatePropertyFixture::$usedProp', $symbols);
    }

    /**
     * Verify public property not checked.
     *
     * @return void
     */
    public function testPublicPropertyNotChecked(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivatePropertyFixture::$publicProp', $symbols);
    }

    /**
     * Verify never read property distinguished.
     *
     * @return void
     */
    public function testNeverReadPropertyDistinguished(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $neverRead = array_values(array_filter(
                                      $findings,
                                      static fn($finding) => $finding->symbol === 'UnusedPrivatePropertyFixture::$neverRead',
                                  ));

        self::assertCount(1, $neverRead);
        self::assertStringContainsString('written but never read', $neverRead[0]->message);
    }

    /**
     * Verify promoted private property detected when never read.
     *
     * @return void
     */
    public function testPromotedPrivatePropertyDetectedWhenNeverRead(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $neverRead = array_values(array_filter(
                                      $findings,
                                      static fn($finding) => $finding->symbol === 'PromotedPrivatePropertyFixture::$neverReadPromoted',
                                  ));

        self::assertCount(1, $neverRead);
        self::assertStringContainsString('written but never read', $neverRead[0]->message);
    }

    /**
     * Verify used promoted private property not flagged.
     *
     * @return void
     */
    public function testUsedPromotedPrivatePropertyNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('PromotedPrivatePropertyFixture::$usedPromoted', $symbols);
        self::assertNotContains('PromotedPrivatePropertyFixture::$publicPromoted', $symbols);
    }

    /**
     * Verify unused private constants detected across class, trait, and enum scopes.
     *
     * @return void
     */
    public function testUnusedPrivateConstantsDetected(): void
    {
        $findings = $this->analyseRule('unused-private-constant.php', UnusedPrivateConstantRule::ID);

        self::assertCount(3, $findings);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('UnusedPrivateConstantFixture::UNUSED', $symbols);
        self::assertContains('PrivateConstantTraitFixture::UNUSED_TRAIT', $symbols);
        self::assertContains('PrivateConstantEnumFixture::UNUSED_ENUM', $symbols);
    }

    /**
     * Verify literal private constant reads are not flagged.
     *
     * @return void
     */
    public function testUsedPrivateConstantsNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-constant.php', UnusedPrivateConstantRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_SELF', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_STATIC', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_THIS', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_BY_OWN_NAME', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_IN_DEFAULT', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::USED_IN_ARRAY_DEFAULT', $symbols);
        self::assertNotContains('PrivateConstantTraitFixture::USED_TRAIT', $symbols);
        self::assertNotContains('PrivateConstantEnumFixture::USED_ENUM', $symbols);
    }

    /**
     * Verify non-private constants and dynamic-name scopes are not flagged.
     *
     * @return void
     */
    public function testNonPrivateAndDynamicConstantsNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-constant.php', UnusedPrivateConstantRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('UnusedPrivateConstantFixture::PROTECTED_CONSTANT', $symbols);
        self::assertNotContains('UnusedPrivateConstantFixture::PUBLIC_CONSTANT', $symbols);
        self::assertNotContains('DynamicPrivateConstantFixture::MAYBE_DYNAMIC', $symbols);
        self::assertNotContains('InheritedConstantParentFixture::INHERITED_PROTECTED', $symbols);
        self::assertNotContains('InheritedConstantParentFixture::INHERITED_PUBLIC', $symbols);
    }

    /**
     * Verify clean file has no dead code findings.
     *
     * @return void
     */
    public function testCleanFileHasNoDeadCodeFindings(): void
    {
        $findings = array_merge(
            $this->analyseRule('clean.php', UnusedPrivateMethodRule::ID),
            $this->analyseRule('clean.php', UnusedPrivatePropertyRule::ID),
            $this->analyseRule('clean.php', UnusedPrivateConstantRule::ID),
        );

        self::assertSame([], $findings);
    }

    /**
     * Analyse dead-code fixtures and return findings for assertions.
     *
     * @param string $fixture - fixture filename under Fixtures/DeadCode to parse and run the full registry against.
     * @param string $ruleId  - rule id to isolate; the full pass runs but only this rule's findings come back, so a
     *                        fixture exercising several dead-code shapes can be asserted one rule at a time.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - findings from $ruleId alone, re-indexed for positional assertions.
     */
    private function analyseRule(string $fixture, string $ruleId): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter($findings, static fn($finding) => $finding->ruleId === $ruleId));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Fixture filename.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture carrying the repo-relative display path the rules report findings against
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/DeadCode/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/DeadCode/' . $filename));
    }
}
