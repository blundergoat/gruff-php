<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\DeadCode;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\DeadCode\UnusedPrivateMethodRule;
use GruffPhp\Rule\DeadCode\UnusedPrivatePropertyRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class DeadCodeRulesTest extends TestCase
{
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    public function testUnusedPrivateMethodsDetected(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        self::assertCount(2, $findings);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('UnusedPrivateMethodFixture::unusedPrivate()', $symbols);
        self::assertContains('UnusedPrivateMethodFixture::alsoUnused()', $symbols);
    }

    public function testUsedPrivateMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::usedPrivate()', $symbols);
    }

    public function testMagicMethodsExcluded(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::__construct()', $symbols);
    }

    public function testProtectedMethodNotChecked(): void
    {
        $findings = $this->analyseRule('unused-private-method.php', UnusedPrivateMethodRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('UnusedPrivateMethodFixture::protectedMethod()', $symbols);
    }

    public function testUnusedPrivatePropertyDetected(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        self::assertGreaterThanOrEqual(2, count($findings));

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('UnusedPrivatePropertyFixture::$totallyUnused', $symbols);
        self::assertContains('UnusedPrivatePropertyFixture::$neverRead', $symbols);
    }

    public function testUsedPropertyNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('UnusedPrivatePropertyFixture::$usedProp', $symbols);
    }

    public function testPublicPropertyNotChecked(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('UnusedPrivatePropertyFixture::$publicProp', $symbols);
    }

    public function testNeverReadPropertyDistinguished(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $neverRead = array_values(array_filter(
            $findings,
            static fn ($f) => $f->symbol === 'UnusedPrivatePropertyFixture::$neverRead',
        ));

        self::assertCount(1, $neverRead);
        self::assertStringContainsString('written but never read', $neverRead[0]->message);
    }

    public function testPromotedPrivatePropertyDetectedWhenNeverRead(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $neverRead = array_values(array_filter(
            $findings,
            static fn ($f) => $f->symbol === 'PromotedPrivatePropertyFixture::$neverReadPromoted',
        ));

        self::assertCount(1, $neverRead);
        self::assertStringContainsString('written but never read', $neverRead[0]->message);
    }

    public function testUsedPromotedPrivatePropertyNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-private-property.php', UnusedPrivatePropertyRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('PromotedPrivatePropertyFixture::$usedPromoted', $symbols);
        self::assertNotContains('PromotedPrivatePropertyFixture::$publicPromoted', $symbols);
    }

    public function testCleanFileHasNoDeadCodeFindings(): void
    {
        $findings = array_merge(
            $this->analyseRule('clean.php', UnusedPrivateMethodRule::ID),
            $this->analyseRule('clean.php', UnusedPrivatePropertyRule::ID),
        );

        self::assertSame([], $findings);
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
        $path = __DIR__ . '/../../Fixtures/DeadCode/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/DeadCode/' . $filename));
    }
}
