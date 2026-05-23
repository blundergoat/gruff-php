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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers ParameterCountRuleTest behavior.
 */
final class ParameterCountRuleTest extends TestCase
{
    /** Rule instance under test. */
    private ParameterCountRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule   = new ParameterCountRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for few parameters.
     *
     * @return void
     */
    public function testNoFindingsForFewParameters(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 5, 'error' => 8]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for six parameters.
     *
     * @return void
     */
    public function testWarningForSixParameters(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $warnings = array_values(array_filter($findings, static fn ($finding) => $finding->severity === Severity::Warning));
        $errors   = array_values(array_filter($findings, static fn ($finding) => $finding->severity === Severity::Error));

        self::assertCount(2, $warnings);
        self::assertCount(1, $errors);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('ManyParamsFixture::sixParams()', $symbols);
        self::assertContains('ManyParamsFixture::nineParams()', $symbols);
        self::assertContains('ManyParamsFixture::__construct()', $symbols);
    }

    /**
     * Verify error for nine parameters.
     *
     * @return void
     */
    public function testErrorForNineParameters(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $errors = array_values(array_filter($findings, static fn ($finding) => $finding->severity === Severity::Error));
        self::assertCount(1, $errors);
        self::assertSame('ManyParamsFixture::nineParams()', $errors[0]->symbol);
        self::assertSame(9, $errors[0]->metadata['parameters']);
    }

    /**
     * Provide fixture/symbol pairs that must remain exempt from parameter-count findings.
     *
     * @return iterable<string, array{fixture: string, symbol: string}>
     */
    public static function exemptCallableProvider(): iterable
    {
        yield 'variadic function exempt' => [
            'fixture' => 'many-params.php',
            'symbol' => 'ManyParamsFixture::variadicParams()',
        ];

        yield 'promoted readonly DTO exempt' => [
            'fixture' => 'promoted-payload.php',
            'symbol' => 'PromotedPayloadFixture::__construct()',
        ];

        yield 'promoted DTO at ceiling stays exempt' => [
            'fixture' => 'promoted-payload-at-ceiling.php',
            'symbol' => 'PromotedPayloadAtCeilingFixture::__construct()',
        ];
    }

    /**
     * Verify exempt callables are not reported under the standard threshold.
     *
     * @param string $fixture Fixture filename to parse.
     * @param string $symbol  Symbol whose absence from findings is asserted.
     * @return void
     */
    #[DataProvider('exemptCallableProvider')]
    public function testExemptCallableDoesNotFire(string $fixture, string $symbol): void
    {
        $findings = $this->analyse($fixture, ['warning' => 5, 'error' => 8]);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertNotContains($symbol, $symbols);
    }

    /**
     * Verify promoted constructor parameters counted.
     *
     * @return void
     */
    public function testPromotedConstructorParametersCounted(): void
    {
        $expectedPromotedCount = 6;

        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $constructorFindings = array_values(array_filter(
            $findings,
            static fn ($finding) => $finding->symbol === 'ManyParamsFixture::__construct()',
        ));

        self::assertCount(1, $constructorFindings);
        self::assertSame($expectedPromotedCount, $constructorFindings[0]->metadata['parameters']);
    }

    /**
     * Verify constructor-specific threshold can allow constructors while ordinary methods still fire.
     *
     * @return void
     */
    public function testConfiguredConstructorThresholdAllowsConstructorWhileKeepingMethodFindings(): void
    {
        $findings = $this->analyse(
            'many-params.php',
            ['warning' => 5, 'error' => 8],
            ['constructorMaxParameters' => 6],
        );

        $symbols = array_map(static fn ($finding): ?string => $finding->symbol, $findings);

        self::assertContains('ManyParamsFixture::sixParams()', $symbols);
        self::assertContains('ManyParamsFixture::nineParams()', $symbols);
        self::assertNotContains('ManyParamsFixture::__construct()', $symbols);
    }

    /**
     * Verify constructor-specific threshold emits dedicated metadata under legacy thresholds.
     *
     * @return void
     */
    public function testConfiguredConstructorThresholdFiresWithMetadata(): void
    {
        $constructorMax        = 5;
        $constructorParamCount = 6;

        $findings = $this->analyse(
            'many-params.php',
            ['warning' => 5, 'error' => 8],
            ['constructorMaxParameters' => $constructorMax],
        );

        $constructorFindings = array_values(array_filter(
            $findings,
            static fn ($finding) => $finding->symbol === 'ManyParamsFixture::__construct()',
        ));

        self::assertCount(1, $constructorFindings);
        self::assertSame(Severity::Warning, $constructorFindings[0]->severity);
        self::assertSame($constructorParamCount, $constructorFindings[0]->metadata['parameters']);
        self::assertSame($constructorMax, $constructorFindings[0]->metadata['threshold']);
        self::assertSame($constructorMax, $constructorFindings[0]->metadata['constructorMaxParameters']);
        self::assertSame('constructor-threshold', $constructorFindings[0]->metadata['findingKind']);
        self::assertStringContainsString(
            sprintf('constructor threshold of %d', $constructorMax),
            $constructorFindings[0]->message,
        );
    }

