<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Rule\Size\ParameterCountRule;
use GruffPhp\Source\SourceFile;
use PHPUnit\Framework\TestCase;

final class ParameterCountRuleTest extends TestCase
{
    private ParameterCountRule $rule;
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->rule   = new ParameterCountRule();
        $this->parser = new PhpFileParser();
    }

    public function testNoFindingsForFewParameters(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 5, 'error' => 8]);

        self::assertSame([], $findings);
    }

    public function testWarningForSixParameters(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $warnings = array_values(array_filter($findings, static fn ($f) => $f->severity === Severity::Warning));
        $errors   = array_values(array_filter($findings, static fn ($f) => $f->severity === Severity::Error));

        self::assertCount(2, $warnings);
        self::assertCount(1, $errors);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('ManyParamsFixture::sixParams()', $symbols);
        self::assertContains('ManyParamsFixture::nineParams()', $symbols);
        self::assertContains('ManyParamsFixture::__construct()', $symbols);
    }

    public function testErrorForNineParameters(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $errors = array_values(array_filter($findings, static fn ($f) => $f->severity === Severity::Error));
        self::assertCount(1, $errors);
        self::assertSame('ManyParamsFixture::nineParams()', $errors[0]->symbol);
        self::assertSame(9, $errors[0]->metadata['parameters']);
    }

    public function testVariadicCountsAsOne(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('ManyParamsFixture::variadicParams()', $symbols);
    }

    public function testPromotedConstructorParametersCounted(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $constructorFindings = array_values(array_filter(
            $findings,
            static fn ($f) => $f->symbol === 'ManyParamsFixture::__construct()',
        ));

        self::assertCount(1, $constructorFindings);
        self::assertSame(6, $constructorFindings[0]->metadata['parameters']);
    }

    public function testPromotedReadonlyPayloadConstructorIsExempt(): void
    {
        $findings = $this->analyse('promoted-payload.php', ['warning' => 5, 'error' => 8]);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertNotContains('PromotedPayloadFixture::__construct()', $symbols);
    }

    public function testInterfaceParametersCounted(): void
    {
        $findings = $this->analyse('interface-fixture.php', ['warning' => 5, 'error' => 8]);

        self::assertCount(1, $findings);
        self::assertSame(6, $findings[0]->metadata['parameters']);
    }

    /**
     * @param array<string, int> $thresholds
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ParameterCountRule::ID,
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
