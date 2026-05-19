<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\HalsteadVolumeRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers HalsteadVolumeRule behavior.
 */
final class HalsteadVolumeRuleTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;
    /** Rule instance under test. */
    private HalsteadVolumeRule $rule;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
        $this->rule   = new HalsteadVolumeRule();
    }

    /**
     * @return array<string, array{string, float, float, float, int, int}>
     */
    public static function metricsProvider(): array
    {
        return [
            'flat' => ['flat', 2.0, 0.5, 1.0, 2, 2],
            'one if' => ['oneIf', 18.5754247591, 3.0, 55.7262742773, 5, 8],
            'nested if' => ['nestedIf', 44.9176787529, 5.3333333333, 239.5609533489, 7, 16],
            'boolean chain' => ['booleanChain', 44.3789500202, 6.0, 266.2737001212, 9, 14],
            'same operator chain' => ['sameOperatorChain', 42.0, 3.0, 126.0, 8, 14],
            'switch only' => ['switchOnly', 23.2192809489, 2.0, 46.4385618977, 5, 10],
            'deeply nested' => ['deeplyNested', 106.6059378176, 15.3, 1631.0708486095, 14, 28],
            'while condition' => ['whileWithBooleanCondition', 30.8809041426, 7.5, 231.6067810698, 7, 11],
            'do while condition' => ['doWhileWithBooleanCondition', 30.8809041426, 7.5, 231.6067810698, 7, 11],
            'try catch finally' => ['tryCatchFinallyBranches', 60.0, 15.0, 900.0, 8, 20],
            'jumps and goto' => ['jumpsAndGoto', 82.4541375166, 14.0, 1154.3579252322, 12, 23],
            'logical keyword chain' => ['logicalKeywordChain', 22.4588393765, 2.0, 44.9176787529, 7, 8],
            'expression ternaries' => ['expressionAndReturnTernaries', 43.9443625123, 6.0, 263.6661750736, 6, 17],
            'closure arrow' => ['closureAndArrowFunction', 69.7383500317, 8.125, 566.6240940078, 9, 22],
        ];
    }

    /**
     * Verify Halstead metrics match expected counts and formula output.
     *
     * @param string $methodName         Fixture method name.
     * @param float  $expectedVolume     Expected Halstead volume.
     * @param float  $expectedDifficulty Expected Halstead difficulty.
     * @param float  $expectedEffort     Expected Halstead effort.
     * @param int    $expectedVocabulary Expected vocabulary count.
     * @param int    $expectedLength     Expected token length.
     * @return void No return value.
     */
    #[DataProvider('metricsProvider')]
    public function testHalsteadMetricsMatchExpected(
        string $methodName,
        float $expectedVolume,
        float $expectedDifficulty,
        float $expectedEffort,
        int $expectedVocabulary,
        int $expectedLength,
    ): void {
        $metrics = HalsteadVolumeRule::computeHalsteadMetrics($this->fixtureMethod($methodName));

        self::assertEqualsWithDelta($expectedVolume, $metrics['volume'], 0.000000001);
        self::assertEqualsWithDelta($expectedDifficulty, $metrics['difficulty'], 0.000000001);
        self::assertEqualsWithDelta($expectedEffort, $metrics['effort'], 0.000000001);
        self::assertSame($expectedVocabulary, $metrics['vocabulary']);
        self::assertSame($expectedLength, $metrics['length']);
    }

    /**
     * Verify default Halstead thresholds stay stable.
     *
     * @return void No return value.
     */
    public function testDefinitionThresholdsAreStable(): void
    {
        $definition = $this->rule->definition();

        self::assertSame([], $definition->defaultThresholds);
        self::assertNotNull($definition->severityThreshold);
        self::assertSame(8000, $definition->severityThreshold->threshold);
        self::assertSame(\GruffPhp\Finding\Severity::Error, $definition->severityThreshold->severity);
    }

    /**
     * Verify findings include rounded metric metadata.
     *
     * @return void No return value.
     */
    public function testFindingsIncludeRoundedMetricMetadata(): void
    {
        $findings = $this->analyse(['warning' => 30, 'error' => 100]);
        $finding  = array_values(array_filter(
            $findings,
            static fn ($candidate): bool => $candidate->symbol === 'CognitiveFixture::deeplyNested()',
        ))[0] ?? null;

        self::assertNotNull($finding);
        self::assertSame(106.6, $finding->metadata['volume'] ?? null);
        self::assertSame(15.3, $finding->metadata['difficulty'] ?? null);
        self::assertSame(1631.1, $finding->metadata['effort'] ?? null);
        $expectedVocabulary = 14;
        $expectedLength     = 28;
        self::assertSame($expectedVocabulary, $finding->metadata['vocabulary'] ?? null);
        self::assertSame($expectedLength, $finding->metadata['length'] ?? null);
        self::assertSame(100, $finding->metadata['threshold'] ?? null);
        self::assertSame('error', $finding->metadata['thresholdType'] ?? null);
    }

    /**
     * Verify fractional threshold values are preserved in messages.
     *
     * @return void No return value.
     */
    public function testFractionalThresholdIsPreservedInMessage(): void
    {
        $findings = $this->analyse(['warning' => 10.5, 'error' => 2000]);
        $finding  = array_values(array_filter(
            $findings,
            static fn ($candidate): bool => $candidate->symbol === 'CognitiveFixture::oneIf()',
        ))[0] ?? null;

        self::assertNotNull($finding);
        self::assertStringContainsString('above the warning threshold of 10.5.', $finding->message);
    }

    /**
     * Analyse the cognitive fixture with custom Halstead thresholds.
     *
     * @param array<string, int|float> $thresholds Rule thresholds.
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(array $thresholds): array
    {
        $unit     = $this->parseFixture();
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            HalsteadVolumeRule::ID,
            new RuleSettings(true, $thresholds),
        );

        return $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Return a named method from the cognitive fixture.
     *
     * @param string $methodName Fixture method name.
     * @return ClassMethod Fixture method node.
     */
    private function fixtureMethod(string $methodName): ClassMethod
    {
        $nodeFinder = new NodeFinder();

        foreach ($nodeFinder->findInstanceOf($this->parseFixture()->statements, ClassMethod::class) as $method) {
            if ($method->name->toString() === $methodName) {
                return $method;
            }
        }

        self::fail(sprintf('Fixture method %s not found.', $methodName));
    }

    /**
     * Parse the cognitive fixture into an analysis unit.
     *
     * @return \GruffPhp\Parser\AnalysisUnit Fixture value.
     */
    private function parseFixture(): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/cognitive.php';

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/cognitive.php'));
    }
}
