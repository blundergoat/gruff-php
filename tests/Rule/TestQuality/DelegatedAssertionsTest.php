<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the warnings developers receive when test assertions live behind another invocation.
 *
 * Authored examples prove same-class delegation and Doctrine's resolved deprecation expectation.
 * Uncalled helpers, nested closures and shadowed vendor methods retain assertion-free warnings.
 */
final class DelegatedAssertionsTest extends TestCase
{
    /**
     * Parses one test class and checks whether its entry method still needs an assertion warning.
     *
     * @param string $members          - Helper methods and trait declarations; empty means the class has no helper evidence.
     * @param string $body             - Entry test body, parsed as data without running any fixture method.
     * @param int    $expectedWarnings - Zero requires recognized invoked assertion evidence.
     * @param string $imports          - Optional name bindings; empty leaves vendor short names unresolved.
     * @param bool   $usesPartialCall  - True models an uninvoked partial callback without requiring newer parser syntax.
     * @return void
     */
    #[DataProvider('delegationCases')]
    public function testDelegatedExpectation(string $members, string $body, int $expectedWarnings, string $imports = '', bool $usesPartialCall = false): void
    {
        $source = '<?php namespace TestFixture; ' . $imports . '
            class ExampleTest extends \PHPUnit\Framework\TestCase {
                ' . $members . '
                public function testEntry(): void { ' . $body . ' }
            }';
        $unit     = $this->parseTestSource($source, $usesPartialCall);
        $context  = new RuleContext(__DIR__, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
        $findings = (new NoAssertionsRule())->analyse($unit, $context);

        self::assertCount($expectedWarnings, $findings);
    }

    /**
     * Prepares inert test source and optional newer-parser nodes for the same public rule check.
     *
     * @param string $source          - Authored PHPUnit class; its methods are parsed without execution.
     * @param bool   $usesPartialCall - True models a callback reference whose assertions have not run.
     * @return AnalysisUnit - Parsed fixture ready for the normal analyzer entry point.
     */
    private function parseTestSource(string $source, bool $usesPartialCall): AnalysisUnit
    {
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor());
        /** @var list<Stmt> $statements Parsed declarations; an empty source contributes no executable statements. */
        $statements = $traverser->traverse($parser->parse($source) ?? []);
        // A developer can prepare a partial callback without executing the helper's assertions.
        if ($usesPartialCall) {
            $methodCall = (new \PhpParser\NodeFinder())->findFirstInstanceOf($statements, \PhpParser\Node\Expr\MethodCall::class);
            self::assertInstanceOf(\PhpParser\Node\Expr\MethodCall::class, $methodCall);
            $methodCall->args[1] = new \PhpParser\Node\VariadicPlaceholder();
        }

        return new AnalysisUnit(new SourceFile(__FILE__, 'tests/ExampleTest.php'), $source, $statements, [], []);
    }

    /**
     * Supplies invoked checks and nearby cases that must not grant an assertion exception.
     *
     * @return iterable<string, array{string, string, int, 3?: string, 4?: bool}> - Cases with optional imports and partial-call controls.
     */
    public static function delegationCases(): iterable
    {
        $assertionMethod = 'private function verifyResult(): void { self::assertSame(1, 1); }';
        $trait           = 'use \Doctrine\Deprecations\PHPUnit\VerifyDeprecations;';
        $expectation     = '$this->expectDeprecationWithIdentifier("reviewed-identifier");';
        yield 'invoked helper' => [$assertionMethod, '$this->verifyResult();', 0];
        yield 'case-insensitive method' => [$assertionMethod, '$this->VERIFYRESULT();', 0];
        yield 'static helper' => ['private static function verifyResult(): void { self::assertSame(1, 1); }', 'self::verifyResult();', 0];
        yield 'helper chain' => [$assertionMethod . ' private function checkResult(): void { $this->verifyResult(); }', '$this->checkResult();', 0];
        yield 'partial helper is uncalled' => [$assertionMethod, '$callback = $this->verifyResult(1, 2);', 1, '', true];
        yield 'uncalled helper' => [$assertionMethod, '$answer = 1;', 1];
        yield 'foreign receiver' => [$assertionMethod, '$other->verifyResult();', 1];
        yield 'first-class reference is uncalled' => [$assertionMethod, '$callback = $this->verifyResult(...);', 1];
        yield 'cycle without assertions' => ['private function verifyResult(): void { $this->verifyResult(); }', '$this->verifyResult();', 1];
        yield 'unused closure inside helper' => ['private function verifyResult(): void { $callback = function () { self::assertSame(1, 1); }; }', '$this->verifyResult();', 1];
        yield 'named helper without assertions' => ['private function verifyResult(): void {}', '$this->verifyResult();', 1];
        yield 'resolved vendor trait' => [$trait, $expectation, 0];
        yield 'imported vendor trait' => ['use ChecksDeprecations;', $expectation, 0, 'use Doctrine\Deprecations\PHPUnit\VerifyDeprecations as ChecksDeprecations;'];
        yield 'missing vendor trait' => ['', $expectation, 1];
        yield 'shadowed vendor trait' => ['use VerifyDeprecations;', $expectation, 1, 'trait VerifyDeprecations {}'];
        yield 'local method override' => [$trait . ' public function expectDeprecationWithIdentifier(string $id): void {}', $expectation, 1];
        yield 'after hook override' => [$trait . ' public function verifyDeprecationsAreTriggered(): void {}', $expectation, 1];
        yield 'before hook override' => [$trait . ' public function enableDeprecationTracking(): void {}', $expectation, 1];
        yield 'local vendor replacement' => [$trait, $expectation, 1,
            'namespace Doctrine\\Deprecations\\PHPUnit; trait VerifyDeprecations {} namespace TestFixture;'];
        yield 'trait adaptation' => ['use \Doctrine\Deprecations\PHPUnit\VerifyDeprecations { expectDeprecationWithIdentifier as private; }', $expectation, 1];
        yield 'additional unknown trait' => [$trait . ' use AdditionalBehavior;', $expectation, 1, 'trait AdditionalBehavior {}'];
        yield 'uncalled vendor expectation' => [$trait, '$callback = $this->expectDeprecationWithIdentifier(...);', 1];
        yield 'foreign vendor receiver' => [$trait, '$other->expectDeprecationWithIdentifier("reviewed-identifier");', 1];
    }
}
