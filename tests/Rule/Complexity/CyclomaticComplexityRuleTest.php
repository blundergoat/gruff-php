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
 * Covers CyclomaticComplexityRuleTest behavior.
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
            CyclomaticComplexityRule::ID,
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
