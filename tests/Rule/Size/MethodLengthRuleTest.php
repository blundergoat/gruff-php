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

final class MethodLengthRuleTest extends TestCase
{
    private MethodLengthRule $rule;
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->rule = new MethodLengthRule();
        $this->parser = new PhpFileParser();
    }

    public function testNoFindingsForShortMethods(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 30, 'error' => 60]);

        self::assertSame([], $findings);
    }

    public function testWarningForMethodAboveWarningThreshold(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 30, 'error' => 60]);

        self::assertCount(1, $findings);
        self::assertSame(MethodLengthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('LongMethodFixture::warningMethod()', $findings[0]->symbol);
        self::assertSame(34, $findings[0]->metadata['lines']);
    }

    public function testErrorForMethodAboveErrorThreshold(): void
    {
        $findings = $this->analyse('long-method.php', ['warning' => 3, 'error' => 10]);

        self::assertCount(2, $findings);

        $short = $findings[0];
        self::assertSame(Severity::Warning, $short->severity);
        self::assertSame('LongMethodFixture::shortMethod()', $short->symbol);

        $warning = $findings[1];
        self::assertSame(Severity::Error, $warning->severity);
        self::assertSame('LongMethodFixture::warningMethod()', $warning->symbol);
    }

    public function testClosureCountedAsMethod(): void
    {
        $findings = $this->analyse('closure.php', ['warning' => 3, 'error' => 60]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertNotNull($findings[0]->symbol);
        self::assertStringStartsWith('Closure@', $findings[0]->symbol);
    }

    public function testDisabledRuleProducesNoFindings(): void
    {
        $unit = $this->parseFixture('long-method.php');
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            MethodLengthRule::ID,
            new RuleSettings(false, ['warning' => 3, 'error' => 10]),
        );

        $findings = $registry->analyse([$unit], new RuleContext(__DIR__ . '/../../..', $config));
        $methodFindings = array_filter($findings, static fn ($f) => $f->ruleId === MethodLengthRule::ID);

        self::assertSame([], array_values($methodFindings));
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
            MethodLengthRule::ID,
            new RuleSettings(true, $thresholds),
        );
        $context = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $context);
    }

    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
