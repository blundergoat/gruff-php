<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Naming\BooleanPrefixRule;
use GruffPhp\Rule\Naming\ClassFileMismatchRule;
use GruffPhp\Rule\Naming\ConfusingNameRule;
use GruffPhp\Rule\Naming\GenericMethodNameRule;
use GruffPhp\Rule\Naming\HungarianNotationRule;
use GruffPhp\Rule\Naming\ShortVariableRule;
use GruffPhp\Rule\Naming\TestNamingConsistencyRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class NamingRulesTest extends TestCase
{
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    public function testGenericMethodNamesDetected(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        self::assertCount(6, $findings);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('GenericMethodFixture::process()', $symbols);
        self::assertContains('GenericMethodFixture::handle()', $symbols);
        self::assertContains('GenericMethodFixture::execute()', $symbols);
    }

    public function testQualifiedMethodNamesNotFlagged(): void
    {
        $findings = $this->analyseRule('generic-method.php', GenericMethodNameRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('GenericMethodFixture::processPayment()', $symbols);
        self::assertNotContains('GenericMethodFixture::handleRequest()', $symbols);
        self::assertNotContains('GenericMethodFixture::calculateTotal()', $symbols);
    }

    public function testSingleCharVariablesDetected(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertContains('x', $vars);
        self::assertContains('a', $vars);
    }

    public function testLoopCountersExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertNotContains('i', $vars);
        self::assertNotContains('j', $vars);
    }

    public function testAcceptedAbbreviationsCanSuppressShortVariableFindings(): void
    {
        $unit = $this->parseFixture('short-variable.php');
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)->withAcceptedAbbreviations(['a']);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $shortVariableFindings = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->ruleId === ShortVariableRule::ID,
        ));

        $vars = array_map(static fn ($finding): mixed => $finding->metadata['variable'] ?? null, $shortVariableFindings);
        self::assertContains('x', $vars);
        self::assertNotContains('a', $vars);
    }

    public function testCatchVariableExcluded(): void
    {
        $findings = $this->analyseRule('short-variable.php', ShortVariableRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertNotContains('e', $vars);
    }

    public function testBooleanPrefixMissing(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        self::assertNotSame([], $findings);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('BooleanPrefixFixture::active()', $symbols);
        self::assertContains('BooleanPrefixFixture::enabled()', $symbols);
        self::assertContains('BooleanPrefixFixture::check()', $symbols);
    }

    public function testGoodBooleanPrefixNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('BooleanPrefixFixture::isActive()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::hasPermission()', $symbols);
        self::assertNotContains('BooleanPrefixFixture::canEdit()', $symbols);
    }

    public function testNonBoolMethodNotFlagged(): void
    {
        $findings = $this->analyseRule('boolean-prefix.php', BooleanPrefixRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('BooleanPrefixFixture::getName()', $symbols);
    }

    public function testHungarianNotationDetected(): void
    {
        $findings = $this->analyseRule('hungarian.php', HungarianNotationRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertContains('strName', $vars);
        self::assertContains('arrItems', $vars);
        self::assertContains('intCount', $vars);
        self::assertContains('boolReady', $vars);
        self::assertContains('objUser', $vars);
    }

    public function testCleanVariablesNotFlaggedAsHungarian(): void
    {
        $findings = $this->analyseRule('hungarian.php', HungarianNotationRule::ID);

        $vars = array_map(static fn ($f) => $f->metadata['variable'] ?? null, $findings);
        self::assertNotContains('name', $vars);
        self::assertNotContains('items', $vars);
        self::assertNotContains('strategy', $vars);
    }

    public function testConfusingStandaloneClassNames(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('Helper', $symbols);
        self::assertContains('Util', $symbols);
    }

    public function testDomainSpecificClassNamesNotFlagged(): void
    {
        $findings = $this->analyseRule('confusing-class.php', ConfusingNameRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('UserService', $symbols);
        self::assertNotContains('OrderRepository', $symbols);
    }

    public function testMixedTestNamingDetected(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('MixedTestNamingFixture', $findings[0]->symbol);
    }

    public function testConsistentTestNamingNotFlagged(): void
    {
        $findings = $this->analyseRule('test-naming.php', TestNamingConsistencyRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('ConsistentTestNamingFixture', $symbols);
    }

    public function testClassFileMismatch(): void
    {
        $findings = $this->analyseRule('clean.php', ClassFileMismatchRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('CleanNamingFixture', $findings[0]->symbol);
    }

    public function testCleanFixtureHasNoNamingFindingsExceptFileMismatch(): void
    {
        $unit = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $namingFindings = array_filter(
            $findings,
            static fn ($f) => str_starts_with($f->ruleId, 'naming.') && $f->ruleId !== ClassFileMismatchRule::ID,
        );
        self::assertSame([], array_values($namingFindings));
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
        $path = __DIR__ . '/../../Fixtures/M08/Naming/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/M08/Naming/' . $filename));
    }
}
