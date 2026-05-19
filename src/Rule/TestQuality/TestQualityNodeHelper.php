<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Parser\AnalysisUnit;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Provides shared AST helpers for test-quality rules.
 */
final class TestQualityNodeHelper
{
    /** @var \WeakMap<AnalysisUnit, list<TestQualityScope>>|null */
    private static ?\WeakMap $scopeCache = null;

    /**
     * Detect whether the unit looks like a PHPUnit test file by path or filename.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit whose path should be classified.
     * @return bool True when the file lives under tests/ or has a Test/TestCase basename suffix.
     */
    public static function looksLikePhpUnitTestFile(AnalysisUnit $analysisUnit): bool
    {
        $displayPath = '/' . str_replace('\\', '/', $analysisUnit->file->displayPath);

        if (str_contains($displayPath, '/tests/') || str_contains($displayPath, '/Tests/')) {
            return true;
        }

        $basename = basename($analysisUnit->file->displayPath);

        return str_ends_with($basename, 'Test.php') || str_ends_with($basename, 'TestCase.php');
    }

    /**
     * Discover PHPUnit and Pest test scopes in an analysis unit.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect for test scopes.
     * @return list<TestQualityScope> Test scopes discovered in source order.
     */
    public static function testScopes(AnalysisUnit $analysisUnit): array
    {
        // 18 test-quality rules each call this for every PHP unit; cache so the AST walks once per unit.
        $cache = self::$scopeCache ??= new \WeakMap();

        if ($cache->offsetExists($analysisUnit)) {
            return $cache->offsetGet($analysisUnit);
        }

        $nodeFinder = new NodeFinder();
        $scopes     = [];

        foreach ($nodeFinder->findInstanceOf($analysisUnit->statements, Stmt\ClassMethod::class) as $classMethod) {
            if (!self::isTestMethod($classMethod)) {
                continue;
            }

            $class      = self::parentClass($classMethod);
            $className  = $class?->name?->toString();
            $methodName = $classMethod->name->toString();

            $scopes[] = new TestQualityScope(
                symbol:     ($className === null ? 'anonymous' : $className) . '::' . $methodName . '()',
                name:       $methodName,
                line:       $classMethod->getStartLine(),
                endLine:    $classMethod->getEndLine(),
                statements: array_values($classMethod->stmts ?? []),
                node:       $classMethod,
                isPest:     false,
                className:  $className,
            );
        }

        foreach ($nodeFinder->findInstanceOf($analysisUnit->statements, Expr\FuncCall::class) as $call) {
            $name = self::functionName($call);
            if (($name !== 'it' && $name !== 'test') || count($call->args) < 2) {
                continue;
            }

            if (!$call->args[0] instanceof Arg || !$call->args[1] instanceof Arg) {
                continue;
            }

            $description = self::argString($call->args[0]) ?? $name;
            $closure     = $call->args[1]->value;
            if (!$closure instanceof Expr\Closure) {
                continue;
            }

            $scopes[] = new TestQualityScope(
                symbol:     sprintf('pest:%s', $description),
                name:       $description,
                line:       $call->getStartLine(),
                endLine:    $call->getEndLine(),
                statements: array_values($closure->stmts),
                node:       $call,
                isPest:     true,
            );
        }

        $cache->offsetSet($analysisUnit, $scopes);

        return $scopes;
    }

    /**
     * Detect whether the method is a PHPUnit test (Test attribute, @test annotation, or test*-prefix on a TestCase subclass).
     *
     * @param Stmt\ClassMethod $classMethod Method node to classify.
     * @return bool True when the method should be analysed as a test body.
     */
    public static function isTestMethod(Stmt\ClassMethod $classMethod): bool
    {
        if (self::hasAttribute($classMethod, 'Test')) {
            return true;
        }

        if (self::hasTestAnnotation($classMethod)) {
            return true;
        }

        $name = $classMethod->name->toString();

        if (!str_starts_with($name, 'test')) {
            return false;
        }

        // The bare `test*` prefix only counts as a PHPUnit test method when the enclosing
        // class extends a *TestCase base. This stops library code with method names like
        // testScopes()/testCandidate() being analysed as test bodies.
        return self::extendsTestCase(self::parentClass($classMethod));
    }

