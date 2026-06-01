<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\MaintainabilityIndexRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers maintainability index computation, threshold stability, rounded metadata, and fractional-threshold preservation in finding messages.
 */
final class MaintainabilityIndexRuleTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;
    /** Rule instance under test. */
    private MaintainabilityIndexRule $rule;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
        $this->rule   = new MaintainabilityIndexRule();
    }

    /**
     * Provide index cases for parameterized tests.
     *
     * @return array<string, array{string, float}> - data-provider rows keyed by case label, each pairing a fixture method name with its
     *                       hand-computed expected maintainability index
     */
    public static function indexProvider(): array
    {
        // Each row pins a fixture method to its hand-computed index; the oracle the formula output is checked against.
        return [
            'flat'                  => ['flat', 97.7576810884],
            'one if'                => ['oneIf', 80.4379624235],
            'nested if'             => ['nestedIf', 71.0516801408],
            'boolean chain'         => ['booleanChain', 77.5205272398],
            'same operator chain'   => ['sameOperatorChain', 77.6880695371],
            'switch only'           => ['switchOnly', 71.5978619526],
            'deeply nested'         => ['deeplyNested', 64.3131216050],
            'while condition'       => ['whileWithBooleanCondition', 78.7577563175],
            'do while condition'    => ['doWhileWithBooleanCondition', 78.7577563175],
            'try catch finally'     => ['tryCatchFinallyBranches', 65.0628829064],
            'jumps and goto'        => ['jumpsAndGoto', 65.0943288022],
            'logical keyword chain' => ['logicalKeywordChain', 79.5916507046],
            'expression ternaries'  => ['expressionAndReturnTernaries', 81.3917012156],
            'closure arrow'         => ['closureAndArrowFunction', 69.7139149775],
        ];
    }

    /**
     * Verify maintainability index values match expected formula output.
     *
     * @param string $methodName    Fixture method name.
     * @param float  $expectedIndex Expected maintainability index.
     *
     * @return void
     */
    #[DataProvider('indexProvider')]
    public function testMaintainabilityIndexMatchesExpected(string $methodName, float $expectedIndex): void
    {
        $unit = $this->parseFixture();

        self::assertEqualsWithDelta($expectedIndex, MaintainabilityIndexRule::computeMaintainabilityIndex($this->fixtureMethod($unit, $methodName), $unit), 0.000000001);
    }

    /**
     * Verify default maintainability thresholds stay stable.
     *
     * @return void
     */
    public function testDefinitionThresholdsAreStable(): void
    {
        $definition = $this->rule->definition();

        self::assertSame([], $definition->defaultThresholds);
        self::assertNotNull($definition->severityThreshold);
        self::assertSame(35, $definition->severityThreshold->threshold);
        self::assertSame(\GruffPhp\Finding\Severity::Advisory, $definition->severityThreshold->severity);
    }

    /**
     * Verify low maintainability findings include rounded metadata.
     *
     * @return void
     */
    public function testFindingsIncludeRoundedMetadata(): void
    {
        $unit     = $this->parseFixture();
        $config   = AnalysisConfig::fromRegistry(\GruffPhp\Rule\RuleRegistry::defaults())->withRuleSettings(
            MaintainabilityIndexRule::ID,
            new RuleSettings(true, ['warning' => 70, 'error' => 65]),
        );
        $findings = $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
        $finding  = array_values(array_filter(
                                     $findings,
                                     static fn($candidate): bool => $candidate->symbol === 'CognitiveFixture::deeplyNested()',
                                 ))[0] ?? null;

        self::assertNotNull($finding);
        self::assertSame(64.3, $finding->metadata['maintainabilityIndex'] ?? null);
        self::assertSame(65, $finding->metadata['threshold'] ?? null);
        self::assertSame('error', $finding->metadata['thresholdType'] ?? null);
        self::assertStringContainsString('maintainability index of 64.3, below the error threshold of 65.', $finding->message);
    }

    /**
     * Verify fractional threshold values are preserved in messages.
     *
     * @return void
     */
    public function testFractionalThresholdIsPreservedInMessage(): void
    {
        $unit     = $this->parseFixture();
        $config   = AnalysisConfig::fromRegistry(\GruffPhp\Rule\RuleRegistry::defaults())->withRuleSettings(
            MaintainabilityIndexRule::ID,
            new RuleSettings(true, ['warning' => 80.5, 'error' => 35]),
        );
        $findings = $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
        $finding  = array_values(array_filter(
                                     $findings,
                                     static fn($candidate): bool => $candidate->symbol === 'CognitiveFixture::oneIf()',
                                 ))[0] ?? null;

        self::assertNotNull($finding);
        self::assertStringContainsString('below the warning threshold of 80.5.', $finding->message);
    }

    /**
     * Return a named method from the cognitive fixture.
     *
     * @param AnalysisUnit $analysisUnit Parsed fixture.
     * @param string       $methodName   Fixture method name.
     *
     * @return ClassMethod - the fixture's AST node whose name matches $methodName; fails the test when no such method exists
     */
    private function fixtureMethod(AnalysisUnit $analysisUnit, string $methodName): ClassMethod
    {
        $nodeFinder = new NodeFinder();

        foreach ($nodeFinder->findInstanceOf($analysisUnit->statements, ClassMethod::class) as $method) {
            if ($method->name->toString() === $methodName) {
                // Fixture method names are unique, so the first match is the node under test; stop scanning here.
                return $method;
            }
        }

        self::fail(sprintf('Fixture method %s not found.', $methodName));
    }

    /**
     * Parse the cognitive fixture into an analysis unit.
     *
     * @return AnalysisUnit - parsed cognitive fixture carrying its repo-relative display path, ready for rule analysis
     */
    private function parseFixture(): AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/cognitive.php';

        // Parsed unit carries the display path the rule reports findings against, so keep it relative to the repo root.
        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/cognitive.php'));
    }
}
