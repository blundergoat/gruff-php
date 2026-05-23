<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\TestQuality\TestQualityNodeHelper;
use GruffPhp\Rule\TestQuality\TestQualityScope;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\TestCase;

/**
 * Covers test-file detection, PHPUnit/Pest scope discovery, method classification (attributes/annotations/parents/detached), assertion-call recognition, argument-literal helpers, and mock creation/verification detection.
 */
final class TestQualityNodeHelperTest extends TestCase
{
    /** Parser used to load helper fixture files. */
    private PhpFileParser $parser;

    /**
     * Prepare parser fixtures before each helper test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
    }

    /**
     * Verify PHPUnit file detection handles paths and conventional suffixes.
     *
     * @return void
     */
    public function testLooksLikePhpUnitTestFileRecognisesPathsAndSuffixes(): void
    {
        self::assertTrue(TestQualityNodeHelper::looksLikePhpUnitTestFile($this->unitWithDisplayPath('tests/Unit/Example.php')));
        self::assertTrue(TestQualityNodeHelper::looksLikePhpUnitTestFile($this->unitWithDisplayPath('src\\Tests\\Example.php')));
        self::assertTrue(TestQualityNodeHelper::looksLikePhpUnitTestFile($this->unitWithDisplayPath('src/Feature/ExampleTest.php')));
        self::assertTrue(TestQualityNodeHelper::looksLikePhpUnitTestFile($this->unitWithDisplayPath('src/Feature/ExampleTestCase.php')));
        self::assertFalse(TestQualityNodeHelper::looksLikePhpUnitTestFile($this->unitWithDisplayPath('src/Feature/Example.php')));
    }

    /**
     * Verify PHPUnit and Pest scopes are discovered in source order.
     *
     * @return void
     */
    public function testScopesDiscoversPhpUnitAndPestTestsInSourceOrder(): void
    {
        $unit         = $this->parseFixture();
        $scopes       = TestQualityNodeHelper::testScopes($unit);
        $cachedScopes = TestQualityNodeHelper::testScopes($unit);

        self::assertSame($scopes, $cachedScopes);
        self::assertSame([
            'HelperEdgeCaseTest::attributeStyle()',
            'HelperEdgeCaseTest::annotationStyle()',
            'HelperEdgeCaseTest::testPrefixStyle()',
            'HelperEdgeCaseTest::testTrivialAssertions()',
            'HelperEdgeCaseTest::testLiteralAndMagicNumbers()',
            'HelperEdgeCaseTest::testMockApi()',
            'anonymous::anonymousExample()',
            'pest:pest description',
            'pest:explicit pest test',
        ], array_map(static fn (TestQualityScope $scope): string => $scope->symbol, $scopes));
        self::assertSame('attributeStyle', $scopes[0]->name);
        self::assertSame('HelperEdgeCaseTest', $scopes[0]->className);
        self::assertFalse($scopes[0]->isPest);
        self::assertSame('anonymousExample', $scopes[6]->name);
        self::assertNull($scopes[6]->className);
        self::assertFalse($scopes[6]->isPest);
        self::assertSame('pest description', $scopes[7]->name);
        self::assertNull($scopes[7]->className);
        self::assertTrue($scopes[7]->isPest);
        self::assertGreaterThanOrEqual(1, $scopes[0]->lineCount());
    }

