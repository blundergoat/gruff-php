<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers CognitiveComplexityRuleTest behavior.
 */
final class CognitiveComplexityRuleTest extends TestCase
{
    /** Rule instance under test. */
    private CognitiveComplexityRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule   = new CognitiveComplexityRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Provide method cc cases for parameterized tests.
     *
     * @return array<string, array{string, int}>
     */
    public static function methodCcProvider(): array
    {
        return [
            'flat method' => ['flat', 0],
            'single if' => ['oneIf', 1],
            'nested if/else' => ['nestedIf', 4],
            'boolean chain switching' => ['booleanChain', 3],
            'same operator chain' => ['sameOperatorChain', 2],
            'switch only' => ['switchOnly', 1],
            'deeply nested' => ['deeplyNested', 11],
            'while boolean condition' => ['whileWithBooleanCondition', 2],
            'do while boolean condition' => ['doWhileWithBooleanCondition', 2],
            'try catch finally branches' => ['tryCatchFinallyBranches', 5],
            'jumps and goto' => ['jumpsAndGoto', 12],
            'logical keyword chain' => ['logicalKeywordChain', 3],
            'expression and return ternaries' => ['expressionAndReturnTernaries', 4],
            'closure and arrow function' => ['closureAndArrowFunction', 4],
            'elseif and nested branches' => ['elseifAndNestedBranches', 8],
            'switch with nested cases' => ['switchWithNestedCases', 8],
            'short ternary' => ['shortTernary', 3],
            'plain return' => ['plainReturn', 1],
        ];
    }

    /**
     * Verify cognitive count matches expected.
     *
     * @param string $methodName Fixture method name.
     * @param int    $expectedCc Expected cognitive complexity.
     * @return void
     */
    #[DataProvider('methodCcProvider')]
    public function testCognitiveCountMatchesExpected(string $methodName, int $expectedCc): void
    {
        $unit       = $this->parseFixture('cognitive.php');
        $nodeFinder = new NodeFinder();
        $methods    = $nodeFinder->findInstanceOf($unit->statements, ClassMethod::class);

        $method = null;

        foreach ($methods as $candidateMethod) {
            if ($candidateMethod->name->toString() === $methodName) {
                $method = $candidateMethod;

                break;
            }
        }

        self::assertNotNull($method, sprintf('Method %s not found in fixture.', $methodName));
        self::assertSame($expectedCc, CognitiveComplexityRule::computeCognitiveComplexity($method));
    }

    /**
     * Verify no findings for simple methods.
     *
     * @return void
     */
    public function testNoFindingsForSimpleMethods(): void
    {
        $findings = $this->analyse('simple.php', ['warning' => 15, 'error' => 30]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for high cognitive complexity.
     *
     * @return void
     */
    public function testWarningForHighCognitiveComplexity(): void
    {
        $findings = $this->analyse('cognitive.php', ['warning' => 2, 'error' => 30]);

        self::assertNotSame([], $findings);

        $symbols = array_map(static fn ($finding) => $finding->symbol, $findings);
        self::assertContains('CognitiveFixture::deeplyNested()', $symbols);

        $deeplyNested = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->symbol === 'CognitiveFixture::deeplyNested()',
        ))[0] ?? null;

        self::assertNotNull($deeplyNested);
        self::assertSame(11, $deeplyNested->metadata['complexity'] ?? null);
        self::assertSame(2, $deeplyNested->metadata['threshold'] ?? null);
        self::assertSame('warning', $deeplyNested->metadata['thresholdType'] ?? null);
        $deeplyNestedFixtureEndLine = 97;
        self::assertSame($deeplyNestedFixtureEndLine, $deeplyNested->endLine);
    }

    /**
     * Verify default threshold metadata stays stable.
     *
     * @return void
     */
    public function testDefinitionThresholdsAreStable(): void
    {
        $definition = $this->rule->definition();

        self::assertSame([], $definition->defaultThresholds);
        self::assertNotNull($definition->severityThreshold);
        self::assertSame(30, $definition->severityThreshold->threshold);
        self::assertSame(\GruffPhp\Finding\Severity::Error, $definition->severityThreshold->severity);
    }

    /**
     * Verify boolean chain collapsing.
     *
     * @return void
     */
    public function testBooleanChainCollapsing(): void
    {
        $unit       = $this->parseFixture('cognitive.php');
        $nodeFinder = new NodeFinder();
        $methods    = $nodeFinder->findInstanceOf($unit->statements, ClassMethod::class);

        $sameChain  = null;
        $mixedChain = null;

        foreach ($methods as $candidateMethod) {
            if ($candidateMethod->name->toString() === 'sameOperatorChain') {
                $sameChain = $candidateMethod;
            }

            if ($candidateMethod->name->toString() === 'booleanChain') {
                $mixedChain = $candidateMethod;
            }
        }

        self::assertNotNull($sameChain);
        self::assertNotNull($mixedChain);
        self::assertSame(2, CognitiveComplexityRule::computeCognitiveComplexity($sameChain));
        self::assertSame(3, CognitiveComplexityRule::computeCognitiveComplexity($mixedChain));
    }

    /**
     * Analyse complexity fixtures and return findings for assertions.
     *
     * @param array<string, int> $thresholds
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            CognitiveComplexityRule::ID,
            new RuleSettings(true, $thresholds),
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
        $path = __DIR__ . '/../../Fixtures/Complexity/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $filename));
    }
}