    /**
     * Verify constructor-specific threshold uses the single-threshold severity shape.
     *
     * @return void
     */
    public function testConfiguredConstructorThresholdUsesDefaultSeverityThreshold(): void
    {
        $constructorMax = 5;
        $findings       = $this->analyseWithDefaultSettings(
            'many-params.php',
            ['constructorMaxParameters' => $constructorMax],
        );

        $constructorFindings = array_values(array_filter(
            $findings,
            static fn ($finding) => $finding->symbol === 'ManyParamsFixture::__construct()',
        ));

        self::assertCount(1, $constructorFindings);
        self::assertSame(Severity::Error, $constructorFindings[0]->severity);
        self::assertSame($constructorMax, $constructorFindings[0]->metadata['constructorMaxParameters']);
    }

    /**
     * Verify a promoted readonly DTO above the default ceiling fires with the ceiling-bypass message.
     *
     * @return void
     */
    public function testPromotedConstructorAboveDefaultCeilingFires(): void
    {
        $defaultCeiling = 25;
        $expectedParams = 26;

        $findings = $this->analyse('promoted-payload-above-ceiling.php', ['warning' => 5, 'error' => 8]);

        $ceilingFindings = array_values(array_filter(
            $findings,
            static fn ($finding) => $finding->symbol === 'PromotedPayloadAboveCeilingFixture::__construct()',
        ));

        self::assertCount(1, $ceilingFindings);
        self::assertSame($expectedParams, $ceilingFindings[0]->metadata['parameters']);
        self::assertSame($defaultCeiling, $ceilingFindings[0]->metadata['promotedConstructorMaxParameters']);
        self::assertSame('promoted-ctor-ceiling', $ceilingFindings[0]->metadata['findingKind']);
        self::assertStringContainsString(sprintf('value-object ceiling of %d', $defaultCeiling), $ceilingFindings[0]->message);
    }

    /**
     * Verify a project override of the ceiling fires earlier on a smaller promoted DTO.
     *
     * @return void
     */
    public function testPromotedConstructorCeilingHonoursOptionOverride(): void
    {
        $overrideCeiling = 5;
        $expectedParams  = 9;

        $findings = $this->analyse(
            'promoted-payload.php',
            ['warning' => 5, 'error' => 8],
            ['promotedConstructorMaxParameters' => $overrideCeiling],
        );

        $ceilingFindings = array_values(array_filter(
            $findings,
            static fn ($finding) => $finding->symbol === 'PromotedPayloadFixture::__construct()',
        ));

        self::assertCount(1, $ceilingFindings);
        self::assertSame($expectedParams, $ceilingFindings[0]->metadata['parameters']);
        self::assertSame($overrideCeiling, $ceilingFindings[0]->metadata['promotedConstructorMaxParameters']);
        self::assertSame('promoted-ctor-ceiling', $ceilingFindings[0]->metadata['findingKind']);
    }

    /**
     * Verify constructor threshold does not override promoted value-object exemption.
     *
     * @return void
     */
    public function testConstructorThresholdDoesNotOverridePromotedValueObjectExemption(): void
    {
        $findings = $this->analyse(
            'promoted-payload.php',
            ['warning' => 5, 'error' => 8],
            ['constructorMaxParameters' => 5],
        );

        $symbols = array_map(static fn ($finding): ?string => $finding->symbol, $findings);
        self::assertNotContains('PromotedPayloadFixture::__construct()', $symbols);
    }

    /**
     * Verify interface parameters counted.
     *
     * @return void
     */
    public function testInterfaceParametersCounted(): void
    {
        $findings = $this->analyse('interface-fixture.php', ['warning' => 5, 'error' => 8]);

        self::assertCount(1, $findings);
        self::assertSame(6, $findings[0]->metadata['parameters']);
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param array<string, int>                                                           $thresholds
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds, array $options = []): array
    {
        $unit           = $this->parseFixture($fixture);
        $registry       = RuleRegistry::defaults();
        $defaultOptions = $registry->get(ParameterCountRule::ID)->definition()->defaultOptions;
        $config         = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ParameterCountRule::ID,
            new RuleSettings(true, $thresholds, array_merge($defaultOptions, $options)),
        );
        $ruleContext = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Analyse fixture paths and return findings for assertions.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyseWithDefaultSettings(string $fixture, array $options): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $settings = AnalysisConfig::fromRegistry($registry)->ruleSettings(ParameterCountRule::ID);
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            ParameterCountRule::ID,
            new RuleSettings(
                true,
                $settings->thresholds,
                array_merge($settings->options, $options),
                $settings->severityThreshold,
            ),
        );

        return $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return \GruffPhp\Parser\AnalysisUnit
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
