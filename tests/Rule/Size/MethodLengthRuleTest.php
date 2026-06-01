<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\MethodLengthRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers method-length thresholds (warning/error), multiline-call collapsing, closure-as-method counting, and disabled-rule suppression.
 */
final class MethodLengthRuleTest extends TestCase
{
    /** Rule instance under test. */
    private MethodLengthRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule   = new MethodLengthRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for short methods.
     *
     * @return void
     */
    public function testNoFindingsForShortMethods(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 30, 'error' => 60]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for method above warning threshold.
     *
     * @return void
     */
    public function testWarningForMethodAboveWarningThreshold(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 30, 'error' => 60]);

        self::assertCount(1, $findings);
        self::assertSame(MethodLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('LongMethodFixture::warningMethod()', $findings[0]->symbol);
        self::assertSame(31, $findings[0]->metadata['lines']);
    }

    /**
     * Verify error for method above error threshold.
     *
     * @return void
     */
    public function testErrorForMethodAboveErrorThreshold(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 3, 'error' => 10]);

        self::assertCount(1, $findings);

        $warning = $findings[0];
        self::assertSame(Severity::Error, $warning->severity);
        self::assertSame('LongMethodFixture::warningMethod()', $warning->symbol);
    }

    /**
     * Verify multiline call counts as one logical line.
     *
     * @return void
     */
    public function testMultilineCallCountsAsOneLogicalLine(): void
    {
        $findings = $this->analyse('logical-method.php', ['warning' => 3, 'error' => 60]);

        self::assertSame([], $findings);
    }

    /**
     * Verify closure counted as method.
     *
     * @return void
     */
    public function testClosureCountedAsMethod(): void
    {
        $findings = $this->analyse('closure.php', ['warning' => 3, 'error' => 60]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertNotNull($findings[0]->symbol);
        self::assertStringStartsWith('Closure@', $findings[0]->symbol);
    }

    /**
     * Verify disabled rule produces no findings.
     *
     * @return void
     */
    public function testDisabledRuleProducesNoFindings(): void
    {
        $unit     = $this->parseFixture('long-method.php');
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            MethodLengthRule::ID,
            new RuleSettings(false, ['warning' => 3, 'error' => 10]),
        );

        $findings       = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $methodFindings = array_filter($findings, static fn($finding) => $finding->ruleId === MethodLengthRule::ID);

        self::assertSame([], array_values($methodFindings));
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param string             $fixture    Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds Rule option overrides applied before the fixture runs.
     *
     * @return list<\GruffPhp\Finding\Finding> - findings the rule raised for the fixture; empty when nothing tripped the thresholds
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit        = $this->parseFixture($fixture);
        $registry    = RuleRegistry::defaults();
        $config      = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            MethodLengthRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $ruleContext = new RuleContext(__DIR__ . '/../../..', $config);

        // Only this rule runs, so every finding returned belongs to the case under test.
        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     *
     * @return \GruffPhp\Parser\AnalysisUnit - the parsed fixture ready for the rule to analyse, carrying the repo-relative display path
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        // Display path stays repo-relative so findings report the fixture, not the temp absolute path.
        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
