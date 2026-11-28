<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Waste;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Waste\CommentedOutCodeRule;
use GruffPhp\Rules\Waste\EmptyMethodRule;
use GruffPhp\Rules\Waste\UnreachableCodeRule;
use GruffPhp\Rules\Waste\UnusedImportRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers waste detection: unreachable code, empty methods, unused imports, and commented-out code.
 * Checks rule findings and exclusions using parsed dead-code fixtures.
 * Also asserts that the clean fixture produces no waste or dead-code findings.
 */
final class WasteRulesTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Initialize the parser used to read rule fixtures.
     *
     * @return void - Leaves each rule-fixture test with a fresh parser.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Check the unreachable-code fixture's finding count, rule IDs, and warning severity.
     *
     * @return void - Asserts three warning findings, all attributed to UnreachableCodeRule.
     */
    public function testUnreachableCodeAfterReturn(): void
    {
        $findings = $this->analyseRule('unreachable.php', UnreachableCodeRule::ID);

        self::assertCount(3, $findings);

        $ruleIds        = array_values(array_unique(array_map(static fn($finding): string => $finding->ruleId, $findings)));
        $severityValues = array_values(array_unique(array_map(static fn($finding): string => $finding->severity->value, $findings)));

        self::assertSame([UnreachableCodeRule::ID], $ruleIds);
        self::assertSame([Severity::Warning->value], $severityValues);
    }

    /**
     * Check that the unreachable-code rule reports no findings for the clean fixture.
     *
     * @return void - Asserts an empty findings list for UnreachableCodeRule.
     */
    public function testNoUnreachableCodeInCleanFile(): void
    {
        $findings = $this->analyseRule('clean.php', UnreachableCodeRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Check that the empty-method rule emits advisory findings for the empty-members fixture.
     *
     * @return void - Asserts a nonempty result containing only EmptyMethodRule advisory findings.
     */
    public function testEmptyMethodDetected(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        self::assertNotSame([], $findings);

        $ruleIds        = array_values(array_unique(array_map(static fn($finding): string => $finding->ruleId, $findings)));
        $severityValues = array_values(array_unique(array_map(static fn($finding): string => $finding->severity->value, $findings)));

        self::assertSame([EmptyMethodRule::ID], $ruleIds);
        self::assertSame([Severity::Advisory->value], $severityValues);
    }

    /**
     * Checks that abstract declarations are excluded from empty-method findings.
     *
     * @return void - Asserts that AbstractFixture::abstractMethod() is absent from the finding symbols.
     */
    public function testAbstractMethodNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('AbstractFixture::abstractMethod()', $symbols);
    }

    /**
     * Checks that constructors with promoted properties are excluded from empty-method findings.
     *
     * @return void - Asserts that PromotedConstructorFixture::__construct() is absent from the finding symbols.
     */
    public function testPromotedConstructorNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('PromotedConstructorFixture::__construct()', $symbols);
    }

    /**
     * Checks that the fixture reports exactly InvalidArgumentException and LogicException as unused.
     *
     * @return void - Asserts two findings naming InvalidArgumentException and LogicException.
     */
    public function testUnusedImportsDetected(): void
    {
        $findings = $this->analyseRule('unused-imports.php', UnusedImportRule::ID);

        self::assertCount(2, $findings);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('InvalidArgumentException', $symbols);
        self::assertContains('LogicException', $symbols);
    }

    /**
     * Checks that a referenced RuntimeException import is absent from unused-import findings.
     *
     * @return void - Asserts that RuntimeException is absent from the unused-import finding symbols.
     */
    public function testUsedImportNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-imports.php', UnusedImportRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('RuntimeException', $symbols);
    }

    /**
     * Checks that the fixture yields commented-out-code findings whose first severity is advisory.
     *
     * @return void - Asserts nonempty CommentedOutCodeRule findings with an advisory first finding.
     */
    public function testCommentedOutCodeDetected(): void
    {
        $findings = $this->analyseRule('commented-out-code.php', CommentedOutCodeRule::ID);

        self::assertNotSame([], $findings);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
    }

    /**
     * Defines the precision fixture's reportable comment set by exact source line.
     *
     * @return void - Asserts the exact finding lines for the commented-out-code-precision fixture.
     */
    public function testCommentedOutCodeSkipsProseAndRestatements(): void
    {
        $findings = $this->analyseRule('commented-out-code-precision.php', CommentedOutCodeRule::ID);

        self::assertSame([31, 32, 37, 39, 41, 43, 45, 47, 49, 50, 52], array_map(static fn($finding): ?int => $finding->line, $findings));
    }

    /**
     * Checks that the clean fixture emits no findings from the waste or dead-code rule families.
     *
     * @return void - Asserts an empty result after filtering for waste and dead-code rule ID prefixes.
     */
    public function testCleanFileHasNoWasteFindings(): void
    {
        $unit     = $this->parseFixture('clean.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry);
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));

        $wasteFindings = array_filter($findings, static fn($finding) => str_starts_with($finding->ruleId, 'waste.') || str_starts_with($finding->ruleId, 'dead-code.'));
        self::assertSame([], array_values($wasteFindings));
    }

    /**
     * Analyse waste-rule fixtures and return findings for assertions.
     *
     * @param string              $fixture - Fixture filename under the dead-code fixtures directory.
     * @param string              $ruleId - Rule identifier to keep; all other findings are discarded.
     * @param AnalysisConfig|null $config - Overriding config, or null to use the registry defaults.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Findings for the requested rule in analyser order; empty if none match.
     */
    private function analyseRule(string $fixture, string $ruleId, ?AnalysisConfig $config = null): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config ?? AnalysisConfig::fromRegistry($registry)));

        return array_values(array_filter($findings, static fn($finding) => $finding->ruleId === $ruleId));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Filename relative to tests/Fixtures/DeadCode.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - Analysis unit with the fixture's display path and any read or parse diagnostics.
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/DeadCode/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/DeadCode/' . $filename));
    }
}
