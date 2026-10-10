<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protects the callable warnings developers see after copying or binding an existing callback.
 *
 * Authored source examples pair supported local evidence with ambiguous writes and request-controlled targets.
 * The snippets are parsed only; their callbacks and shell-shaped controls never execute.
 */
final class CallablePrecisionTest extends TestCase
{
    /**
     * Keeps each authored callable example at its expected warning count.
     *
     * @param string $body             - Method body to parse; no supplied code executes.
     * @param int    $expectedWarnings - Zero means the complete invocation has bounded callable evidence.
     * @param string $prefix           - Optional namespace declarations; empty uses the ordinary fixture namespace.
     * @return void
     */
    #[DataProvider('invocationCases')]
    public function testInvocationEvidence(string $body, int $expectedWarnings, string $prefix = ''): void
    {
        $source = '<?php namespace CallbackFixture; ' . $prefix . '
            final class Runner {
                private \Closure $callback;
                public function run($unknown, bool $condition, callable $trusted): void { ' . $body . ' }
                private static function estimate(string $value): int { return strlen($value); }
            }';
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        /** @var list<Stmt> $statements Parsed fixture declarations; an empty body contributes no statements. */
        $statements = $traverser->traverse($parser->parse($source) ?? []);
        $unit       = new AnalysisUnit(new SourceFile(__FILE__, 'src/Runner.php'), $source, $statements, [], []);
        $context    = new RuleContext(__DIR__, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
        $findings   = (new DangerousFunctionCallRule())->analyse($unit, $context);

        self::assertCount($expectedWarnings, $findings);
    }

    /**
     * Keeps partial-call placeholders from producing scanner warnings or granting binder evidence.
     *
     * @return void - Every supported placeholder remains safe to inspect with either parser dependency version.
     */
    public function testPartialApplicationPlaceholders(): void
    {
        $source     = '<?php function invoke() { $handler = \\Closure::bind(fn () => 1, null); factory(1, 2); $handler(); }';
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser  = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor());
        $statements = $traverser->traverse($parser->parse($source) ?? []);
        $finder     = new \PhpParser\NodeFinder();
        $calls      = $finder->findInstanceOf($statements, \PhpParser\Node\Expr\FuncCall::class);
        $binder     = $finder->findFirstInstanceOf($statements, \PhpParser\Node\Expr\StaticCall::class);
        self::assertInstanceOf(\PhpParser\Node\Expr\StaticCall::class, $binder);

        // Build the newer partial-call node shape without requiring the older development parser to parse its syntax.
        $calls[0]->args[1] = new \PhpParser\Node\VariadicPlaceholder();
        $declaredCallable  = static fn (\PhpParser\Node\Expr $callbackValue): bool => false;
        self::assertTrue(\GruffPhp\Rules\Security\CallableFlowEvidence::hasInvocationEvidence($calls[1], $declaredCallable));

        // Reserving the binder's argument makes the binding unresolved instead of granting a callable exception.
        $binder->args[1] = new \PhpParser\Node\VariadicPlaceholder();
        self::assertFalse(\GruffPhp\Rules\Security\CallableFlowEvidence::hasInvocationEvidence($calls[1], $declaredCallable));
    }

    /**
     * Supplies bounded reproductions and nearby unsafe mutations for developers' callback code.
     *
     * @return iterable<string, array{string, int, 2?: string}> - Named source bodies and expected warnings; no production code is executed.
     */
    public static function invocationCases(): iterable
    {
        yield 'property copy' => ['$handler = $this->callback; $handler();', 0];
        yield 'alias chain' => ['$first = $this->callback; $handler = $first; $handler();', 0];
        yield 'typed parameter copy' => ['$handler = $trusted; $handler();', 0];
        yield 'coalesced property' => ['$handler = $this->callback ?? self::estimate(...); $handler("value");', 0];
        yield 'immediate bind' => ['\Closure::bind(fn () => $this->callback, $this, self::class)();', 0];
        yield 'stored bind' => ['$handler = \Closure::bind(static fn () => 1, null, self::class); $handler();', 0];
        yield 'imported binder' => ['$handler = Binder::bind(static fn () => 1, null); $handler();', 0, 'use Closure as Binder;'];
        yield 'guarded local copy' => ['if ($condition) { $handler = $this->callback; $handler(); }', 0];
        yield 'nested call result is not a borrowed local' => ['$handler = $this->callback; if ($condition) { write($handler()); } else { logResult($handler()); }', 0];
        yield 'array reference survives assignment' => ['$references = [&$handler]; $handler = $this->callback; $references[0] = $unknown; $handler();', 1];
        yield 'unknown reassignment' => ['$handler = $this->callback; $handler = $unknown; $handler();', 1];
        yield 'conditional reassignment' => ['$handler = $this->callback; if ($condition) { $handler = $unknown; } $handler();', 1];
        yield 'conditional initialization' => ['if ($condition) { $handler = $this->callback; } $handler();', 1];
        yield 'parameter rebound before copy' => ['$trusted = $unknown; $handler = $trusted; $handler();', 1];
        yield 'request assignment' => ['$handler = $this->callback; $handler = $_GET["callback"]; $handler();', 1];
        yield 'request origin' => ['$handler = $_GET["callback"]; $handler();', 1];
        yield 'unknown property receiver' => ['$handler = $unknown->callback; $handler();', 1];
        yield 'unproved bind target' => ['$handler = \Closure::bind($unknown, null); $handler();', 1];
        yield 'shadowed binder' => ['$handler = Closure::bind(static fn () => 1, null); $handler();', 1, 'class Closure {}'];
        yield 'captured alias remains unresolved' => ['$handler = $this->callback; $nested = function () use ($handler) { $handler(); };', 1];
        yield 'shadowed closure parameter' => ['$handler = $this->callback; $nested = function ($handler) { $handler(); };', 1];
        yield 'unknown call may write by reference' => ['$handler = $this->callback; change($handler); $handler();', 1];
        yield 'reference alias' => ['$handler = $this->callback; $other =& $handler; $handler();', 1];
        yield 'reference survives assignment' => ['$other =& $handler; $handler = $this->callback; $other = $unknown; $handler();', 1];
        yield 'captured reference' => ['$handler = $this->callback; $nested = function () use (&$handler) { $handler = null; }; $handler();', 1];
        yield 'partial array write' => ['$handler = $unknown; $handler[0] = $this->callback; $handler();', 1];
        yield 'dynamic variable write' => ['$handler = $this->callback; $$unknown = null; $handler();', 1];
        yield 'jump past assignment' => ['$handler = $unknown; goto invoke; $handler = $this->callback; invoke: $handler();', 1];
        yield 'runtime locals' => ['$handler = $this->callback; extract($unknown); $handler();', 1];
        yield 'global reference' => ['global $handler; $handler = $this->callback; changeGlobals(); $handler();', 1];
        yield 'unset alias' => ['$handler = $this->callback; unset($handler); $handler();', 1];
        yield 'later loop write' => ['$handler = $this->callback; while ($condition) { $handler(); $handler = $unknown; }', 1];
        yield 'prior closure write does not escape its scope' => [
            '$nested = function () { $handler = null; }; $handler = $this->callback; $handler();', 0,
        ];
    }
}
