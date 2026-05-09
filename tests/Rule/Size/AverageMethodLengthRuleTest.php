<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\AverageMethodLengthRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class AverageMethodLengthRuleTest extends TestCase
{
    private AverageMethodLengthRule $rule;
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->rule = new AverageMethodLengthRule();
        $this->parser = new PhpFileParser();
    }

    public function testNoFindingsForSmallAverages(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 20, 'error' => 40]);

        self::assertSame([], $findings);
    }

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

    public function testErrorForVeryHighAverage(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 5, 'error' => 10]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    public function testClassWithNoMethodsSkipped(): void
    {
        $findings = $this->analyse('few-properties.php', ['warning' => 1, 'error' => 2]);

        $avgFindings = array_values(array_filter(
            $findings,
            static fn ($f) => $f->ruleId === AverageMethodLengthRule::ID,
        ));

        self::assertSame([], $avgFindings);
    }

    /**
     * @param array<string, int> $thresholds
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            AverageMethodLengthRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $context = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $context);
    }

    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/M05/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/M05/Size/' . $filename));
    }
}
