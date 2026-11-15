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
 * Provides AST helpers for test-quality rules: scope discovery, call classification, and literal argument values.
 * Scopes are PHPUnit methods or Pest closures recognised from node shapes and names.
 * Memoises discovered scopes per analysis unit for reuse across rules.
 */
final class TestQualityNodeHelper
{
    /** @var \WeakMap<AnalysisUnit, list<TestQualityScope>>|null - Cached scopes by unit; null before the first lookup. */
    private static ?\WeakMap $scopeCache = null;

    /**
     * Reports whether the unit looks like a PHPUnit test file by path or filename.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose path should be classified.
     *
     * @return bool - true when the file lives under tests/ or has a Test/TestCase basename suffix
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
     * Discovers the PHPUnit and Pest test scopes in an analysis unit.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect for test scopes.
     *
     * @return list<TestQualityScope> - PHPUnit method scopes followed by Pest scopes, each in AST order; empty when none match.
     */
    public static function testScopes(AnalysisUnit $analysisUnit): array
    {
        // Cache scope discovery per unit so multiple rules can share the result.
        $cache = self::$scopeCache ??= new \WeakMap();

        if ($cache->offsetExists($analysisUnit)) {

            return $cache->offsetGet($analysisUnit);
        }

        $scopes = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Stmt\ClassMethod::class) as $classMethod) {

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


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
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
                node:       $closure,
                isPest:     true,
            );
        }

        $cache->offsetSet($analysisUnit, $scopes);

        return $scopes;
    }

    /**
     * Reports whether the method matches a supported test shape by attribute, annotation, or method and parent names.
     *
     * @param Stmt\ClassMethod $classMethod - Method node to classify.
     *
     * @return bool - true when the method should be analysed as a test body
     */
    public static function isTestMethod(Stmt\ClassMethod $classMethod): bool
    {
        if (self::hasAttribute($classMethod, 'Test')) {
            // A Test-named attribute is sufficient for this heuristic regardless of the method or parent name.
            return true;
        }

        if (self::hasTestAnnotation($classMethod)) {
            // A standalone @test tag is also sufficient for this heuristic, independent of the method name.
            return true;
        }

        $name = $classMethod->name->toString();

        if (!str_starts_with($name, 'test')) {

            return false;
        }

        // A test-prefixed name only qualifies when the declared parent name ends in TestCase,
        // which avoids classifying similarly named methods in ordinary library classes.
        return self::extendsTestCase(self::parentClass($classMethod));
    }

    /**
     * Reports whether the method docblock carries a standalone PHPUnit @test annotation line.
     *
     * @param Stmt\ClassMethod $classMethod - Method node whose docblock is scanned for a standalone @test tag.
     *
     * @return bool - true when the method docblock declares @test as a standalone tag line
     */
    private static function hasTestAnnotation(Stmt\ClassMethod $classMethod): bool
    {
        $docText = $classMethod->getDocComment()?->getText() ?? '';

        // Require the tag at the start of a docblock line so prose that merely mentions @test does not classify the method.
        return preg_match('/^\s*\*\s*@test\b/m', $docText) === 1;
    }

    /**
     * Reports whether the declared parent short name ends with TestCase, ignoring case.
     *
     * @param Stmt\Class_|null $class - Class node to inspect, or null when the method is detached.
     *
     * @return bool - true when the parent name ends with TestCase (case-insensitive)
     */
    public static function extendsTestCase(?Stmt\Class_ $class): bool
    {
        if ($class === null || $class->extends === null) {

            return false;
        }

        $parent = strtolower($class->extends->getLast());

        // Match only the parent-name suffix so qualified and custom names work without resolving their declarations.
        return str_ends_with($parent, 'testcase');
    }

    /**
     * Collects function, method, and static call nodes from the test body.
     *
     * @param TestQualityScope $scope - Test scope whose body and nested bodies are traversed.
     *
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall> - Call nodes in AST order; empty when none match.
     */
    public static function calls(TestQualityScope $scope): array
    {
        return NodeIndex::descendantsOfAny($scope->node, [Expr\FuncCall::class, Expr\MethodCall::class, Expr\StaticCall::class]);
    }

    /**
     * Collects the assertion-like calls in a test scope.
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
     * Reports whether the call name matches a supported PHPUnit assertion, Pest expectation, or other assertion shape.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to classify.
     *
     * @return bool - true when the call counts as an assertion for test-quality rules
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
            'expectuserdeprecationmessage',
            'expectnottoperformassertions',
        ],           true)) {

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
        ],              true);
    }

    /**
     * Returns the lowercase function name or method identifier, or null for a dynamic name.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node whose name should be normalized.
     *
     * @return string|null - Lowercase literal name, including any function namespace; null for a dynamic name.
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
     * Returns the lowercase function name, or null when the call target is not a Name node.
     *
     * @param Expr\FuncCall $call - Function call node to inspect.
     *
     * @return string|null - lowercase function name, or null when the target is a variable function with no static name
     */
    public static function functionName(Expr\FuncCall $call): ?string
    {
        if ($call->name instanceof Name) {

            return strtolower($call->name->toString());
        }


        return null;
    }

    /**
     * Reports whether the call name matches a supported mock, spy, builder, or prophecy factory.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to classify.
     *
     * @return bool - True for a supported factory name; createStub is excluded because it needs no interaction expectation.
     */
    public static function isMockCreationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $name = self::callName($call);

        // Stubs supply values without interaction expectations, so createStub is excluded from the supported factory names.
        return $name !== null && in_array($name, [
                'createmock',
                'getmockbuilder',
                'mock',
                'partialmock',
                'spy',
                'prophesize',
            ],                            true);
    }

    /**
     * Reports whether a non-first-class call name matches a supported mock-expectation method.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to classify.
     *
     * @return bool - True for a listed expectation-wiring name unless the call is a first-class callable reference.
     */
    public static function isMockVerificationCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        // A first-class callable reference does not invoke the named method, so it cannot configure or verify an expectation here.
        if ($call->isFirstClassCallable()) {
            return false;
        }
        $name = self::callName($call);


        return $name !== null && in_array($name, [
                'expects',
                'shouldreceive',
                'shouldhavebeencalled',
                'shouldbecalled',
                'shouldbecalledonce',
                'shouldbecalledtimes',
                'shouldnotbecalled',
                'shouldnothavebeencalled',
                'once',
                'never',
                'with',
                'method',
            ],                            true);
    }

    /**
     * Returns the first argument expression, if one is available.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call node to inspect.
     *
     * @return Expr|null - First argument expression, or null for an absent argument or first-class callable placeholder.
     */
    public static function firstArgValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?Expr
    {
        return self::argValue($call, 0);
    }

    /**
     * Returns the expression at an argument-list position, or null when no argument node exists.
     *
     * @param Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call - Call whose argument list is inspected.
     * @param int $index - Zero-based position in the argument list, irrespective of argument names.
     *
     * @return Expr|null - Argument expression, including unpacked values; null for a missing argument or first-class callable placeholder.
     */
    public static function argValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?Expr
    {

        if (!isset($call->args[$index]) || !$call->args[$index] instanceof Arg) {
            return null;
        }

        return $call->args[$index]->value;
    }

    /**
     * Returns the string-literal value of the argument, or null when the argument is not a string literal.
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
     * Resolves supported scalar literals and true/false/null constant expressions.
     *
     * @param Expr|null $expr - Expression to inspect; null yields null.
     *
     * @return bool|int|float|string|null - Literal scalar value; null for literal null, absent input, or unsupported expressions.
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
     * Returns the expect()'d value at the base of a Pest expectation chain, or null.
     *
     * @param Expr\MethodCall $call - Pest expectation method call to unwind.
     *
     * @return Expr|null - the expression originally wrapped by expect(...), or null when the chain doesn't start with expect()
     */
    public static function pestExpectationValue(Expr\MethodCall $call): ?Expr
    {
        $receiver = $call->var;

        if ($receiver instanceof Expr\FuncCall && self::functionName($receiver) === 'expect') {
            // Base of the chain reached: the value under test is expect()'s first argument.
            return self::firstArgValue($receiver);
        }

        if ($receiver instanceof Expr\MethodCall) {
            // Walk one link back through a chained modifier such as ->not->toBe(...).
            return self::pestExpectationValue($receiver);
        }

        // Receiver is neither expect(...) nor a further method call, so this is not a Pest expectation chain.
        return null;
    }

    /**
     * Reports whether the method carries an attribute matching the given short name (case-insensitive).
     *
     * @param Stmt\ClassMethod $node      - Method node whose attributes should be inspected.
     * @param string           $shortName - Attribute short name to match case-insensitively.
     *
     * @return bool - True when at least one #[...] attribute group has an attribute whose last name segment matches.
     */
    public static function hasAttribute(Stmt\ClassMethod $node, string $shortName): bool
    {

        foreach ($node->attrGroups as $attributeGroup) {

            foreach ($attributeGroup->attrs as $attribute) {
                if (strtolower($attribute->name->getLast()) === strtolower($shortName)) {
                    // Match on the last name segment so both #[Test] and #[PHPUnit\...\Test] qualify.
                    return true;
                }
            }
        }


        return false;
    }

    /**
     * Returns the node's immediate parent when it is a class, or null otherwise.
     *
     * @param Node $node - Node whose immediate `parent` attribute should be inspected.
     *
     * @return Stmt\Class_|null - Immediate class parent; null when the parent is absent or has another node type.
     */
    public static function parentClass(Node $node): ?Stmt\Class_
    {
        $parent = $node->getAttribute('parent');



        return $parent instanceof Stmt\Class_ ? $parent : null;
    }

    /**
     * Returns the test name with its test* prefix and non-alphanumeric characters stripped, lowercased.
     *
     * @param string $name - Test method name to normalize.
     *
     * @return string - the normalised form (prefix-stripped, separators removed, lowercased) used for cross-method comparisons
     */
    public static function normalizedTestName(string $name): string
    {
        // Ignore the leading test marker so naming style does not distinguish otherwise equivalent test names.
        $name = preg_replace('/^test[_]?/i', '', $name) ?? $name;

        // Remove every non-alphanumeric character, so test_user_login and testUserLogin compare equal.
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $name));
    }

}