    /**
     * Verify test method classification requires an attribute, annotation, or TestCase parent.
     *
     * @return void
     */
    public function testMethodClassificationHandlesAttributesAnnotationsParentsAndDetachedNodes(): void
    {
        $scopes           = $this->scopesByName();
        $attributeMethod  = $this->methodNode($scopes['attributeStyle']);
        $annotationMethod = $this->methodNode($scopes['annotationStyle']);
        $testPrefixMethod = $this->methodNode($scopes['testPrefixStyle']);
        $classMethod      = new Stmt\ClassMethod('testDetached');

        self::assertTrue(TestQualityNodeHelper::hasAttribute($attributeMethod, 'test'));
        self::assertFalse(TestQualityNodeHelper::hasAttribute($attributeMethod, 'dataProvider'));
        self::assertTrue(TestQualityNodeHelper::isTestMethod($attributeMethod));
        self::assertTrue(TestQualityNodeHelper::isTestMethod($annotationMethod));
        self::assertTrue(TestQualityNodeHelper::isTestMethod($testPrefixMethod));
        self::assertSame('HelperEdgeCaseTest', TestQualityNodeHelper::parentClass($testPrefixMethod)?->name?->toString());
        self::assertTrue(TestQualityNodeHelper::extendsTestCase(TestQualityNodeHelper::parentClass($testPrefixMethod)));
        self::assertFalse(TestQualityNodeHelper::isTestMethod($classMethod));
        self::assertNull(TestQualityNodeHelper::parentClass($classMethod));
        self::assertFalse(TestQualityNodeHelper::extendsTestCase(null));
    }

    /**
     * Verify calls and assertion calls are classified with lower-case names.
     *
     * @return void
     */
    public function testCallsAndAssertionCallsClassifyNames(): void
    {
        $scope          = $this->scopesByName()['testPrefixStyle'];
        $calls          = TestQualityNodeHelper::calls($scope);
        $assertionCalls = TestQualityNodeHelper::assertionCalls($scope);

        self::assertSame(['helper', 'asserttrue', 'assertequals', 'assertsame', 'fail'], array_map(
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string => TestQualityNodeHelper::callName($call),
            $calls,
        ));
        self::assertSame(['asserttrue', 'assertequals', 'assertsame', 'fail'], array_map(
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string => TestQualityNodeHelper::callName($call),
            $assertionCalls,
        ));
        self::assertFalse(TestQualityNodeHelper::isAssertionCall($calls[0]));
    }

    /**
     * Verify trivial assertion detection covers PHPUnit and Pest literal idioms.
     *
     * @return void
     */
    public function testTrivialAssertionDetectionCoversPhpUnitAndPestLiterals(): void
    {
        $scope   = $this->scopesByName()['testTrivialAssertions'];
        $results = [];

        foreach (TestQualityNodeHelper::assertionCalls($scope) as $call) {
            $name = TestQualityNodeHelper::callName($call);
            if ($name === 'expect') {
                continue;
            }

            self::assertIsString($name, 'every iterated assertion call should resolve to a string name');
            $results[] = [$name, TestQualityNodeHelper::isTrivialAssertion($call)];
        }

        self::assertSame([
            ['asserttrue', true],
            ['assertfalse', true],
            ['assertequals', true],
            ['assertsame', true],
            ['assertsame', false],
            ['tobetrue', true],
            ['tobefalse', true],
            ['tobe', true],
            ['toequal', true],
            ['tobe', false],
            ['tobetrue', false],
            ['tobefalse', false],
        ], $results);
    }

    /**
     * Verify the assertion detector recognises the expanded Pest expectation API.
     *
     * @return void
     */
    public function testAssertionCallDetectionCoversExpandedPestExpectations(): void
    {
        $path   = __DIR__ . '/../../Fixtures/TestQuality/pest-expanded-expectations.php';
        $unit   = $this->parser->parse(new SourceFile($path, 'tests/Fixtures/TestQuality/pest-expanded-expectations.php'));
        $scopes = TestQualityNodeHelper::testScopes($unit);

        self::assertCount(1, $scopes, 'fixture should contain exactly one Pest scope');

        $detected = [];
        $missed   = [];

        foreach (TestQualityNodeHelper::calls($scopes[0]) as $call) {
            $name = TestQualityNodeHelper::callName($call);
            if ($name === null || $name === 'expect') {
                continue;
            }

            if (TestQualityNodeHelper::isAssertionCall($call)) {
                $detected[] = $name;

                continue;
            }

            $missed[] = $name;
        }

        self::assertSame([], $missed, 'every Pest expectation in the fixture should be recognised as an assertion');
        self::assertGreaterThanOrEqual(40, count($detected), 'fixture should exercise a broad set of expanded Pest expectations');
        self::assertContains('tobenull', $detected);
        self::assertContains('tobeinstanceof', $detected);
        self::assertContains('tohavekey', $detected);
        self::assertContains('tomatch', $detected);
        self::assertContains('tostartwith', $detected);
        self::assertContains('toendwith', $detected);
        self::assertContains('tothrowif', $detected);
    }

