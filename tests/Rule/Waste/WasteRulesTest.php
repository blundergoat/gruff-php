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
 */
final class WasteRulesTest extends TestCase
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
     * Verify unreachable code after return.
     *
     * @return void
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
     * Verify no unreachable code in clean file.
     *
     * @return void
     */
    public function testNoUnreachableCodeInCleanFile(): void
    {
        $findings = $this->analyseRule('clean.php', UnreachableCodeRule::ID);

        self::assertSame([], $findings);
    }

    /**
     * Verify empty method detected.
     *
     * @return void
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
     * Verify abstract method not flagged as empty.
     *
     * @return void
     */
    public function testAbstractMethodNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('AbstractFixture::abstractMethod()', $symbols);
    }

    /**
     * Verify promoted constructor not flagged as empty.
     *
     * @return void
     */
    public function testPromotedConstructorNotFlaggedAsEmpty(): void
    {
        $findings = $this->analyseRule('empty-members.php', EmptyMethodRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('PromotedConstructorFixture::__construct()', $symbols);
    }

    /**
     * Verify unused imports detected.
     *
     * @return void
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
     * Verify used import not flagged.
     *
     * @return void
     */
    public function testUsedImportNotFlagged(): void
    {
        $findings = $this->analyseRule('unused-imports.php', UnusedImportRule::ID);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains('RuntimeException', $symbols);
    }

    /**
     * Verify commented out code detected.
     *
     * @return void
     */
    public function testCommentedOutCodeDetected(): void
    {
        $findings = $this->analyseRule('commented-out-code.php', CommentedOutCodeRule::ID);

        self::assertNotSame([], $findings);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
    }

    /**
     * Verify prose and placeholders (lines 11-15) and restatements of the assignment below (21, 23) stay quiet.
     *
     * Every other comment is disabled code or a look-alike restatement that must still report, such as an earlier edit of the line below.
     *
     * @return void
     */
    public function testCommentedOutCodeSkipsProseAndRestatements(): void
    {
        $findings = $this->analyseRule('commented-out-code-precision.php', CommentedOutCodeRule::ID);

        self::assertSame([31, 32, 37, 39, 41, 43, 45, 47, 49, 50, 52], array_map(static fn($finding): ?int => $finding->line, $findings));
    }

    /**
     * Verify clean file has no waste findings.
     *
     * @return void
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
     * @return list<\GruffPhp\Results\Finding\Finding> - findings emitted only by the requested rule, in analyser order; empty when that rule produced none
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
     * @param string $filename - Fixture filename
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - Hand back the dead-code fixture parsed from disk, with its project-relative display path attached
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/DeadCode/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/DeadCode/' . $filename));
    }
}
