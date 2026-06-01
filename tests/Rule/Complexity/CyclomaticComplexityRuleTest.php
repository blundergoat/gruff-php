<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
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
     * Prepare parser fixtures before each rule test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule   = new CyclomaticComplexityRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * Provide method ccn cases for parameterized tests.
     *
     * @return array<string, array{string, int}>
     */
    public static function methodCcnProvider(): array
    {
        // Each row pairs a fixture method with its expected ccn; the oracle the counter is checked against.
        return [
            'flat method' => ['flat', 1],
            'if/elseif' => ['ifElseIf', 3],
            'loop with condition' => ['loopWithCondition', 4],
            'switch block' => ['switchBlock', 4],
            'match block' => ['matchBlock', 4],
            'mixed operators' => ['mixedOperators', 7],
            'try/catch loop' => ['tryCatchLoop', 4],
        ];
    }

    /**
     * Verify cyclomatic count matches expected.
     *
     * @param string $methodName  Fixture method name.
     * @param int    $expectedCcn Expected cyclomatic complexity.
     * @return void
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
     * Verify no findings for simple methods.
     *
     * @return void
     */
    public function testNoFindingsForSimpleMethods(): void
    {
        $findings = $this->analyse('simple.php', ['warning' => 10, 'error' => 20]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for method above threshold.
     *
     * @return void
     */
    public function testWarningForMethodAboveThreshold(): void
    {
        $findings = $this->analyse('cyclomatic.php', ['warning' => 3, 'error' => 20]);

        self::assertNotSame([], $findings);

        $ruleIds             = array_values(array_unique(array_map(static fn ($finding): string => $finding->ruleId, $findings)));
        $complexities        = array_map(static fn ($finding): mixed => $finding->metadata['complexity'] ?? null, $findings);
        $invalidComplexities = array_values(array_filter(
            $complexities,
            static fn (mixed $complexity): bool => !is_int($complexity) || $complexity <= 3,
        ));

        self::assertSame([CyclomaticComplexityRule::ID], $ruleIds);
        self::assertSame([], $invalidComplexities);
    }

    /**
     * Verify error for method above error threshold.
     *
     * @return void
     */
    public function testErrorForMethodAboveErrorThreshold(): void
    {
        $findings = $this->analyse('cyclomatic.php', ['warning' => 3, 'error' => 4]);

        $errors = array_values(array_filter($findings, static fn ($finding) => $finding->severity === Severity::Error));

        self::assertNotSame([], $errors);
    }

    /**
     * Verify hasExecutableBody separates bodyless signatures from real bodies (P6).
     *
     * This is the shared predicate the cyclomatic, cognitive, and nesting-depth
     * rules use to skip declarations with no control flow, so a regression here
     * would let any of them measure an abstract or interface method.
     *
     * @return void
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
     * Verify bodyless declarations are skipped even at a zero threshold (P6).
     *
     * A warning threshold of 0 would flag every measured node, since base
     * cyclomatic complexity is 1, so without the bodyless guard the abstract and
     * interface methods would each report a finding. Only the concrete method is
     * reported, proving the rule filters bodyless signatures rather than leaning
     * on "no body folds to baseline complexity".
     *
     * @return void
     */
    public function testBodylessDeclarationsAreNotMeasured(): void
    {
        $findings = $this->analyse('bodyless.php', ['warning' => 0, 'error' => 20]);

        $symbols = array_map(static fn ($finding): ?string => $finding->symbol, $findings);

        self::assertSame(['BodylessFixture::concreteTotal()'], $symbols);
    }

    /**
     * Analyse complexity fixtures and return findings for assertions.
     *
     * @param string             $fixture    - fixture filename under Fixtures/Complexity to parse and run.
     * @param array<string, int> $thresholds - warning/error cutoffs keyed by level; sets where the rule starts
     *   emitting, so a test can force or suppress findings on the same fixture.
     * @return list<\GruffPhp\Finding\Finding> - findings the rule emits under those thresholds, to assert on.
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            CyclomaticComplexityRule::ID,
            new RuleSettings(true, $thresholds),
        );

        // Run the single rule under the test-supplied thresholds so each case controls its own finding boundary.
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

        // Parsed unit carries the display path the rule reports findings against, so keep it relative to the repo root.
        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $filename));
    }
}
