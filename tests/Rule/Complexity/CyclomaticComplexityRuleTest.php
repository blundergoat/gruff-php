<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Engine\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers cyclomatic complexity counting on fixtures plus warning and error threshold transitions for individual methods.
 */
final class CyclomaticComplexityRuleTest extends TestCase
{
    /** Rule instance under test. */
    private CyclomaticComplexityRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Give each test an independent cyclomatic-complexity rule and parser.
     *
     * @return void - Installs a fresh rule and parser for the next test.
     */
    protected function setUp(): void
    {
        $this->rule   = new CyclomaticComplexityRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Supply expected cyclomatic-complexity counts for fixture methods.
     *
     * @return array<string, array{string, int}> - Labelled fixture method names and their expected cyclomatic counts.
     */
    public static function methodCcnProvider(): array
    {
        return [
            'flat method'         => ['flat', 1],
            'if/elseif'           => ['ifElseIf', 3],
            'loop with condition' => ['loopWithCondition', 4],
            'switch block'        => ['switchBlock', 4],
            'match block'         => ['matchBlock', 4],
            'mixed operators'     => ['mixedOperators', 7],
            'try/catch loop'      => ['tryCatchLoop', 4],
        ];
    }

    /**
     * Verify cyclomatic count matches expected.
     *
     * @param string $methodName  - Name of the fixture method to measure.
     * @param int    $expectedCcn - Cyclomatic count the fixture method must produce.
     *
     * @return void - Asserts the shared counter's result for the selected fixture method.
     */
    #[DataProvider('methodCcnProvider')]
    public function testCyclomaticCountMatchesExpected(string $methodName, int $expectedCcn): void
    {
        $unit       = $this->parseFixture('cyclomatic.php');
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
        self::assertSame($expectedCcn, CyclomaticComplexityRule::computeCyclomaticComplexity($method));
    }

    /**
     * Verify reported complexity is 2 for switch and match, and 3 for the if/elseif chain.
     *
     * @return void - Asserts dispatch and branch counts in the rule's reported metadata.
     */
    public function testRuleCountsEachDispatchOnce(): void
    {
        $findings = $this->analyse('cyclomatic.php', ['warning' => 1, 'error' => 20]);
        $reported = [];

        foreach ($findings as $finding) {
            $reported[(string) $finding->symbol] = $finding->metadata;
        }

        $byMethod = static fn (string $method): array => array_values(array_filter(
            $reported,
            static fn (string $symbol): bool => str_contains($symbol, '::' . $method . '('),
            ARRAY_FILTER_USE_KEY,
        ))[0] ?? [];

        self::assertSame(2, $byMethod('switchBlock')['complexity'] ?? null, 'three cases and a default are one decision');
        self::assertSame(2, $byMethod('matchBlock')['complexity'] ?? null, 'three arm conditions are one decision');
        self::assertSame(3, $byMethod('ifElseIf')['complexity'] ?? null, 'an if/elseif chain keeps one point per branch');
    }

    /**
     * Require simple.php to produce no cyclomatic-complexity findings at warning 10 and error 20.
     *
     * @return void - Asserts that the simple fixture produces no findings at the supplied cutoffs.
     */
    public function testNoFindingsForSimpleMethods(): void
    {
        $findings = $this->analyse('simple.php', ['warning' => 10, 'error' => 20]);

        self::assertSame([], $findings);
    }

    /**
     * Verify every reported method exceeds the warning cutoff and belongs to the cyclomatic rule.
     *
     * @return void - Asserts rule identity and counts above three; it does not assert severity.
     */
    public function testWarningForMethodAboveThreshold(): void
    {
        $findings = $this->analyse('cyclomatic.php', ['warning' => 3, 'error' => 20]);

        self::assertNotSame([], $findings);

        $ruleIds             = array_values(array_unique(array_map(static fn($finding): string => $finding->ruleId, $findings)));
        $complexities        = array_map(static fn($finding): mixed => $finding->metadata['complexity'] ?? null, $findings);
        $invalidComplexities = array_values(array_filter(
                                                $complexities,
                                                static fn(mixed $complexity): bool => !is_int($complexity) || $complexity <= 3,
                                            ));

        self::assertSame([CyclomaticComplexityRule::ID], $ruleIds);
        self::assertSame([], $invalidComplexities);
    }

    /**
     * Verify error for method above error threshold.
     *
     * @return void - Asserts that at least one reported method reaches Error severity.
     */
    public function testErrorForMethodAboveErrorThreshold(): void
    {
        $findings = $this->analyse('cyclomatic.php', ['warning' => 3, 'error' => 4]);

        $errors = array_values(array_filter($findings, static fn($finding) => $finding->severity === Severity::Error));

        self::assertNotSame([], $errors);
    }

    /**
     * Verify the shared executable-body predicate excludes interface and abstract signatures while retaining concrete methods.
     *
     * @return void - Asserts the predicate result for each declaration kind.
     */
    public function testHasExecutableBodyDistinguishesBodylessSignatures(): void
    {
        $unit       = $this->parseFixture('bodyless.php');
        $nodeFinder = new NodeFinder();
        $methods    = $nodeFinder->findInstanceOf($unit->statements, ClassMethod::class);

        $hasBodyByName = [];
        foreach ($methods as $method) {
            $hasBodyByName[$method->name->toString()] = CyclomaticComplexityRule::hasExecutableBody($method);
        }

        self::assertFalse($hasBodyByName['declaredCount'] ?? null, 'Interface method has no body.');
        self::assertFalse($hasBodyByName['abstractTotal'] ?? null, 'Abstract method has no body.');
        self::assertTrue($hasBodyByName['concreteTotal'] ?? null, 'Concrete method has a body.');
    }

    /**
     * Verify only the concrete method reports at a zero warning cutoff, which would flag every measured declaration.
     *
     * @return void - Asserts that bodyless signatures are omitted from the reported symbols.
     */
    public function testBodylessDeclarationsAreNotMeasured(): void
    {
        $findings = $this->analyse('bodyless.php', ['warning' => 0, 'error' => 20]);

        $symbols = array_map(static fn($finding): ?string => $finding->symbol, $findings);

        self::assertSame(['BodylessFixture::concreteTotal()'], $symbols);
    }

    /**
     * Verify flat guard-clause validators are advisory while nested decision trees stay severe.
     *
     * @return void - Asserts shape metadata and severity for flat guards and nested branching.
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
     * @return void - Asserts that a flat fall-through builder retains Warning severity and branching metadata.
     */
    public function testFlatFallThroughBranchesAreNotDowngradedToAdvisory(): void
    {
        $findings = $this->analyse('guard-clauses.php', ['warning' => 4, 'error' => 14]);

        $bySymbol = [];
        foreach ($findings as $finding) {
            $bySymbol[$finding->symbol ?? ''] = $finding;
        }

        // telemetryBuilder is flat but falls through (no return/throw/exit), so it is branching complexity
        // rather than a guard clause and must not be downgraded to advisory at these thresholds.
        self::assertSame(Severity::Warning, $bySymbol['GuardClauseComplexityFixture::telemetryBuilder()']->severity ?? null);
        self::assertSame('branching', $bySymbol['GuardClauseComplexityFixture::telemetryBuilder()']->metadata['complexityShape'] ?? null);
    }

    /**
     * Analyse complexity fixtures and return findings for assertions.
     *
     * @param string             $fixture    - fixture filename under Fixtures/Complexity to parse and run.
     * @param array<string, int> $thresholds - Warning/error cutoffs controlling which fixture methods report.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - findings the rule emits under those thresholds, to assert on.
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            CyclomaticComplexityRule::ID,
            new RuleSettings(true, $thresholds),
        );

        return $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename - File to load from tests/Fixtures/Complexity.
     *
     * @return \GruffPhp\Engine\Parser\AnalysisUnit - parsed fixture carrying the repo-relative display path the rule reports findings against
     */
    private function parseFixture(string $filename): \GruffPhp\Engine\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $filename));
    }
}
