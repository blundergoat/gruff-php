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
use GruffPhp\Rule\Waste\RedundantVariableRule;
use GruffPhp\Rule\Waste\UnreachableCodeRule;
use GruffPhp\Rule\Waste\UnusedImportRule;
use GruffPhp\Rule\Waste\UnusedParameterRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers WasteRulesTest behavior.
 */
final class WasteRulesTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify unreachable code after return.
     *
     * @return void No return value.
     */
    public function testUnreachableCodeAfterReturn(): void
    {
        $findings = $this->analyseRule('unreachable.php', UnreachableCodeRule::ID);

        self::assertCount(3, $findings);

        $ruleIds = array_values(array_unique(array_map(static fn ($finding): string => $finding->ruleId, $findings)));
        $severityValues = array_values(array_unique(array_map(static fn ($finding): string => $finding->severity->value, $findings)));

        self::assertSame([UnreachableCodeRule::ID], $ruleIds);
        self::assertSame([Severity::Warning->value], $severityValues);
    }

    /**
     * Verify no unreachable code in clean file.
     *
     * @return void No return value.
     */
    public function testNoUnreachableCodeInCleanFile(): void
    {
        $findings = $this->analyseRule('clean.php', UnreachableCodeRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Verify empty method detected.
     *
     * @return void No return value.
     */
    public function testEmptyMethodDetected(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        self::assertNotSame([], $findings);

        $ruleIds = array_values(array_unique(array_map(static fn ($finding): string => $finding->ruleId, $findings)));
        $severityValues = array_values(array_unique(array_map(static fn ($finding): string => $finding->severity->value, $findings)));

        self::assertSame([EmptyMethodRule::ID], $ruleIds);
        self::assertSame([Severity::Advisory->value], $severityValues);
    }

    /**
     * Verify abstract method not flagged as empty.
     *
     * @return void No return value.
     */
    public function testAbstractMethodNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('AbstractFixture::abstractMethod()', $symbols);
    }

    /**
     * Verify promoted constructor not flagged as empty.
     *
     * @return void No return value.
     */
    public function testPromotedConstructorNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('PromotedConstructorFixture::__construct()', $symbols);
    }

    /**
     * Verify empty class detected.
     *
     * @return void No return value.
     */
    public function testEmptyClassDetected(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyClassRule::ID);

        self::assertCount(1, $findings);
        self::assertSame('EmptyClassFixture', $findings[0]->symbol);
    }

    /**
     * Verify empty exception marker not flagged as empty class.
     *
     * @return void No return value.
     */
    public function testEmptyExceptionMarkerNotFlaggedAsEmptyClass(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyClassRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('EmptyExceptionFixture', $symbols);
    }

    /**
     * Verify unused imports detected.
     *
     * @return void No return value.
     */
    public function testUnusedImportsDetected(): void
    {
        $findings = $this->analyseRule('unused-imports.php', UnusedImportRule::ID);

        self::assertCount(2, $findings);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('InvalidArgumentException', $symbols);
        self::assertContains('LogicException', $symbols);
    }

    /**
     * Verify used import not flagged.
     *
     * @return void No return value.
     */
    public function testUsedImportNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-imports.php', UnusedImportRule::ID);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains('RuntimeException', $symbols);
    }

    /**
     * Verify unused parameter in private method.
     *
     * @return void No return value.
     */
    public function testUnusedParameterInPrivateMethod(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $params = array_map(static fn ($finding) => $finding->metadata['parameter'] ?? null, $findings);
        self::assertContains('unused', $params);
    }

    /**
     * Verify used parameter not flagged.
     *
     * @return void No return value.
     */
    public function testUsedParameterNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $params = array_map(static fn ($finding) => $finding->metadata['parameter'] ?? null, $findings);
        self::assertNotContains('used', $params);
        self::assertNotContains('a', $params);
        self::assertNotContains('b', $params);
    }

    /**
     * Verify unused parameter in public method without external contract.
     *
     * @return void No return value.
     */
    public function testUnusedParameterInPublicMethodWithoutExternalContract(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $reported = array_values(array_filter(
            array_map(
                static fn ($finding): ?string => $finding->symbol !== null && is_string($finding->metadata['parameter'] ?? null)
                    ? $finding->symbol . ':' . $finding->metadata['parameter']
                    : null,
                $findings,
            ),
            'is_string',
        ));

        self::assertContains('UnusedParameterFixture::publicMethod():detailed', $reported);
    }

    /**
     * Verify public method parameters with external contracts are not checked.
     *
     * @return void No return value.
     */
    public function testPublicMethodParametersWithExternalContractsAreNotChecked(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $paramsBySymbol = [];
        foreach ($findings as $finding) {
            $paramsBySymbol[$finding->symbol ?? ''][] = $finding->metadata['parameter'] ?? null;
        }

        self::assertArrayNotHasKey('InheritedParameterFixture::hook()', $paramsBySymbol);
        self::assertArrayNotHasKey('ContractParameterFixture::handle()', $paramsBySymbol);
    }

    /**
     * Verify promoted private constructor parameters are used as properties.
     *
     * @return void No return value.
     */
    public function testPromotedPrivateConstructorParametersAreUsedAsProperties(): void
    {
        $findings = $this->analyseRule('unused-parameter.php', UnusedParameterRule::ID);

        $params = array_map(static fn ($finding) => $finding->metadata['parameter'] ?? null, $findings);
        self::assertNotContains('promoted', $params);
    }

    /**
     * Verify commented out code detected.
     *
     * @return void No return value.
     */
    public function testCommentedOutCodeDetected(): void
    {
        $findings = $this->analyseRule('commented-out-code.php', CommentedOutCodeRule::ID);

        self::assertNotSame([], $findings);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
    }

    /**
     * Verify one line call wrapper methods are detected.
     *
     * @return void No return value.
     */
    public function testOneLineCallWrapperMethodsAreDetected(): void
    {
        $findings = $this->analyseRule('one-line-methods.php', OneLineMethodRule::ID);
        $symbols  = array_map(static fn ($finding): ?string => $finding->symbol, $findings);

        self::assertContains('OneLineMethodFixture::isEligible()', $symbols);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
        self::assertSame('return', $findings[0]->metadata['statementKind']);
    }

    /**
     * Verify one line method rule skips pure expressions and no argument accessors.
     *
     * @return void No return value.
     */
    public function testOneLineMethodRuleSkipsPureExpressionsAndNoArgumentAccessors(): void
    {
        $findings = $this->analyseRule('one-line-methods.php', OneLineMethodRule::ID);
        $symbols  = array_map(static fn ($finding): ?string => $finding->symbol, $findings);

        self::assertNotContains('OneLineMethodFixture::formatGreeting()', $symbols);
        self::assertNotContains('OneLineMethodFixture::getName()', $symbols);
        self::assertNotContains('OneLineMethodFixture::testItUsesFixture()', $symbols);
    }

    /**
     * Verify redundant variable before return detected.
     *
     * @return void No return value.
     */
    public function testRedundantVariableBeforeReturnDetected(): void
    {
        $findings  = $this->analyseRule('redundant-variable.php', RedundantVariableRule::ID);
        $variables = array_map(static fn ($finding): mixed => $finding->metadata['variable'] ?? null, $findings);

        self::assertSame(['result', 'branchResult'], $variables);
    }

    /**
     * Verify clean file has no waste findings.
     *
     * @return void No return value.
     */
    public function testCleanFileHasNoWasteFindings(): void
    {
        $unit     = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $wasteFindings = array_filter($findings, static fn ($finding) => str_starts_with($finding->ruleId, 'waste.') || str_starts_with($finding->ruleId, 'dead-code.'));
        self::assertSame([], array_values($wasteFindings));
    }

    /**
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyseRule(string $fixture, string $ruleId): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        return array_values(array_filter($findings, static fn ($finding) => $finding->ruleId === $ruleId));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return \GruffPhp\Parser\AnalysisUnit Fixture value.
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/DeadCode/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/DeadCode/' . $filename));
    }
}
