<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Waste;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Waste\CommentedOutCodeRule;
use GruffPhp\Rule\Waste\EmptyClassRule;
use GruffPhp\Rule\Waste\EmptyMethodRule;
use GruffPhp\Rule\Waste\OneLineMethodRule;
use GruffPhp\Rule\Waste\UnreachableCodeRule;
use GruffPhp\Rule\Waste\UnusedImportRule;
use GruffPhp\Rule\Waste\UnusedParameterRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class WasteRulesTest extends TestCase
{
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    public function testUnreachableCodeAfterReturn(): void
    {
        $findings = $this->analyseRule('unreachable.php', UnreachableCodeRule::ID);

        self::assertCount(3, $findings);

        foreach ($findings as $finding) {
            self::assertSame(UnreachableCodeRule::ID, $finding->ruleId);
            self::assertSame(Severity::Warning, $finding->severity);
        }
    }

    public function testNoUnreachableCodeInCleanFile(): void
    {
        $findings = $this->analyseRule('clean.php', UnreachableCodeRule::ID);

        self::assertSame([], $findings);
    }

    public function testEmptyMethodDetected(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        self::assertNotSame([], $findings);

        foreach ($findings as $finding) {
            self::assertSame(EmptyMethodRule::ID, $finding->ruleId);
            self::assertSame(Severity::Advisory, $finding->severity);
        }
    }

    public function testAbstractMethodNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('AbstractFixture::abstractMethod()', $symbols);
    }

    public function testPromotedConstructorNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('PromotedConstructorFixture::__construct()', $symbols);
    }

    public function testEmptyClassDetected(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyClassRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('EmptyClassFixture', $findings[0]->symbol);
    }

    public function testEmptyExceptionMarkerNotFlaggedAsEmptyClass(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyClassRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('EmptyExceptionFixture', $symbols);
    }

    public function testUnusedImportsDetected(): void
    {
        $findings = $this->analyseRule('unused-imports.php', UnusedImportRule::ID);

        self::assertCount(2, $findings);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('InvalidArgumentException', $symbols);
        self::assertContains('LogicException', $symbols);
    }

    public function testUsedImportNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-imports.php', UnusedImportRule::ID);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('RuntimeException', $symbols);
    }

    public function testUnusedParameterInPrivateMethod(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $findings);
        self::assertContains('unused', $params);
    }

    public function testUsedParameterNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $findings);
        self::assertNotContains('used', $params);
        self::assertNotContains('a', $params);
        self::assertNotContains('b', $params);
    }

    public function testUnusedParameterInPublicMethodWithoutExternalContract(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $reported = [];
        foreach ($findings as $finding) {
            self::assertNotNull($finding->symbol);
            $parameter = $finding->metadata['parameter'] ?? null;
            if (!is_string($parameter)) {
                continue;
            }

            $reported[$finding->symbol . ':' . $parameter] = true;
        }

        self::assertArrayHasKey('UnusedParameterFixture::publicMethod():detailed', $reported);
    }

    public function testPublicMethodParametersWithExternalContractsAreNotChecked(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $paramsBySymbol = [];
        foreach ($findings as $finding) {
            self::assertNotNull($finding->symbol);
            $paramsBySymbol[$finding->symbol][] = $finding->metadata['parameter'] ?? null;
        }

        self::assertArrayNotHasKey('InheritedParameterFixture::hook()', $paramsBySymbol);
        self::assertArrayNotHasKey('ContractParameterFixture::handle()', $paramsBySymbol);
    }

    public function testPromotedPrivateConstructorParametersAreUsedAsProperties(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $params = array_map(static fn ($f) => $f->metadata['parameter'] ?? null, $findings);
        self::assertNotContains('promoted', $params);
    }

    public function testCommentedOutCodeDetected(): void
    {
        $findings = $this->analyseRule('commented-out-code.php', CommentedOutCodeRule::ID);

        self::assertNotSame([], $findings);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
    }

    public function testOneLineCallWrapperMethodsAreDetected(): void
    {
        $findings = $this->analyseRule('one-line-methods.php', OneLineMethodRule::ID);
        $symbols = array_map(static fn ($finding): ?string => $finding->symbol, $findings);

        self::assertContains('OneLineMethodFixture::isEligible()', $symbols);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
        self::assertSame('return', $findings[0]->metadata['statementKind']);
    }

    public function testOneLineMethodRuleSkipsPureExpressionsAndNoArgumentAccessors(): void
    {
        $findings = $this->analyseRule('one-line-methods.php', OneLineMethodRule::ID);
        $symbols = array_map(static fn ($finding): ?string => $finding->symbol, $findings);

        self::assertNotContains('OneLineMethodFixture::formatGreeting()', $symbols);
        self::assertNotContains('OneLineMethodFixture::getName()', $symbols);
        self::assertNotContains('OneLineMethodFixture::testItUsesFixture()', $symbols);
    }

    public function testCleanFileHasNoWasteFindings(): void
    {
        $unit = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $wasteFindings = array_filter($findings, static fn ($f) => str_starts_with($f->ruleId, 'waste.') || str_starts_with($f->ruleId, 'dead-code.'));
        self::assertSame([], array_values($wasteFindings));
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
