<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\MethodLengthRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers method-length thresholds with short, long and closure fixtures.
 * Asserts warning and error findings, callable symbols and the long method's measured line count.
 * Checks that multiline-call and disabled-rule cases produce no method-length findings.
 */
final class MethodLengthRuleTest extends TestCase
{
    /** Rule instance under test. */
    private MethodLengthRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create a fresh rule and parser for each test.
     *
     * @return void - Initializes the rule and parser used by fixture helpers.
     */
    protected function setUp(): void
    {
        $this->rule   = new MethodLengthRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for short methods.
     *
     * @return void - Asserts an empty finding list for the short-method fixture.
     */
    public function testNoFindingsForShortMethods(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 30, 'error' => 60]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for method above warning threshold.
     *
     * @return void - Asserts one warning with the expected rule ID, symbol and 31-line measurement.
     */
    public function testWarningForMethodAboveWarningThreshold(): void
    {
        // The 31-line method exceeds the upper-band boundary of 30, so it retains warning severity.
        $findings = $this->analyse('long-method.php', ['warning' => 20, 'error' => 60]);

        self::assertCount(1, $findings);
        self::assertSame(MethodLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('LongMethodFixture::warningMethod()', $findings[0]->symbol);
        self::assertSame(31, $findings[0]->metadata['lines']);
    }

    /**
     * Verify error for method above error threshold.
     *
     * @return void - Asserts one error finding for LongMethodFixture::warningMethod().
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
     * Verify the multiline-call fixture stays below the configured reporting threshold.
     *
     * @return void - Asserts no findings for the multiline-call fixture at the configured thresholds.
     */
    public function testMultilineCallCountsAsOneLogicalLine(): void
    {
        $findings = $this->analyse('logical-method.php', ['warning' => 3, 'error' => 60]);

        self::assertSame([], $findings);
    }

    /**
     * Verify closure counted as method.
     *
     * @return void - Asserts one warning finding with a non-null symbol beginning with Closure@.
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
     * @return void - Asserts the registry emits no method-length findings when the rule is disabled.
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
     * Analyse one size fixture with the supplied thresholds.
     *
     * @param string             $fixture - Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds - Warning and error thresholds used for this fixture.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - findings the rule raised for the fixture; empty when nothing tripped the thresholds
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

        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Filename appended to tests/Fixtures/Size/.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - the parsed fixture ready for the rule to analyse, carrying the repo-relative display path
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