    /**
     * Verify argument, literal, Pest-chain, and magic-number helpers.
     *
     * @return void
     */
    public function testArgumentLiteralPestAndMagicNumberHelpers(): void
    {
        $scope       = $this->scopesByName()['testLiteralAndMagicNumbers'];
        $calls       = TestQualityNodeHelper::calls($scope);
        $helperCall  = $this->firstNamedCall($calls, 'helper');
        $dynamicCall = array_values(array_filter(
            $calls,
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool => $call instanceof Expr\FuncCall
                && TestQualityNodeHelper::functionName($call) === null,
        ))[0] ?? null;
        $integerHelperCall = array_values(array_filter(
            $calls,
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool => TestQualityNodeHelper::callName($call) === 'helper'
                && TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($call)) === 2,
        ))[0] ?? null;
        $unknownConstantCall = array_values(array_filter(
            $calls,
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool => TestQualityNodeHelper::callName($call) === 'helper'
                && TestQualityNodeHelper::firstArgValue($call) instanceof Expr\ConstFetch
                && TestQualityNodeHelper::firstArgValue($call)->name->toString() === 'MAYBE',
        ))[0] ?? null;

        self::assertInstanceOf(Expr\FuncCall::class, $helperCall);
        self::assertInstanceOf(Expr\FuncCall::class, $integerHelperCall);
        self::assertInstanceOf(Expr\FuncCall::class, $unknownConstantCall);
        self::assertInstanceOf(Expr\FuncCall::class, $dynamicCall);
        self::assertInstanceOf(Arg::class, $helperCall->args[0]);
        $nonStringArgument = $helperCall->args[1];
        self::assertInstanceOf(Arg::class, $nonStringArgument);
        self::assertSame('literal', TestQualityNodeHelper::argString($helperCall->args[0]));
        self::assertNull(TestQualityNodeHelper::argString($nonStringArgument));
        self::assertSame('literal', TestQualityNodeHelper::literalValue(TestQualityNodeHelper::argValue($helperCall, 0)));
        self::assertTrue(TestQualityNodeHelper::literalValue(TestQualityNodeHelper::argValue($helperCall, 1)));
        self::assertFalse(TestQualityNodeHelper::literalValue(TestQualityNodeHelper::argValue($helperCall, 2)));
        self::assertNull(TestQualityNodeHelper::literalValue(TestQualityNodeHelper::argValue($helperCall, 3)));
        self::assertNull(TestQualityNodeHelper::literalValue(TestQualityNodeHelper::argValue($helperCall, 4)));
        self::assertNull(TestQualityNodeHelper::argValue($helperCall, 99));
        self::assertNull(TestQualityNodeHelper::functionName($dynamicCall));
        self::assertSame('dynamic', TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($dynamicCall)));
        self::assertFalse(TestQualityNodeHelper::isAssertionCall($dynamicCall));
        self::assertNull(TestQualityNodeHelper::literalValue(TestQualityNodeHelper::firstArgValue($unknownConstantCall)));
        self::assertNull(TestQualityNodeHelper::isAssertionMagicNumber($integerHelperCall));

        $magicNumbers = array_values(array_filter(array_map(
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?int => TestQualityNodeHelper::isAssertionMagicNumber($call),
            TestQualityNodeHelper::assertionCalls($scope),
        ), static fn (?int $magicNumber): bool => $magicNumber !== null));

        self::assertSame([2, 4, 3], $magicNumbers);

        $pestTerminal = array_values(array_filter(
            $calls,
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool => $call instanceof Expr\MethodCall
                && TestQualityNodeHelper::callName($call) === 'tohavecount',
        ))[0] ?? null;

        self::assertInstanceOf(Expr\MethodCall::class, $pestTerminal);
        self::assertInstanceOf(Expr\Variable::class, TestQualityNodeHelper::pestExpectationValue($pestTerminal));
        self::assertSame('calculatetotalreturnsexpectedstatus', TestQualityNodeHelper::normalizedTestName('test_calculate-total returns expected status'));
    }

    /**
     * Verify mock creation and verification helper lists stay intentional.
     *
     * @return void
     */
    public function testMockCreationAndVerificationHelpers(): void
    {
        $scope             = $this->scopesByName()['testMockApi'];
        $calls             = TestQualityNodeHelper::calls($scope);
        $mockCreations     = [];
        $mockVerifications = [];

        foreach ($calls as $call) {
            $name = TestQualityNodeHelper::callName($call);
            self::assertIsString($name, 'every mock-API call should resolve to a string name');

            if (TestQualityNodeHelper::isMockCreationCall($call)) {
                $mockCreations[] = $name;
            }

            if (TestQualityNodeHelper::isMockVerificationCall($call)) {
                $mockVerifications[] = $name;
            }
        }

        sort($mockVerifications);

        self::assertSame([
            'createmock',
            'createstub',
            'getmockbuilder',
            'mock',
            'partialmock',
            'spy',
            'prophesize',
        ], $mockCreations);
        self::assertSame([
            'expects',
            'method',
            'never',
            'once',
            'shouldhavebeencalled',
            'shouldreceive',
            'with',
        ], $mockVerifications);
        self::assertFalse(TestQualityNodeHelper::isMockCreationCall($this->firstNamedCall($calls, 'run')));
        self::assertFalse(TestQualityNodeHelper::isMockVerificationCall($this->firstNamedCall($calls, 'run')));
    }

    /**
     * Parse the helper fixture.
     *
     * @return AnalysisUnit Parsed fixture.
     */
    private function parseFixture(): AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/TestQuality/test-quality-node-helper.php';

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/TestQuality/test-quality-node-helper.php'));
    }

    /**
     * Create an empty analysis unit with a display path.
     *
     * @param string $displayPath Project-relative display path.
     * @return AnalysisUnit Empty unit.
     */
    private function unitWithDisplayPath(string $displayPath): AnalysisUnit
    {
        return new AnalysisUnit(new SourceFile(__FILE__, $displayPath), '', [], [], []);
    }

    /**
     * Return helper fixture scopes keyed by their local name.
     *
     * @return array<string, TestQualityScope>
     */
    private function scopesByName(): array
    {
        $scopes = [];

        foreach (TestQualityNodeHelper::testScopes($this->parseFixture()) as $scope) {
            $scopes[$scope->name] = $scope;
        }

        return $scopes;
    }

    /**
     * Return the class method node owned by a scope.
     *
     * @param TestQualityScope $scope Scope to inspect.
     * @return Stmt\ClassMethod Scope method node.
     */
    private function methodNode(TestQualityScope $scope): Stmt\ClassMethod
    {
        self::assertInstanceOf(Stmt\ClassMethod::class, $scope->node);

        return $scope->node;
    }

    /**
     * Return the first call with the requested normalised name.
     *
     * @param list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> $calls Calls to inspect.
     * @param string                                              $name  Normalised call name.
     * @return Expr\FuncCall|Expr\MethodCall|Expr\StaticCall Matching call.
     */
    private function firstNamedCall(array $calls, string $name): Expr\FuncCall|Expr\MethodCall|Expr\StaticCall
    {
        foreach ($calls as $call) {
            if (TestQualityNodeHelper::callName($call) === $name) {
                return $call;
            }
        }

        self::fail(sprintf('Call "%s" was not found.', $name));
    }
}
