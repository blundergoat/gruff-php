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

        self::assertCount(1, $findings);
        self::assertSame('MissingPhpdocFixture::undocumented()', $findings[0]->symbol);
    }

    public function testDocumentedMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('MissingPhpdocFixture::documented()', $symbols);
    }

    public function testGetterSetterExemptFromPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('MissingPhpdocFixture::getTitle()', $symbols);
        self::assertNotContains('MissingPhpdocFixture::setTitle()', $symbols);
        self::assertNotContains('MissingPhpdocFixture::isActive()', $symbols);
    }

    public function testPrivateMethodExemptFromPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('MissingPhpdocFixture::privateMethod()', $symbols);
    }

    public function testMagicMethodExemptFromPhpdoc(): void
    {
        $findings = $this->analyseRule('missing-phpdoc.php', MissingPublicPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('MissingPhpdocFixture::__toString()', $symbols);
    }

    public function testMissingParamTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $findings);
        self::assertContains('x', $params);
    }

    public function testCompleteParamTagNotFlagged(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingParamTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('PhpdocTagsFixture::complete()', $symbols);
    }

    public function testMissingReturnTagDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', MissingReturnTagRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::missingReturn()', $symbols);
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

    public function testUselessPhpdocDetected(): void
    {
        $findings = $this->analyseRule('phpdoc-tags.php', UselessPhpdocRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('PhpdocTagsFixture::uselessDoc()', $symbols);
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
        $path = __DIR__ . '/../../Fixtures/M09/Docs/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/M09/Docs/' . $filename));
    }
}
