<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\AverageMethodLengthRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers average-method-length thresholds, warning/error transitions, and skipping of classes with no methods.
 */
final class AverageMethodLengthRuleTest extends TestCase
{
    /** Rule instance under test. */
    private AverageMethodLengthRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule   = new AverageMethodLengthRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for small averages.
     *
     * @return void
     */
    public function testNoFindingsForSmallAverages(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 20, 'error' => 40]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for high average method length.
     *
     * @return void
     */
    public function testWarningForHighAverageMethodLength(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 10, 'error' => 40]);

        self::assertCount(1, $findings);
        self::assertSame(AverageMethodLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('LongMethodFixture', $findings[0]->symbol);
        self::assertSame(2, $findings[0]->metadata['methodCount']);
        self::assertGreaterThan(10.0, $findings[0]->metadata['averageLength']);
    }

    /**
     * Verify error for very high average.
     *
     * @return void
     */
    public function testErrorForVeryHighAverage(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 5, 'error' => 10]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    /**
     * Verify class with no methods skipped.
     *
     * @return void
     */
    public function testClassWithNoMethodsSkipped(): void
    {
        $findings = $this->analyse('few-properties.php', ['warning' => 1, 'error' => 2]);

        $avgFindings = array_values(array_filter(
                                        $findings,
                                        static fn($finding) => $finding->ruleId === AverageMethodLengthRule::ID,
                                    ));

        self::assertSame([], $avgFindings);
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param string             $fixture - Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds - Rule option overrides applied before the fixture runs.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - findings this rule emitted for the fixture; empty when the average is under threshold
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit        = $this->parseFixture($fixture);
        $registry    = RuleRegistry::defaults();
        $config      = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            AverageMethodLengthRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $ruleContext = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - Fixture filename.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture with its display path set to the repo-relative location
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
