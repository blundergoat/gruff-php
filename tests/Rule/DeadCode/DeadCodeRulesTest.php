<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\DeadCode;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rule\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers dead-code detection: unused/used private methods (with magic and protected exclusions) and unused/used/never-read private properties
 * (including promoted constructor properties).
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
     * Verify clean file has no dead code findings.
     *
     * @return void
     */
    public function testCleanFileHasNoDeadCodeFindings(): void
    {
        $findings = array_merge(
            $this->analyseRule('clean.php', UnusedPrivateMethodRule::ID),
            $this->analyseRule('clean.php', UnusedPrivatePropertyRule::ID),
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
     * @return list<\GruffPhp\Finding\Finding> - findings from $ruleId alone, re-indexed for positional assertions.
     */
    private function analyseRule(string $fixture, string $ruleId): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        // Keep only the requested rule so one fixture can be asserted against each dead-code rule independently.
        return array_values(array_filter($findings, static fn($finding) => $finding->ruleId === $ruleId));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     *
     * @return \GruffPhp\Parser\AnalysisUnit - parsed fixture carrying the repo-relative display path the rules report findings against
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/DeadCode/' . $filename;

        // Parsed unit carries the display path the rules report findings against, so keep it relative to the repo root.
        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/DeadCode/' . $filename));
    }
}
