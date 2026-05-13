<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\NestingDepthRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers NestingDepthRuleTest behavior.
 */
final class NestingDepthRuleTest extends TestCase
{
    /** Rule instance under test. */
    private NestingDepthRule $rule;
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each rule test.
     *
     * @return void No return value.
     */
    protected function setUp(): void
    {
        $this->rule   = new NestingDepthRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function methodDepthProvider(): array
    {
        return [
            'flat method' => ['flat', 0],
            'one level' => ['oneLevel', 1],
            'four levels' => ['fourLevels', 4],
            'five levels' => ['fiveLevels', 5],
        ];
    }

    /**
     * Verify nesting depth matches expected.
     *
     * @param string $methodName Fixture method name.
     * @param int    $expectedDepth Expected nesting depth.
     * @return void No return value.
     */
    #[DataProvider('methodDepthProvider')]
    public function testNestingDepthMatchesExpected(string $methodName, int $expectedDepth): void
    {
        $unit    = $this->parseFixture('nesting.php');
        $finder  = new NodeFinder();
        $methods = $finder->findInstanceOf($unit->statements, ClassMethod::class);

        $method = null;

        foreach ($methods as $candidateMethod) {
            if ($candidateMethod->name->toString() === $methodName) {
                $method = $candidateMethod;

                break;
            }
        }

        self::assertNotNull($method, sprintf('Method %s not found in fixture.', $methodName));
        self::assertSame($expectedDepth, NestingDepthRule::computeMaximumNestingDepth($method));
    }

    /**
     * Verify no findings for shallow methods.
     *
     * @return void No return value.
     */
    public function testNoFindingsForShallowMethods(): void
    {
        $findings = $this->analyse('simple.php', ['warning' => 4, 'error' => 6]);

        self::assertSame([], $findings);
    }

    /**
     * Verify warning for deeply nested method.
     *
     * @return void No return value.
     */
    public function testWarningForDeeplyNestedMethod(): void
    {
        $findings = $this->analyse('nesting.php', ['warning' => 3, 'error' => 6]);

        self::assertNotSame([], $findings);
        self::assertSame(NestingDepthRule::ID, $findings[0]->ruleId);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    /**
     * Verify error for very deeply nested method.
     *
     * @return void No return value.
     */
    public function testErrorForVeryDeeplyNestedMethod(): void
    {
        $findings = $this->analyse('nesting.php', ['warning' => 3, 'error' => 4]);

        $errors = array_values(array_filter($findings, static fn ($finding) => $finding->severity === Severity::Error));
        self::assertNotSame([], $errors);
        self::assertSame('NestingFixture::fiveLevels()', $errors[0]->symbol);
    }

    /**
     * @param array<string, int> $thresholds
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit     = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config   = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            NestingDepthRule::ID,
            new RuleSettings(true, $thresholds),
        );

        return $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
    }

    /**
     * Parse the named fixture into an analysis unit.
     *
     * @param string $filename Fixture filename.
     * @return \GruffPhp\Parser\AnalysisUnit Fixture value.
     */
    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $filename));
    }
}
