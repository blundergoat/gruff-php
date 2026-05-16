<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\NpathComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers NpathComplexityRule behavior.
 */
final class NpathComplexityRuleTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;
    /** Rule instance under test. */
    private NpathComplexityRule $rule;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
        $this->rule   = new NpathComplexityRule();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function npathProvider(): array
    {
        return [
            'flat' => ['flat', 1],
            'one if' => ['oneIf', 2],
            'nested if' => ['nestedIf', 3],
            'boolean chain' => ['booleanChain', 4],
            'same operator chain' => ['sameOperatorChain', 4],
            'switch only' => ['switchOnly', 3],
            'deeply nested' => ['deeplyNested', 5],
            'while condition' => ['whileWithBooleanCondition', 2],
            'do while condition' => ['doWhileWithBooleanCondition', 2],
            'try catch finally' => ['tryCatchFinallyBranches', 4],
            'jumps and goto' => ['jumpsAndGoto', 6],
            'logical keyword chain' => ['logicalKeywordChain', 4],
            'expression ternaries' => ['expressionAndReturnTernaries', 1],
            'closure arrow' => ['closureAndArrowFunction', 1],
        ];
    }

    /**
     * Verify NPath complexity values match expected fixture paths.
     *
     * @param string $methodName    Fixture method name.
     * @param int    $expectedNpath Expected NPath complexity.
     * @return void No return value.
     */
    #[DataProvider('npathProvider')]
    public function testNpathComplexityMatchesExpected(string $methodName, int $expectedNpath): void
    {
        self::assertSame($expectedNpath, NpathComplexityRule::computeNpathComplexity($this->fixtureMethod('cognitive.php', $methodName)));
    }

    /**
     * Verify default NPath thresholds stay stable.
     *
     * @return void No return value.
     */
    public function testDefinitionThresholdsAreStable(): void
    {
        self::assertSame(['warning' => 200, 'error' => 500], $this->rule->definition()->defaultThresholds);
    }

    /**
     * Verify findings include NPath metadata.
     *
     * @return void No return value.
     */
    public function testFindingsIncludeNpathMetadata(): void
    {
        $findings = $this->analyse($this->parseFixture('cognitive.php'), ['warning' => 3, 'error' => 5]);
        $finding  = array_values(array_filter(
            $findings,
            static fn ($candidate): bool => $candidate->symbol === 'CognitiveFixture::jumpsAndGoto()',
        ))[0] ?? null;

        self::assertNotNull($finding);
        self::assertSame(6, $finding->metadata['npath'] ?? null);
        $capped = $finding->metadata['capped'] ?? null;
        self::assertIsBool($capped);
        self::assertFalse($capped);
        self::assertSame(5, $finding->metadata['threshold'] ?? null);
        self::assertSame('error', $finding->metadata['thresholdType'] ?? null);
        self::assertStringContainsString('NPath complexity of 6, above the error threshold of 5.', $finding->message);
    }

    /**
     * Verify capped NPath findings use the cap label and metadata.
     *
     * @return void No return value.
     */
    public function testCappedNpathFindingUsesCapLabel(): void
    {
        $findings = $this->analyse($this->parseFixture('npath-cap.php'), ['warning' => 1, 'error' => 2]);

        self::assertCount(1, $findings);
        self::assertSame(100000, $findings[0]->metadata['npath'] ?? null);
        $capped = $findings[0]->metadata['capped'] ?? null;
        self::assertIsBool($capped);
        self::assertTrue($capped);
        self::assertStringContainsString('NPath complexity of >=100,000 (cap reached)', $findings[0]->message);
    }

    /**
     * Verify fractional threshold values are preserved in messages.
     *
     * @return void No return value.
     */
    public function testFractionalThresholdIsPreservedInMessage(): void
    {
        $findings = $this->analyse($this->parseFixture('cognitive.php'), ['warning' => 1.5, 'error' => 200]);
        $finding  = array_values(array_filter(
            $findings,
            static fn ($candidate): bool => $candidate->symbol === 'CognitiveFixture::oneIf()',
        ))[0] ?? null;

        self::assertNotNull($finding);
        self::assertStringContainsString('above the warning threshold of 1.5.', $finding->message);
    }

    /**
     * Analyse a fixture with custom NPath thresholds.
     *
     * @param AnalysisUnit             $unit       Parsed fixture.
     * @param array<string, int|float> $thresholds Rule thresholds.
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(AnalysisUnit $unit, array $thresholds): array
    {
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            NpathComplexityRule::ID,
            new RuleSettings(true, $thresholds),
        );

        return $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Return a named method from a fixture.
     *
     * @param string $fixture    Fixture filename.
     * @param string $methodName Fixture method name.
     * @return ClassMethod Fixture method node.
     */
    private function fixtureMethod(string $fixture, string $methodName): ClassMethod
    {
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($this->parseFixture($fixture)->statements, ClassMethod::class) as $method) {
            if ($method->name->toString() === $methodName) {
                return $method;
            }
        }

        self::fail(sprintf('Fixture method %s not found.', $methodName));
    }

    /**
     * Parse a complexity fixture into an analysis unit.
     *
     * @param string $fixture Fixture filename.
     * @return AnalysisUnit Fixture value.
     */
    private function parseFixture(string $fixture): AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/' . $fixture;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $fixture));
    }
}
