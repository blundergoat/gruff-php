<?php

declare(strict_types=1);

namespace GruffPhp\Rules\TestQuality;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose path should be classified.
     *
     * @return bool - true when the file lives under tests/ or has a Test/TestCase basename suffix
     */
    public static function looksLikePhpUnitTestFile(AnalysisUnit $analysisUnit): bool
    {
        $displayPath = '/' . str_replace('\\', '/', $analysisUnit->file->displayPath);

        // User view: choose the findings list branch for this case.
        if (str_contains($displayPath, '/tests/') || str_contains($displayPath, '/Tests/')) {
            // A tests/ directory is the strongest signal; classify without inspecting the basename.
            return true;
        }

        $basename = basename($analysisUnit->file->displayPath);

        return str_ends_with($basename, 'Test.php') || str_ends_with($basename, 'TestCase.php');
    }

    /**
     * Discover PHPUnit and Pest test scopes in an analysis unit.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect for test scopes.
     *
     * @return list<TestQualityScope> - test scopes discovered in source order; empty when the unit has no tests
     */
    public static function testScopes(AnalysisUnit $analysisUnit): array
    {
        // 18 test-quality rules each call this for every PHP unit; cache so the AST walks once per unit.
        $cache = self::$scopeCache ??= new \WeakMap();

        // User view: choose the findings list branch for this case.
        if ($cache->offsetExists($analysisUnit)) {
            // Reuse the memoised scopes; recomputing would re-walk the whole AST for no gain.
            return $cache->offsetGet($analysisUnit);
        }

        $scopes = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\ClassMethod::class) as $classMethod) {
            // User view: choose the findings list branch for this case.
            if (!self::isTestMethod($classMethod)) {
                continue;
            }

            $class      = self::parentClass($classMethod);
            $className  = $class?->name?->toString();
            $methodName = $classMethod->name->toString();

            $scopes[] = new TestQualityScope(
                // User view: missing data becomes the expected findings list state.
                symbol:     ($className === null ? 'anonymous' : $className) . '::' . $methodName . '()',
                name:       $methodName,
                line:       $classMethod->getStartLine(),
                endLine:    $classMethod->getEndLine(),
                // User view: missing data becomes a safe findings list default.
                statements: array_values($classMethod->stmts ?? []),
                node:       $classMethod,
                isPest:     false,
                className:  $className,
            );
        }

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = self::functionName($call);
            // User view: choose the findings list branch for this case.
            if (($name !== 'it' && $name !== 'test') || count($call->args) < 2) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (!$call->args[0] instanceof Arg || !$call->args[1] instanceof Arg) {
                continue;
            }

            // User view: missing data becomes a safe findings list default.
            $description = self::argString($call->args[0]) ?? $name;
            $closure     = $call->args[1]->value;
            // User view: choose the findings list branch for this case.
            if (!$closure instanceof Expr\Closure) {
                continue;
            }

            $scopes[] = new TestQualityScope(
                symbol:     sprintf('pest:%s', $description),
                name:       $description,
                line:       $call->getStartLine(),
                endLine:    $call->getEndLine(),
                statements: array_values($closure->stmts),
                node:       $closure,
                isPest:     true,
            );
        }

        $cache->offsetSet($analysisUnit, $scopes);

        return $scopes;
    }

    /**
     * Detect whether the method is a PHPUnit test (Test attribute, @test annotation, or test*-prefix on a TestCase subclass).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassMethod $classMethod - Method node to classify.
     *
     * @return bool - true when the method should be analysed as a test body
     */
    public static function isTestMethod(Stmt\ClassMethod $classMethod): bool
    {
        // User view: choose the findings list branch for this case.
        if (self::hasAttribute($classMethod, 'Test')) {
            // An explicit #[Test] attribute is authoritative regardless of method name or base class.
            return true;
        }

        // User view: choose the findings list branch for this case.
        if (self::hasTestAnnotation($classMethod)) {
            // A @test docblock tag is equally authoritative, independent of the test* prefix.
            return true;
        }

        $name = $classMethod->name->toString();

        // User view: choose the findings list branch for this case.
        if (!str_starts_with($name, 'test')) {
            // No attribute, no annotation, no test* prefix: nothing marks this as a test method.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassMethod $classMethod - Method node whose docblock is scanned for a standalone `@test` tag.
     *
     * @return bool - true when the method docblock declares `@test` as a standalone tag line
     */
    private static function hasTestAnnotation(Stmt\ClassMethod $classMethod): bool
    {
        // User view: missing data becomes a safe findings list default.
        $docText = $classMethod->getDocComment()?->getText() ?? '';

        // Match a standalone PHPUnit @test annotation line inside the method docblock.
        return preg_match('/^\s*\*\s*@test\b/m', $docText) === 1;
    }

    /**
     * Detect whether the class extends a *TestCase base.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\Class_|null $class - Class node to inspect, or null when the method is detached.
     *
     * @return bool - true when the parent name ends with `testcase` (case-insensitive)
     */
    public static function extendsTestCase(?Stmt\Class_ $class): bool
    {
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($class === null || $class->extends === null) {
            // A detached method or a class with no parent cannot extend a TestCase base.
            return false;
        }

        $parent = strtolower($class->extends->getLast());

        // Match by the `TestCase` suffix so vendor and custom base classes both qualify.
        return str_ends_with($parent, 'testcase');
    }

    /**
     * Collect function, method, and static calls from a test scope.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param TestQualityScope $scope - Test scope whose statements should be walked.
     *
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> - every call the scope makes, of any of the three call shapes
     */
    public static function calls(TestQualityScope $scope): array
    {
        return NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\MethodCall::class, Expr\StaticCall::class]);
    }

    /**
     * Collect assertion-like calls from a test scope.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param TestQualityScope $scope - Test scope whose calls should be filtered.
     *
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> - the assertion subset of the scope's calls, re-indexed as a list
     */
    public static function assertionCalls(TestQualityScope $scope): array
    {
        return array_values(array_filter(
                                self::calls($scope),
                                static fn(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool => self::isAssertionCall($call),
                            ));
    }

    /**
     * Detect whether the call is a PHPUnit assertion, Pest expectation, or other supported assertion shape.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to classify.
     *
     * @return bool - true when the call counts as an assertion for test-quality rules
     */
    public static function isAssertionCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name === null) {
            // A dynamic call target has no resolvable name, so it cannot be a known assertion.
            return false;
        }

        // User view: choose the findings list branch for this case.
        if (str_starts_with($name, 'assert') || $name === 'fail') {
            // Every PHPUnit assertion is an assert* method, and fail() is a forced assertion failure.
            return true;
        }

        // User view: choose the findings list branch for this case.
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
        ],           true)) {
            // PHPUnit expect*() methods declare an expectation, which the rules treat as an assertion.
            return true;
        }

        // Remaining matches are Pest expectation terminals (expect(...)->toBe*); anything else is not an assertion.
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
        ],              true);
    }

    /**
     * Detect whether the assertion is trivially-true (e.g. assertTrue(true), assertSame($x, $x)).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Assertion call to inspect.
     *
     * @return bool - true when the assertion's literal arguments make it tautological
     */
    public static function isTrivialAssertion(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name === null) {
            // A dynamic call target cannot be matched to a known tautological assertion shape.
            return false;
        }

        // Only these specific assertions can be statically proven tautological; every other call is non-trivial.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - assertSame/assertEquals call to compare.
     *
     * @return bool - true when both arguments resolve to the same scalar literal (non-literals never match)
     */
    private static function hasSameLiteralArguments(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $expected = self::literalValue(self::firstArgValue($call));

        // literalValue() returns null for non-literals; the null guard excludes those so two
        // non-literals (both null) cannot count as matching arguments.
        // User view: missing data becomes the expected findings list state.
        return $expected !== null && $expected === self::literalValue(self::argValue($call, 1));
    }

    /**
     * Detect whether a Pest expectation's literal argument matches the expected value.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\MethodCall $call - toBe/toEqual matcher; its argument is compared with the expect()'d value.
     *
     * @return bool - true when expect($x)->toBe($x) has equal literal arguments (non-literals never match)
     */
    private static function hasSamePestLiteralArguments(Expr\MethodCall $call): bool
    {
        $expected = self::literalValue(self::firstArgValue($call));

        // literalValue() returns null for non-literals; the null guard excludes those so a
        // non-literal matcher and a non-literal expectation (both null) cannot count as matching.
        // User view: missing data becomes the expected findings list state.
        return $expected !== null && $expected === self::literalValue(self::pestExpectationValue($call));
    }

    /**
     * Lowercase short name of the call (method, function, or static), or null when the name is dynamic.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node whose name should be normalized.
     *
     * @return string|null - the lowercase identifier, or null when the call target is a variable / expression
     */
    public static function callName(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        // User view: choose the findings list branch for this case.
        if ($call instanceof Expr\FuncCall) {
            // Function calls resolve their name through the Name-aware helper.
            return self::functionName($call);
        }

        // User view: choose the findings list branch for this case.
        if ($call->name instanceof Identifier) {
            // Method and static calls expose a literal method name; lowercase it for case-insensitive matching.
            return strtolower($call->name->toString());
        }

        // Dynamic targets such as $obj->$method() have no static name to return.
        return null;
    }

    /**
     * Lowercase function name, or null when the call target is not a Name node.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - Function call node to inspect.
     *
     * @return string|null - lowercase function name, or null when the target is a variable function with no static name
     */
    public static function functionName(Expr\FuncCall $call): ?string
    {
        // User view: choose the findings list branch for this case.
        if ($call->name instanceof Name) {
            // A static function name is present; lowercase it so callers can match case-insensitively.
            return strtolower($call->name->toString());
        }

        // A variable function such as $fn() has no static name to return.
        return null;
    }

    /**
     * Detect whether the call creates a mock, stub, or spy via a recognised factory name.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to classify.
     *
     * @return bool - true when the call name is one of createMock / createStub / getMockBuilder / mock / partialMock / spy / prophesize
     */
    public static function isMockCreationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);

        // True only for the recognised mock/stub/spy factory names; a dynamic call (null name) never matches.
        // User view: missing data becomes the expected findings list state.
        return $name !== null && in_array($name, [
                'createmock',
                'createstub',
                'getmockbuilder',
                'mock',
                'partialmock',
                'spy',
                'prophesize',
            ],                            true);
    }

    /**
     * Detect whether the call wires a mock expectation (expects, shouldReceive, once, etc.).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to classify.
     *
     * @return bool - true when the call name matches a mock-verification idiom (expects, shouldReceive, once, etc.)
     */
    public static function isMockVerificationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);

        // True only for the recognised expectation-wiring idioms; a dynamic call (null name) never matches.
        // User view: missing data becomes the expected findings list state.
        return $name !== null && in_array($name, [
                'expects',
                'shouldreceive',
                'shouldhavebeencalled',
                'once',
                'never',
                'with',
                'method',
            ],                            true);
    }

    /**
     * Value of the call's first argument, or null when the call has no first argument.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to inspect.
     *
     * @return Expr|null - the first argument's expression, or null when the call has no first argument
     */
    public static function firstArgValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?Expr
    {
        return self::argValue($call, 0);
    }

    /**
     * Value of the call's argument at the given index, or null when missing or spread.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to inspect.
     * @param int                                           $index - Zero-based argument index.
     *
     * @return Expr|null - the argument's expression at that index, or null when it is missing or a spread placeholder
     */
    public static function argValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?Expr
    {
        // User view: choose the findings list branch for this case.
        if (!isset($call->args[$index]) || !$call->args[$index] instanceof Arg) {
            return null;
        }

        return $call->args[$index]->value;
    }

    /**
     * String literal value of the argument, or null when the argument is not a string literal.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Arg $arg - Argument node to inspect.
     *
     * @return string|null - the literal string value, or null when the argument is not a bare string literal
     */
    public static function argString(Arg $arg): ?string
    {
        return $arg->value instanceof Scalar\String_ ? $arg->value->value : null;
    }

    /**
     * Resolve the literal value of an expression (scalar literal or const true/false/null), or null when not a literal.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr|null $expr - Expression to resolve.
     *
     * @return bool|int|float|string|null - the resolved compile-time value; null when the expression is not a literal (note the literal `null` const
     *                                    also yields null)
     */
    public static function literalValue(?Expr $expr): bool|int|float|string|null
    {
        // User view: choose the findings list branch for this case.
        if ($expr instanceof Scalar\String_ || $expr instanceof Scalar\LNumber || $expr instanceof Scalar\DNumber) {
            // String/int/float literals carry their PHP value directly on the node.
            return $expr->value;
        }

        // User view: choose the findings list branch for this case.
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());

            // Only the three magic constants map to real literals; any other const fetch is not statically known.
            return match ($name) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => null,
            };
        }

        // Variables, calls, and other expressions have no compile-time literal value.
        return null;
    }

    /**
     * Walk a Pest expectation chain back to the expect()'d value.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\MethodCall $call - Pest expectation method call to unwind.
     *
     * @return Expr|null - the expression originally wrapped by expect(...), or null when the chain doesn't start with expect()
     */
    public static function pestExpectationValue(Expr\MethodCall $call): ?Expr
    {
        $receiver = $call->var;

        // User view: choose the findings list branch for this case.
        if ($receiver instanceof Expr\FuncCall && self::functionName($receiver) === 'expect') {
            // Base of the chain reached: the value under test is expect()'s first argument.
            return self::firstArgValue($receiver);
        }

        // User view: choose the findings list branch for this case.
        if ($receiver instanceof Expr\MethodCall) {
            // Walk one link back through a chained modifier such as ->not->toBe(...).
            return self::pestExpectationValue($receiver);
        }

        // Receiver is neither expect(...) nor a further method call, so this is not a Pest expectation chain.
        return null;
    }

    /**
     * Detect whether the method carries an attribute matching the given short name (case-insensitive).
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Stmt\ClassMethod $node - Method node whose attributes should be inspected.
     * @param string           $shortName - Attribute short name to match case-insensitively.
     *
     * @return bool - True when at least one #[...] attribute group has an attribute whose last name segment matches.
     */
    public static function hasAttribute(Stmt\ClassMethod $node, string $shortName): bool
    {
        // User view: add each item that can appear in findings list.
        foreach ($node->attrGroups as $attributeGroup) {
            // User view: add each item that can appear in findings list.
            foreach ($attributeGroup->attrs as $attribute) {
                // User view: choose the findings list branch for this case.
                if (strtolower($attribute->name->getLast()) === strtolower($shortName)) {
                    // Match on the last name segment so both #[Test] and #[PHPUnit\...\Test] qualify.
                    return true;
                }
            }
        }

        // No attribute group on the method carried the requested short name.
        return false;
    }

    /**
     * Get the enclosing Class_ node via the `parent` AST attribute, or null when unattached.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Node $node - Node whose parent chain should be inspected.
     *
     * @return Stmt\Class_|null - the enclosing class node, or null when the node is unattached or sits in a trait/interface
     */
    public static function parentClass(Node $node): ?Stmt\Class_
    {
        $parent = $node->getAttribute('parent');

        // The parent attribute is set by the parent-linking visitor; null here means the node is unattached
        // or sits inside a trait/interface rather than a class.
        return $parent instanceof Stmt\Class_ ? $parent : null;
    }

    /**
     * Strip the test* prefix and non-alphanumeric chars from a test method name; lowercase the result.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param string $name - Test method name to normalize.
     *
     * @return string - the normalised form (prefix-stripped, separators removed, lowercased) used for cross-method comparisons
     */
    public static function normalizedTestName(string $name): string
    {
        // User view: missing data becomes a safe findings list default.
        $name = preg_replace('/^test[_]?/i', '', $name) ?? $name;

        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $name));
    }

    /**
     * Detect a non-trivial integer literal used as the assertion's expected value, or null when none.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Assertion call to inspect.
     *
     * @return int|null - the magic expected value, or null when the expected value is absent, non-integer, or one of -1 / 0 / 1
     */
    public static function isAssertionMagicNumber(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?int
    {
        $name = self::callName($call);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name === null || !self::isAssertionCall($call)) {
            // Only an actual assertion can carry a magic expected value; a dynamic name disqualifies it too.
            return null;
        }

        $candidate = $call instanceof Expr\MethodCall && in_array($name, ['tobe', 'toequal', 'tohavecount'], true)
            ? self::firstArgValue($call)
            : self::argValue($call, 0);

        $literalValue = self::literalValue($candidate);

        // -1/0/1 are too common to be "magic"; report any other integer literal, else null for non-int expecteds.
        return is_int($literalValue) && !in_array($literalValue, [-1, 0, 1], true) ? $literalValue : null;
    }

}
