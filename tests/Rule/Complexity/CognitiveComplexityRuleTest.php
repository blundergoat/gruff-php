<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Complexity\CognitiveComplexityRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks cognitive complexity scores against named fixture methods.
 * Covers default thresholds, emitted finding metadata and boolean-operator runs.
 * Distinguishes advisory guard-clause findings from nested and fall-through branching.
 */
final class CognitiveComplexityRuleTest extends TestCase
{
    /** Rule instance under test. */
    private CognitiveComplexityRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Give each test an independent cognitive-complexity rule and parser.
     *
     * @return void - Initializes the rule and parser used by the test methods.
     */
    protected function setUp(): void
    {
        $this->rule   = new CognitiveComplexityRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Supply expected cognitive-complexity scores for fixture methods.
     *
     * @return array<string, array{string, int}> - Named rows pairing fixture method names with expected cognitive scores.
     */
    public static function methodCcProvider(): array
    {
        return [
            'flat method'                     => ['flat', 0],
            'single if'                       => ['oneIf', 1],
            'nested if/else'                  => ['nestedIf', 4],
            'boolean chain switching'         => ['booleanChain', 3],
            'same operator chain'             => ['sameOperatorChain', 2],
            'switch only'                     => ['switchOnly', 1],
            'deeply nested'                   => ['deeplyNested', 11],
            'while boolean condition'         => ['whileWithBooleanCondition', 2],
            'do while boolean condition'      => ['doWhileWithBooleanCondition', 2],
            'try catch finally branches'      => ['tryCatchFinallyBranches', 4],
            'jumps and goto'                  => ['jumpsAndGoto', 8],
            'logical keyword chain'           => ['logicalKeywordChain', 3],
            'expression and return ternaries' => ['expressionAndReturnTernaries', 4],
            'closure and arrow function'      => ['closureAndArrowFunction', 3],
            'elseif and nested branches'      => ['elseifAndNestedBranches', 7],
            'switch with nested cases'        => ['switchWithNestedCases', 4],
            'non-guard still nests'           => ['nonGuardStillNests', 6],
            'guard needs no else'             => ['guardNeedsNoElse', 5],
            'short ternary'                   => ['shortTernary', 3],
            'plain return'                    => ['plainReturn', 1],
            // Match mirrors cognitive switch: one increment for the construct plus recursive arm scoring,
            // with no per-arm cost. The shared cyclomatic count used by the maintainability index charges each arm condition.
            'match with two arms'             => ['matchTwoArms', 1],
            'match with six arms'             => ['matchSixArms', 1],
            'nested match'                    => ['matchNested', 3],
            'match inside if'                 => ['matchInsideIf', 3],
            'match inside loop'               => ['matchInsideLoop', 3],
            'match with arm-body ternary'     => ['matchWithConditionComplexity', 3],
        ];
    }

    /**
     * Verify cognitive count matches expected.
     *
     * @param string $methodName - Fixture method name.
     * @param int    $expectedCc - Expected cognitive complexity.
     *
     * @return void - Asserts the named fixture method exists and has the supplied cognitive score.
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
     * Require simple.php to produce no cognitive-complexity findings at warning 15 and error 30.
     *
     * @return void - Asserts an empty finding list for simple.php at warning 15 and error 30.
     */
    public function testNoFindingsForSimpleMethods(): void
    {
        $findings = $this->analyse('simple.php', ['warning' => 15, 'error' => 30]);

        self::assertSame([], $findings);
    }

    /**
     * Verify the deeply nested fixture's complexity, threshold metadata and reported end line.
     *
     * @return void - Asserts the deeplyNested finding's score, threshold metadata and end line.
     */
    public function testWarningForHighCognitiveComplexity(): void
    {
        $findings = $this->analyse('cognitive.php', ['warning' => 2, 'error' => 30]);

        self::assertNotSame([], $findings);

        $symbols = array_map(static fn($finding) => $finding->symbol, $findings);
        self::assertContains('CognitiveFixture::deeplyNested()', $symbols);

        $deeplyNested = array_values(array_filter(
                                         $findings,
                                         static fn($finding): bool => $finding->symbol === 'CognitiveFixture::deeplyNested()',
                                     ))[0] ?? null;

        self::assertNotNull($deeplyNested);
        self::assertSame(11, $deeplyNested->metadata['complexity'] ?? null);
        self::assertSame(2, $deeplyNested->metadata['threshold'] ?? null);
        self::assertSame('warning', $deeplyNested->metadata['thresholdType'] ?? null);
        $deeplyNestedFixtureEndLine = 97;
        self::assertSame($deeplyNestedFixtureEndLine, $deeplyNested->endLine);
    }

    /**
     * Verify a match-bearing method trips a lowered threshold and reports its computed score.
     *
     * @return void - Asserts matchNested has complexity 3 in both metadata and message.
     */
    public function testMatchComplexityTripsLoweredThresholdWithReportedValue(): void
    {
        $findings = $this->analyse('cognitive.php', ['warning' => 2, 'error' => 30]);

        $nestedMatch = array_values(array_filter(
                                        $findings,
                                        static fn($finding): bool => $finding->symbol === 'CognitiveFixture::matchNested()',
                                    ))[0] ?? null;

        self::assertNotNull($nestedMatch);
        self::assertSame(3, $nestedMatch->metadata['complexity'] ?? null);
        self::assertStringContainsString('cognitive complexity of 3', $nestedMatch->message);
    }

    /**
     * Verify default threshold metadata stays stable.
     *
     * @return void - Asserts no default threshold map and an error severity threshold of 20.
     */
    public function testDefinitionThresholdsAreStable(): void
    {
        $definition = $this->rule->definition();

        self::assertSame([], $definition->defaultThresholds);
        self::assertNotNull($definition->severityThreshold);
        self::assertSame(20, $definition->severityThreshold->threshold);
        self::assertSame(\GruffPhp\Results\Finding\Severity::Error, $definition->severityThreshold->severity);
    }

    /**
     * Verify boolean chain collapsing.
     *
     * @return void - Asserts scores 2 and 3 for the same-operator and mixed-operator fixture methods.
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
     * Verify flat guard-clause validation is lower severity than nested branching.
     *
     * @return void - Asserts advisory guard-clause metadata and error severity for nested branching.
     */
    public function testFlatGuardClauseComplexityIsAdvisoryButNestedLogicStaysSevere(): void
    {
        $findings = $this->analyse('guard-clauses.php', ['warning' => 4, 'error' => 14]);

        $bySymbol = [];
        foreach ($findings as $finding) {
            $bySymbol[$finding->symbol ?? ''] = $finding;
        }

        self::assertSame(Severity::Advisory, $bySymbol['GuardClauseComplexityFixture::flatPayloadValidator()']->severity ?? null);
        self::assertSame('flat-guard-clauses', $bySymbol['GuardClauseComplexityFixture::flatPayloadValidator()']->metadata['complexityShape'] ?? null);
        self::assertSame('error', $bySymbol['GuardClauseComplexityFixture::flatPayloadValidator()']->metadata['rawThresholdType'] ?? null);
        self::assertSame(Severity::Error, $bySymbol['GuardClauseComplexityFixture::nestedBusinessLogic()']->severity ?? null);
        self::assertSame('branching', $bySymbol['GuardClauseComplexityFixture::nestedBusinessLogic()']->metadata['complexityShape'] ?? null);
    }

    /**
     * Verify flat fall-through branches that do work (not early-exit guards) keep their branching severity.
     *
     * @return void - Asserts telemetryBuilder remains a warning with the branching shape.
     */
    public function testFlatFallThroughBranchesAreNotDowngradedToAdvisory(): void
    {
        // A warning tier of 3 puts telemetryBuilder's complexity of 5 in the upper band, where the shape alone decides severity.
        $findings = $this->analyse('guard-clauses.php', ['warning' => 3, 'error' => 14]);

        $bySymbol = [];
        foreach ($findings as $finding) {
            $bySymbol[$finding->symbol ?? ''] = $finding;
        }

        // The branches do not exit early, so the fixture keeps branching severity at this threshold.

        self::assertSame(Severity::Warning, $bySymbol['GuardClauseComplexityFixture::telemetryBuilder()']->severity ?? null);
        self::assertSame('branching', $bySymbol['GuardClauseComplexityFixture::telemetryBuilder()']->metadata['complexityShape'] ?? null);
    }

    /**
     * Analyse complexity fixtures and return findings for assertions.
     *
     * @param string             $fixture    - fixture filename under Fixtures/Complexity to parse and run.
     * @param array<string, int> $thresholds - warning/error cutoffs applied to the fixture's rule run.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - findings the rule emits under those thresholds, to assert on.
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
     * @param string $filename - Filename resolved under tests/Fixtures/Complexity/.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - Fixture contents, statements and diagnostics with a repository-relative display path.
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $filename));
    }
}
