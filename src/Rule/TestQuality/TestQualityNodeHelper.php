<?php

declare(strict_types=1);

namespace GruffPhp\Rule\TestQuality;

use GruffPhp\Parser\AnalysisUnit;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final class TestQualityNodeHelper
{
    /** @var \WeakMap<AnalysisUnit, list<TestQualityScope>>|null */
    private static ?\WeakMap $scopeCache = null;

    public static function looksLikePhpUnitTestFile(AnalysisUnit $unit): bool
    {
        $displayPath = '/' . str_replace('\\', '/', $unit->file->displayPath);

        if (str_contains($displayPath, '/tests/') || str_contains($displayPath, '/Tests/')) {
            return true;
        }

        $basename = basename($unit->file->displayPath);

        return str_ends_with($basename, 'Test.php') || str_ends_with($basename, 'TestCase.php');
    }

    /**
     * @return list<TestQualityScope>
     */
    public static function testScopes(AnalysisUnit $unit): array
    {
        // 18 test-quality rules each call this for every PHP unit; cache so the AST walks once per unit.
        $cache = self::$scopeCache ??= new \WeakMap();

        if ($cache->offsetExists($unit)) {
            /** @var list<TestQualityScope> $scopes */
            $scopes = $cache->offsetGet($unit);

            return $scopes;
        }

        $finder = new NodeFinder();
        $scopes = [];

        foreach ($finder->findInstanceOf($unit->statements, Stmt\ClassMethod::class) as $method) {
            if (!self::isTestMethod($method)) {
                continue;
            }

            $class = self::parentClass($method);
            $className = $class?->name?->toString();
            $methodName = $method->name->toString();

            $scopes[] = new TestQualityScope(
                symbol: ($className === null ? 'anonymous' : $className) . '::' . $methodName . '()',
                name: $methodName,
                line: $method->getStartLine(),
                endLine: $method->getEndLine(),
                statements: array_values($method->stmts ?? []),
                node: $method,
                isPest: false,
                className: $className,
            );
        }

        foreach ($finder->findInstanceOf($unit->statements, Expr\FuncCall::class) as $call) {
            $name = self::functionName($call);
            if (($name !== 'it' && $name !== 'test') || count($call->args) < 2) {
                continue;
            }

            if (!$call->args[0] instanceof Arg || !$call->args[1] instanceof Arg) {
                continue;
            }

            $description = self::argString($call->args[0]) ?? $name;
            $closure = $call->args[1]->value;
            if (!$closure instanceof Expr\Closure) {
                continue;
            }

            $scopes[] = new TestQualityScope(
                symbol: sprintf('pest:%s', $description),
                name: $description,
                line: $call->getStartLine(),
                endLine: $call->getEndLine(),
                statements: array_values($closure->stmts),
                node: $call,
                isPest: true,
            );
        }

        $cache->offsetSet($unit, $scopes);

        return $scopes;
    }

    public static function isTestMethod(Stmt\ClassMethod $method): bool
    {
        if (self::hasAttribute($method, 'Test')) {
            return true;
        }

        if (str_contains($method->getDocComment()?->getText() ?? '', '@test')) {
            return true;
        }

        $name = $method->name->toString();

        if (!str_starts_with($name, 'test')) {
            return false;
        }

        // The bare `test*` prefix only counts as a PHPUnit test method when the enclosing
        // class extends a *TestCase base. This stops library code with method names like
        // testScopes()/testCandidate() being analysed as test bodies.
        return self::extendsTestCase(self::parentClass($method));
    }

    public static function extendsTestCase(?Stmt\Class_ $class): bool
    {
        if ($class === null || $class->extends === null) {
            return false;
        }

        $parent = strtolower($class->extends->getLast());

        return str_ends_with($parent, 'testcase');
    }

    /**
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall>
     */
    public static function calls(TestQualityScope $scope): array
    {
        $finder = new NodeFinder();

        return array_values(array_filter(
            $finder->find($scope->statements, static fn (Node $node): bool => $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall),
            static fn (Node $node): bool => $node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall,
        ));
    }

    /**
     * @return list<Expr\FuncCall|Expr\MethodCall|Expr\StaticCall>
     */
    public static function assertionCalls(TestQualityScope $scope): array
    {
        return array_values(array_filter(
            self::calls($scope),
            static fn (Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool => self::isAssertionCall($call),
        ));
    }

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
            'tobetrue',
            'tobefalse',
            'toequal',
            'tomatcharray',
            'tocontain',
            'tohavecount',
            'tothrow',
            'tomatchsnapshot',
        ], true);
    }

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
            'assertequals', 'assertsame' => self::sameLiteralArguments($call),
            'tobe', 'toequal' => $call instanceof Expr\MethodCall && self::samePestLiteralArguments($call),
            default => false,
        };
    }

    private static function sameLiteralArguments(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): bool
    {
        $expected = self::literalValue(self::firstArgValue($call));

        return $expected !== null && $expected === self::literalValue(self::argValue($call, 1));
    }

    private static function samePestLiteralArguments(Expr\MethodCall $call): bool
    {
        $expected = self::literalValue(self::firstArgValue($call));

        return $expected !== null && $expected === self::literalValue(self::pestExpectationValue($call));
    }

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

    public static function functionName(Expr\FuncCall $call): ?string
    {
        if ($call->name instanceof Name) {
            return strtolower($call->name->toString());
        }

        return null;
    }

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

    public static function firstArgValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?Expr
    {
        return self::argValue($call, 0);
    }

    public static function argValue(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call, int $index): ?Expr
    {
        if (!isset($call->args[$index]) || !$call->args[$index] instanceof Arg) {
            return null;
        }

        return $call->args[$index]->value;
    }

    public static function argString(Arg $arg): ?string
    {
        return $arg->value instanceof Scalar\String_ ? $arg->value->value : null;
    }

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

    public static function pestExpectationValue(Expr\MethodCall $call): ?Expr
    {
        $var = $call->var;

        if ($var instanceof Expr\FuncCall && self::functionName($var) === 'expect') {
            return self::firstArgValue($var);
        }

        if ($var instanceof Expr\MethodCall) {
            return self::pestExpectationValue($var);
        }

        return null;
    }

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

    public static function parentClass(Node $node): ?Stmt\Class_
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Stmt\Class_ ? $parent : null;
    }

    public static function normalizedTestName(string $name): string
    {
        $name = preg_replace('/^test[_]?/i', '', $name) ?? $name;

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));
    }

    public static function isAssertionMagicNumber(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall $call): ?int
    {
        $name = self::callName($call);
        if ($name === null || !self::isAssertionCall($call)) {
            return null;
        }

        $candidate = $call instanceof Expr\MethodCall && in_array($name, ['tobe', 'toequal', 'tohavecount'], true)
            ? self::firstArgValue($call)
            : self::argValue($call, 0);

        $value = self::literalValue($candidate);

        return is_int($value) && !in_array($value, [-1, 0, 1], true) ? $value : null;
    }

    public static function docComment(Node $node): ?Doc
    {
        return $node->getDocComment();
    }
}
