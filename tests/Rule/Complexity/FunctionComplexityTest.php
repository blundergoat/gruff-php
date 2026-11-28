<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Complexity\FunctionComplexity;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shared function reading other rules rely on: the full cyclomatic count per fixture method,
 * which the maintainability index reads, and the test for a body worth measuring.
 */
final class FunctionComplexityTest extends TestCase
{
    /** Parser used to load fixture files. */
    private PhpFileParser $parser;

    /**
     * Give each test an independent parser.
     *
     * @return void - Installs a fresh parser for the next test.
     */
    protected function setUp(): void
    {
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
        self::assertSame($expectedCcn, FunctionComplexity::computeCyclomaticComplexity($method));
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
            $hasBodyByName[$method->name->toString()] = FunctionComplexity::hasExecutableBody($method);
        }

        self::assertFalse($hasBodyByName['declaredCount'] ?? null, 'Interface method has no body.');
        self::assertFalse($hasBodyByName['abstractTotal'] ?? null, 'Abstract method has no body.');
        self::assertTrue($hasBodyByName['concreteTotal'] ?? null, 'Concrete method has a body.');
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