    /**
     * Detect a real PHPUnit `@test` annotation line.
     *
     * @return bool True when the method docblock declares `@test` as a tag.
     */
    private static function hasTestAnnotation(Stmt\ClassMethod $classMethod): bool
    {
        $docText = $classMethod->getDocComment()?->getText() ?? '';

        // Detect PHPUnit's @test tag inside a method docblock line.
        return preg_match('/^\s*\*\s*@test\b/m', $docText) === 1;
    }

    /**
     * Detect whether the class extends a *TestCase base.
     *
     * @param Stmt\Class_|null $class Class node to inspect, or null when the method is detached.
     * @return bool True when the parent name ends with `testcase` (case-insensitive).
     */
    public static function extendsTestCase(?Stmt\Class_ $class): bool
    {
        if ($class === null || $class->extends === null) {
            return false;
        }

        $parent = strtolower($class->extends->getLast());

        return str_ends_with($parent, 'testcase');
    }

    /**
     * Collect function, method, and static calls from a test scope.
     *
     * @param TestQualityScope $scope Test scope whose statements should be walked.
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> Calls found in the scope.
     */
    public static function calls(TestQualityScope $scope): array
    {
        $nodeFinder = new NodeFinder();

        return array_values(array_filter(
            $nodeFinder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall),
            static fn (Node $node): bool => $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall,
        ));
    }

    /**
     * Collect assertion-like calls from a test scope.
     *
     * @param TestQualityScope $scope Test scope whose calls should be filtered.
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> Assertion calls found in the scope.
     */
    public static function assertionCalls(TestQualityScope $scope): array
    {
        return array_values(array_filter(
            self::calls($scope),
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool => self::isAssertionCall($call),
        ));
    }

    /**
     * Detect whether the call is a PHPUnit assertion, Pest expectation, or other supported assertion shape.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Call node to classify.
     * @return bool True when the call counts as an assertion for test-quality rules.
     */
    public static function isAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);
        if ($name === null) {
            return false;
        }

        if (str_starts_with($name, 'assert') || $name === 'fail') {
            return true;
        }

        if (in_array($name, [
            'expect',
            'expectexception',
            'expectexceptionmessage',
            'expectexceptionmessagematches',
            'expectexceptioncode',
            'expectexceptionobject',
            'expectoutputstring',
            'expectoutputregex',
            'expecterror',
            'expecterrormessage',
            'expecterrormessagematches',
            'expectwarning',
            'expectwarningmessage',
            'expectwarningmessagematches',
            'expectnotice',
            'expectnoticemessage',
            'expectnoticemessagematches',
            'expectdeprecation',
            'expectdeprecationmessage',
            'expectdeprecationmessagematches',
            'expectnottoperformassertions',
        ], true)) {
            return true;
        }

        return in_array($name, [
            'tobe',
            'tobearray',
            'tobebetween',
            'tobebool',
            'tobecallable',
            'tobeempty',
            'tobeeven',
            'tobefalse',
            'tobefalsy',
            'tobefloat',
            'tobegreaterthan',
            'tobegreaterthanorequal',
            'tobeinfinite',
            'tobeinstanceof',
            'tobeint',
            'tobeiterable',
            'tobelessthan',
            'tobelessthanorequal',
            'tobenan',
            'tobenegative',
            'tobenull',
            'tobenumeric',
            'tobeobject',
            'tobeodd',
            'tobepositive',
            'tobescalar',
            'tobestring',
            'tobetrue',
            'tobetruthy',
            'tocontain',
            'tocontainequal',
            'tocontainonly',
            'tocontainonlyinstancesof',
            'toendwith',
            'toequal',
            'toequalcanonicalizing',
            'toequalwithdelta',
            'tohavecount',
            'tohavekey',
            'tohavekeys',
            'tohavelength',
            'tohaveproperties',
            'tohaveproperty',
            'tohavesamesize',
            'tomatch',
            'tomatcharray',
            'tomatchfilesnapshot',
            'tomatchobject',
            'tomatchsnapshot',
            'tostartwith',
            'tothrow',
            'tothrowif',
            'tothrowunless',
        ], true);
    }

    /**
     * Detect whether the assertion is trivially-true (e.g. assertTrue(true), assertSame($x, $x)).
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Assertion call to inspect.
     * @return bool True when the assertion's literal arguments make it tautological.
     */
    public static function isTrivialAssertion(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);
        if ($name === null) {
            return false;
        }

        return match ($name) {
            'asserttrue' => self::literalValue(self::firstArgValue($call)) === true,
            'assertfalse' => self::literalValue(self::firstArgValue($call)) === false,
            'tobetrue' => $call instanceof Expr\MethodCall
                && self::literalValue(self::pestExpectationValue($call)) === true,
            'tobefalse' => $call instanceof Expr\MethodCall
                && self::literalValue(self::pestExpectationValue($call)) === false,
            'assertequals', 'assertsame' => self::hasSameLiteralArguments($call),
            'tobe', 'toequal' => $call instanceof Expr\MethodCall && self::hasSamePestLiteralArguments($call),
            default => false,
        };
    }

    /**
     * Detect whether the call's first two arguments are the same literal value.
     *
     * @return bool True when both arguments resolve to the same scalar literal.
     */
    private static function hasSameLiteralArguments(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $expected = self::literalValue(self::firstArgValue($call));

        return $expected !== null && $expected === self::literalValue(self::argValue($call, 1));
    }

    /**
     * Detect whether a Pest expectation's literal argument matches the expected value.
     *
     * @return bool True when expect($x)->toBe($x) has equal literal arguments.
     */
    private static function hasSamePestLiteralArguments(Expr\MethodCall $call): bool
    {
        $expected = self::literalValue(self::firstArgValue($call));

        return $expected !== null && $expected === self::literalValue(self::pestExpectationValue($call));
    }

    /**
     * Lowercase short name of the call (method, function, or static), or null when the name is dynamic.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Call node whose name should be normalized.
     * @return string|null The lowercase identifier, or null when the call target is a variable / expression.
     */
    public static function callName(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        if ($call instanceof Expr\FuncCall) {
            return self::functionName($call);
        }

        if ($call->name instanceof Identifier) {
            return strtolower($call->name->toString());
        }

        return null;
    }

    /**
     * Lowercase function name, or null when the call target is not a Name node.
     *
     * @param Expr\FuncCall $call Function call node to inspect.
     * @return string|null
     */
    public static function functionName(Expr\FuncCall $call): ?string
    {
        if ($call->name instanceof Name) {
            return strtolower($call->name->toString());
        }

        return null;
    }

    /**
     * Detect whether the call creates a mock, stub, or spy via a recognised factory name.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Call node to classify.
     * @return bool True when the call name is one of createMock / createStub / getMockBuilder / mock / partialMock / spy / prophesize.
     */
    public static function isMockCreationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);

        return $name !== null && in_array($name, [
            'createmock',
            'createstub',
            'getmockbuilder',
            'mock',
            'partialmock',
            'spy',
            'prophesize',
        ], true);
    }

    /**
     * Detect whether the call wires a mock expectation (expects, shouldReceive, once, etc.).
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Call node to classify.
     * @return bool True when the call name matches a mock-verification idiom.
     */
    public static function isMockVerificationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);

        return $name !== null && in_array($name, [
            'expects',
            'shouldreceive',
            'shouldhavebeencalled',
            'once',
            'never',
            'with',
            'method',
        ], true);
    }

    /**
     * Value of the call's first argument, or null when the call has no first argument.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Call node to inspect.
     * @return Expr|null
     */
    public static function firstArgValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?Expr
    {
        return self::argValue($call, 0);
    }

    /**
     * Value of the call's argument at the given index, or null when missing or spread.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call  Call node to inspect.
     * @param int                                           $index Zero-based argument index.
     * @return Expr|null
     */
    public static function argValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?Expr
    {
        if (!isset($call->args[$index]) || !$call->args[$index] instanceof Arg) {
            return null;
        }

        return $call->args[$index]->value;
    }

    /**
     * String literal value of the argument, or null when the argument is not a string literal.
     *
     * @param Arg $arg Argument node to inspect.
     * @return string|null
     */
    public static function argString(Arg $arg): ?string
    {
        return $arg->value instanceof Scalar\String_ ? $arg->value->value : null;
    }

    /**
     * Resolve the literal value of an expression (scalar literal or const true/false/null), or null when not a literal.
     *
     * @param Expr|null $expr Expression to resolve.
     * @return bool|int|float|string|null
     */
    public static function literalValue(?Expr $expr): bool|int|float|string|null
    {
        if ($expr instanceof Scalar\String_ || $expr instanceof Scalar\LNumber || $expr instanceof Scalar\DNumber) {
            return $expr->value;
        }

        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());

            return match ($name) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => null,
            };
        }

        return null;
    }

    /**
     * Walk a Pest expectation chain back to the expect()'d value.
     *
     * @param Expr\MethodCall $call Pest expectation method call to unwind.
     * @return Expr|null The expression originally wrapped by expect(...), or null when the chain doesn't start with expect().
     */
    public static function pestExpectationValue(Expr\MethodCall $call): ?Expr
    {
        $receiver = $call->var;

        if ($receiver instanceof Expr\FuncCall && self::functionName($receiver) === 'expect') {
            return self::firstArgValue($receiver);
        }

        if ($receiver instanceof Expr\MethodCall) {
            return self::pestExpectationValue($receiver);
        }

        return null;
    }

    /**
     * Detect whether the method carries an attribute matching the given short name (case-insensitive).
     *
     * @param Stmt\ClassMethod $node      Method node whose attributes should be inspected.
     * @param string           $shortName Attribute short name to match case-insensitively.
     * @return bool True when at least one #[...] attribute group has an attribute whose last name segment matches.
     */
    public static function hasAttribute(Stmt\ClassMethod $node, string $shortName): bool
    {
        foreach ($node->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if (strtolower($attribute->name->getLast()) === strtolower($shortName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the enclosing Class_ node via the `parent` AST attribute, or null when unattached.
     *
     * @param Node $node Node whose parent chain should be inspected.
     * @return Stmt\Class_|null
     */
    public static function parentClass(Node $node): ?Stmt\Class_
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Stmt\Class_ ? $parent : null;
    }

    /**
     * Strip the test* prefix and non-alphanumeric chars from a test method name; lowercase the result.
     *
     * @param string $name Test method name to normalize.
     * @return string The normalised form used for cross-method comparisons.
     */
    public static function normalizedTestName(string $name): string
    {
        $name = preg_replace('/^test[_]?/i', '', $name) ?? $name;

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));
    }

    /**
     * Detect a non-trivial integer literal used as the assertion's expected value, or null when none.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call Assertion call to inspect.
     * @return int|null The literal expected value, ignoring -1 / 0 / 1 as not-magic.
     */
    public static function isAssertionMagicNumber(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?int
    {
        $name = self::callName($call);
        if ($name === null || !self::isAssertionCall($call)) {
            return null;
        }

        $candidate = $call instanceof Expr\MethodCall && in_array($name, ['tobe', 'toequal', 'tohavecount'], true)
            ? self::firstArgValue($call)
            : self::argValue($call, 0);

        $literalValue = self::literalValue($candidate);

        return is_int($literalValue) && !in_array($literalValue, [-1, 0, 1], true) ? $literalValue : null;
    }

}
