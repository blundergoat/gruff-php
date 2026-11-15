<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\LimitBand;
use GruffPhp\Rules\Size\ParameterCountRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises parameter-count findings using parsed fixtures and per-test rule settings.
 * Checks finding counts, symbols, severities and metadata for methods, constructors and interface declarations.
 * Covers constructor caps and promoted value-object ceilings, including configured overrides.
 */
final class ParameterCountRuleTest extends TestCase
{
    /** Rule instance under test. */
    private ParameterCountRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Create the rule and parser used by each fixture-based test.
     *
     * @return void - Initializes fresh rule and parser instances before each test.
     */
    protected function setUp(): void
    {
        $this->rule   = new ParameterCountRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify no findings for few parameters.
     *
     * @return void - Asserts an empty finding list for the zero-parameter fixture.
     */
    public function testNoFindingsForFewParameters(): void
    {
        $findings = $this->analyse('short-method.php', ['warning' => 5, 'error' => 8]);

        self::assertSame([], $findings);
    }

    /**
     * Exercise configured parameter limits across ordinary methods and a promoted constructor.
     *
     * @return void - Asserts two lower-band advisories, one upper-band error and the expected callable symbols.
     */
    public function testSixParametersAreLowerBandNoticesAndNineAnError(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $notices = array_values(array_filter($findings, static fn($finding) => $finding->severity === Severity::Advisory));
        $errors  = array_values(array_filter($findings, static fn($finding) => $finding->severity === Severity::Error));

        self::assertCount(2, $notices);
        self::assertSame([LimitBand::LOWER, LimitBand::LOWER], array_map(static fn($finding) => $finding->metadata[LimitBand::KEY], $notices));
        self::assertCount(1, $errors);
        self::assertSame(LimitBand::UPPER, $errors[0]->metadata[LimitBand::KEY]);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('ManyParamsFixture::sixParams()', $symbols);
        self::assertContains('ManyParamsFixture::nineParams()', $symbols);
        self::assertContains('ManyParamsFixture::__construct()', $symbols);
    }

    /**
     * Verify error for nine parameters.
     *
     * @return void - Asserts one error for nineParams() with parameter-count metadata of nine.
     */
    public function testErrorForNineParameters(): void
    {
        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $errors = array_values(array_filter($findings, static fn($finding) => $finding->severity === Severity::Error));
        self::assertCount(1, $errors);
        self::assertSame('ManyParamsFixture::nineParams()', $errors[0]->symbol);
        self::assertSame(9, $errors[0]->metadata['parameters']);
    }

    /**
     * Provide fixture/symbol pairs whose absence from parameter-count findings is asserted.
     *
     * @return iterable<string, array{fixture: string, symbol: string}> - One fixture and expected-absent symbol per PHPUnit data-set label.
     */
    public static function exemptCallableProvider(): iterable
    {
        yield 'variadic function exempt' => [
            'fixture' => 'many-params.php',
            'symbol'  => 'ManyParamsFixture::variadicParams()',
        ];

        yield 'promoted readonly DTO exempt' => [
            'fixture' => 'promoted-payload.php',
            'symbol'  => 'PromotedPayloadFixture::__construct()',
        ];

        yield 'promoted DTO at ceiling stays exempt' => [
            'fixture' => 'promoted-payload-at-ceiling.php',
            'symbol'  => 'PromotedPayloadAtCeilingFixture::__construct()',
        ];
    }

    /**
     * Verify each supplied symbol is absent from the findings under the standard thresholds.
     *
     * @param string $fixture - Fixture filename to parse.
     * @param string $symbol - Symbol whose absence from findings is asserted.
     *
     * @return void - Asserts that the supplied symbol does not appear in the fixture's findings.
     */
    #[DataProvider('exemptCallableProvider')]
    public function testExemptCallableDoesNotFire(string $fixture, string $symbol): void
    {
        $findings = $this->analyse($fixture, ['warning' => 5, 'error' => 8]);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertNotContains($symbol, $symbols);
    }

    /**
     * Verify promoted constructor parameters counted.
     *
     * @return void - Asserts one constructor finding whose parameter-count metadata is six.
     */
    public function testPromotedConstructorParametersCounted(): void
    {
        $expectedPromotedCount = 6;

        $findings = $this->analyse('many-params.php', ['warning' => 5, 'error' => 8]);

        $constructorFindings = array_values(array_filter(
                                                $findings,
                                                static fn($finding) => $finding->symbol === 'ManyParamsFixture::__construct()',
                                            ));

        self::assertCount(1, $constructorFindings);
        self::assertSame($expectedPromotedCount, $constructorFindings[0]->metadata['parameters']);
    }

    /**
     * Verify constructor-specific threshold can allow constructors while ordinary methods still fire.
     *
     * @return void - Asserts both method symbols are present and the constructor symbol is absent.
     */
    public function testConfiguredConstructorThresholdAllowsConstructorWhileKeepingMethodFindings(): void
    {
        $findings = $this->analyse(
            'many-params.php',
            ['warning' => 5, 'error' => 8],
            ['constructorMaxParameters' => 6],
        );

        $symbols = array_map(static fn($finding): ?string => $finding->symbol, $findings);

        self::assertContains('ManyParamsFixture::sixParams()', $symbols);
        self::assertContains('ManyParamsFixture::nineParams()', $symbols);
        self::assertNotContains('ManyParamsFixture::__construct()', $symbols);
    }

    /**
     * Verify constructor-specific threshold emits dedicated metadata under configured warning and error thresholds.
     *
     * @return void - Asserts one advisory constructor finding with lower-band, cap and kind metadata plus the threshold message.
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
                                                static fn($finding) => $finding->symbol === 'ManyParamsFixture::__construct()',
                                            ));

        // Six parameters against a constructor cap of 5 sit under one and a half times it, so the finding is advisory.
        self::assertCount(1, $constructorFindings);
        self::assertSame(Severity::Advisory, $constructorFindings[0]->severity);
        self::assertSame(LimitBand::LOWER, $constructorFindings[0]->metadata[LimitBand::KEY]);
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
     * Verify constructor-specific threshold uses the registry's default severity.
     *
     * @return void - Asserts one constructor error carrying the configured cap of four.
     */
    public function testConfiguredConstructorThresholdUsesDefaultSeverityThreshold(): void
    {
        // A cap of 4 puts the six-parameter constructor at one and a half times it, where the default severity applies.
        $constructorMax = 4;
        $findings       = $this->analyseWithDefaultSettings(
            'many-params.php',
            ['constructorMaxParameters' => $constructorMax],
        );

        $constructorFindings = array_values(array_filter(
                                                $findings,
                                                static fn($finding) => $finding->symbol === 'ManyParamsFixture::__construct()',
                                            ));

        self::assertCount(1, $constructorFindings);
        self::assertSame(Severity::Error, $constructorFindings[0]->severity);
        self::assertSame($constructorMax, $constructorFindings[0]->metadata['constructorMaxParameters']);
    }

    /**
     * Verify service constructors with many dependencies are judged by the default threshold, not exempt as value objects.
     *
     * @return void - Asserts one lower-band advisory for twelve constructor parameters without a special finding kind.
     */
    public function testServiceConstructorWithManyDependenciesIsNotExemptAsAValueObject(): void
    {
        $findings = $this->analyseWithDefaultSettings('service-dependencies.php', []);

        // Twelve parameters against the default limit of 10 sit under one and a half times it, so the finding is a notice.
        self::assertCount(1, $findings);
        self::assertSame('ServiceWithTooManyDependencies::__construct()', $findings[0]->symbol);
        self::assertSame(12, $findings[0]->metadata['parameters']);
        self::assertArrayNotHasKey('findingKind', $findings[0]->metadata);
        self::assertSame(LimitBand::LOWER, $findings[0]->metadata[LimitBand::KEY]);
        self::assertSame(Severity::Advisory, $findings[0]->severity);
    }

    /**
     * Verify a final readonly constructor with a void-returning method does not receive the promoted value-object exemption.
     *
     * @return void - Asserts one lower-band advisory for eleven constructor parameters without a special finding kind.
     */
    public function testReadonlyServiceConstructorWithBehaviourIsNotExemptAsAValueObject(): void
    {
        $findings = $this->analyseWithDefaultSettings('readonly-service-constructor.php', []);

        $constructorFindings = array_values(array_filter(
                                                $findings,
                                                static fn($finding) => $finding->symbol === 'ReadonlyServiceConstructorFixture::__construct()',
                                            ));

        // Eleven parameters against the default limit of 10 sit under one and a half times it, so the finding is advisory.
        self::assertCount(1, $constructorFindings);
        self::assertArrayNotHasKey('findingKind', $constructorFindings[0]->metadata);
        self::assertSame(LimitBand::LOWER, $constructorFindings[0]->metadata[LimitBand::KEY]);
        self::assertSame(Severity::Advisory, $constructorFindings[0]->severity);
        self::assertSame(11, $constructorFindings[0]->metadata['parameters']);
    }

    /**
     * Verify a promoted readonly DTO above the default ceiling fires with the ceiling-bypass message.
     *
     * @return void - Asserts one advisory ceiling finding with 26 parameters, ceiling 25 and the ceiling message.
     */
    public function testPromotedConstructorAboveDefaultCeilingFires(): void
    {
        $defaultCeiling = 25;
        $expectedParams = 26;

        $findings = $this->analyse('promoted-payload-above-ceiling.php', ['warning' => 5, 'error' => 8]);

        $ceilingFindings = array_values(array_filter(
                                            $findings,
                                            static fn($finding) => $finding->symbol === 'PromotedPayloadAboveCeilingFixture::__construct()',
                                        ));

        self::assertCount(1, $ceilingFindings);
        self::assertSame(Severity::Advisory, $ceilingFindings[0]->severity);
        self::assertSame($expectedParams, $ceilingFindings[0]->metadata['parameters']);
        self::assertSame($defaultCeiling, $ceilingFindings[0]->metadata['promotedConstructorMaxParameters']);
        self::assertSame('promoted-ctor-ceiling', $ceilingFindings[0]->metadata['findingKind']);
        self::assertStringContainsString(sprintf('value-object ceiling of %d', $defaultCeiling), $ceilingFindings[0]->message);
    }

    /**
     * Verify a project override of the ceiling fires earlier on a smaller promoted DTO.
     *
     * @return void - Asserts one advisory ceiling finding with nine parameters and the configured cap of five.
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
                                            static fn($finding) => $finding->symbol === 'PromotedPayloadFixture::__construct()',
                                        ));

        self::assertCount(1, $ceilingFindings);
        self::assertSame(Severity::Advisory, $ceilingFindings[0]->severity);
        self::assertSame($expectedParams, $ceilingFindings[0]->metadata['parameters']);
        self::assertSame($overrideCeiling, $ceilingFindings[0]->metadata['promotedConstructorMaxParameters']);
        self::assertSame('promoted-ctor-ceiling', $ceilingFindings[0]->metadata['findingKind']);
    }

    /**
     * Verify constructor threshold does not override promoted value-object exemption.
     *
     * @return void - Asserts the promoted payload constructor is absent from findings despite the constructor cap of five.
     */
    public function testConstructorThresholdDoesNotOverridePromotedValueObjectExemption(): void
    {
        $findings = $this->analyse(
            'promoted-payload.php',
            ['warning' => 5, 'error' => 8],
            ['constructorMaxParameters' => 5],
        );

        $symbols = array_map(static fn($finding): ?string => $finding->symbol, $findings);
        self::assertNotContains('PromotedPayloadFixture::__construct()', $symbols);
    }

    /**
     * Verify interface parameters counted.
     *
     * @return void - Asserts one finding whose parameter-count metadata is six.
     */
    public function testInterfaceParametersCounted(): void
    {
        $findings = $this->analyse('interface-fixture.php', ['warning' => 5, 'error' => 8]);

        self::assertCount(1, $findings);
        self::assertSame(6, $findings[0]->metadata['parameters']);
    }

    /**
     * Analyse one size fixture with explicit thresholds and merged rule options.
     *
     * @param string             $fixture - Fixture filename under tests/Fixtures/Size to scan.
     * @param array<string, int> $thresholds - Warning/error parameter-count limits for this case.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Overrides merged onto rule defaults.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - Parameter-count findings after exemptions and active limits; empty when none qualify.
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
        $ruleContext    = new RuleContext(__DIR__ . '/../../..', $config);

        return $this->rule->analyse($unit, $ruleContext);
    }

    /**
     * Analyse one size fixture while retaining the registry's thresholds and severity setting.
     *
     * @param string $fixture - Fixture filename under tests/Fixtures/Size to scan.
     *
     * @param array<string, int|float|bool|string|array<array-key, int|float|bool|string>> $options - Overrides merged onto registry options.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - findings produced under the default thresholds, empty when none fired
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
     * @param string $filename - Name resolved under tests/Fixtures/Size.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - Analysis unit retaining the fixture path and any read or parse diagnostics.
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Size/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Size/' . $filename));
    }
}
