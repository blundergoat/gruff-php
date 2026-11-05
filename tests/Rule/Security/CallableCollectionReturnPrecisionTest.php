<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protects warnings when a developer invokes callbacks returned by a local collection method.
 *
 * The two benign forms mirror pinned framework source without executing it.
 * Nearby unknown stores, return paths and argument bindings must keep the warning visible.
 */
final class CallableCollectionReturnPrecisionTest extends TestCase
{
    /**
     * Checks the dynamic-call warning count for one authored return form.
     *
     * @param string $declaration      - Parsed source only; empty or invalid source cannot earn a quiet result.
     * @param int    $expectedWarnings - Zero means the complete returned list has proved callback elements.
     * @return void - A changed count fails the source-bound precision regression.
     */
    #[DataProvider('returnedCollectionCases')]
    public function testReturnedCollectionEvidence(string $declaration, int $expectedWarnings): void
    {
        $source     = '<?php namespace ReturnFixture; ' . $declaration;
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser  = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor());
        $statements = array_values($parser->parse($source) ?? []);
        // Name and parent links let the rule inspect the declaration the developer actually wrote.
        $traverser->traverse($statements);
        $unit    = new AnalysisUnit(new SourceFile(__FILE__, 'src/ReturnedCallbacks.php'), $source, $statements, [], []);
        $context = new RuleContext(__DIR__, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
        self::assertCount($expectedWarnings, (new DangerousFunctionCallRule())->analyse($unit, $context));
    }

    /**
     * Keeps an incomplete method call from borrowing the missing key argument's position.
     *
     * @return void - The dynamic callback remains reportable with the newer parser's placeholder node.
     */
    public function testPartialCallPlaceholderKeepsWarning(): void
    {
        $source = '<?php class Registry {
            protected $callbacks = [];
            protected function register($key, \Closure $registered) { $this->callbacks[$key][] = $registered; }
            protected function selected($key) { return $this->callbacks[$key] ?? []; }
            public function run($key) {
                if (! $callbacks = $this->selected($key)) { return; }
                foreach ($callbacks as $callback) { $callback(); }
            }
        }';
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser  = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor());
        $statements = array_values($parser->parse($source) ?? []);
        $traverser->traverse($statements);
        $methodCall = (new NodeFinder())->findFirst($statements, static fn (Node $node): bool =>
            $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'selected');
        self::assertInstanceOf(Node\Expr\MethodCall::class, $methodCall);
        // The newer parser can represent a reserved future argument even when the installed parser cannot parse that syntax.
        $methodCall->args[0] = new Node\VariadicPlaceholder();
        $unit                = new AnalysisUnit(new SourceFile(__FILE__, 'src/PartialReturnedCallbacks.php'), $source, $statements, [], []);
        $context             = new RuleContext(__DIR__, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
        self::assertCount(1, (new DangerousFunctionCallRule())->analyse($unit, $context));
    }

    /**
     * Pairs the two observed return shapes with mutations that would make a callback uncertain.
     *
     * @return iterable<string, array{string, int}> - Authored PHP and warning count; snippets are never executed.
     */
    public static function returnedCollectionCases(): iterable
    {
        $bucket = 'class Registry {
            protected $callbacks = [];
            protected function register($key, \Closure $registered) { $this->callbacks[$key][] = $registered; }
            protected function selected($key) { return $this->callbacks[$key] ?? []; }
            public function run($key) {
                if (! $callbacks = $this->selected($key)) { return; }
                foreach ($callbacks as $callback) { $callback(); }
            }
        }';
        $merged = 'class Registry {
            protected $callbacks = [];
            protected function register($key, \Closure $registered) { $this->callbacks[$key][] = $registered; }
            protected function selected($key, $object, array $callbacksPerType) {
                $results = [];
                foreach ($callbacksPerType as $type => $callbacks) {
                    if ($type === $key || $object instanceof $type) {
                        $results = array_merge($results, $callbacks);
                    }
                }
                return $results;
            }
            public function run($key, $object) {
                $callbacks = $this->selected($key, $object, $this->callbacks);
                foreach ($callbacks as $callback) { $callback(); }
            }
        }';
        $bucketAppend       = '$this->callbacks[$key][]';
        $bucketRegistration = '$this->callbacks[$key][] = $registered;';
        $bucketReturn       = 'return $this->callbacks[$key] ?? [];';
        $guardedSelection   = 'if (! $callbacks = $this->selected($key)) { return; }';
        $mergedSelection    = '$this->selected($key, $object, $this->callbacks)';

        yield 'returned registered bucket' => [$bucket, 0];
        yield 'merged registered buckets' => [$merged, 0];
        yield 'unknown bucket default' => [str_replace('protected $callbacks = []', 'protected $callbacks = [["unknown"]]', $bucket), 1];
        yield 'untyped bucket registration' => [str_replace('\Closure $registered', '$registered', $bucket), 1];
        yield 'request-controlled registration' => [str_replace('[] = $registered', '[] = $_GET["callback"]', $bucket), 1];
        yield 'borrowed registration parameter' => [str_replace($bucketAppend, 'change($registered); ' . $bucketAppend, $bucket), 1];
        yield 'public bucket storage' => [str_replace('protected $callbacks', 'public $callbacks', $bucket), 1];
        yield 'foreign bucket store' => [str_replace('$this->callbacks[$key][]', '$other->callbacks[$key][]', $bucket), 1];
        yield 'dynamic bucket property' => [str_replace('$this->callbacks[$key][]', '$this->{$name}[$key][]', $bucket), 1];
        yield 'aliased dynamic property write' => [
            str_replace(
                'protected function selected($key)',
                'protected function replace($name, $key) { $alias = $this; $alias->{$name}[$key][] = $_GET["callback"]; } '
                    . 'protected function selected($key)',
                $bucket,
            ), 1,
        ];
        yield 'nested alias writes outer property' => [
            str_replace(
                'protected function selected($key)',
                'protected function replace($name, $key) { $outer = $this; '
                    . '(new class { function write($outer, $name, $key) { $outer->{$name}[$key][] = $_GET["callback"]; } })'
                    . '->write($outer, $name, $key); } protected function selected($key)',
                $bucket,
            ), 1,
        ];
        yield 'bucket replacement' => [str_replace('$this->callbacks[$key][] = $registered;', '$this->callbacks[$key] = [$registered];', $bucket), 1];
        yield 'bucket reference escape' => [str_replace($bucketReturn, '$borrowed =& $this->callbacks; ' . $bucketReturn, $bucket), 1];
        yield 'unknown fallback' => [str_replace('?? []', '?? [$key]', $bucket), 1];
        yield 'unknown bucket return' => [str_replace('return $this->callbacks[$key] ?? [];', 'return $this->unknown[$key] ?? [];', $bucket), 1];
        yield 'borrowed return method' => [str_replace('function selected($key)', 'function &selected($key)', $bucket), 1];
        yield 'extra return path' => [str_replace($bucketReturn, 'if ($key) { return [$key]; } ' . $bucketReturn, $bucket), 1];
        yield 'foreign return receiver' => [str_replace('$this->selected($key)', '$other->selected($key)', $bucket), 1];
        yield 'named bucket call' => [str_replace('selected($key))', 'selected(key: $key))', $bucket), 1];
        yield 'unpacked bucket call' => [str_replace('selected($key))', 'selected(...[$key]))', $bucket), 1];
        yield 'unguarded branch assignment' => [str_replace($guardedSelection, 'if ($key) { $callbacks = $this->selected($key); }', $bucket), 1];
        yield 'bucket result reassignment' => [
            str_replace('foreach ($callbacks as $callback)', '$callbacks = [$key]; foreach ($callbacks as $callback)', $bucket), 1,
        ];
        yield 'borrowed bucket result' => [
            str_replace('foreach ($callbacks as $callback)', 'change($callbacks); foreach ($callbacks as $callback)', $bucket), 1,
        ];
        yield 'bucket loop mutation' => [str_replace('$callback();', '$callback = $key; $callback();', $bucket), 1];
        yield 'wrong merged source argument' => [str_replace($mergedSelection, '$this->selected($key, $object, $unknown)', $merged), 1];
        yield 'named merged arguments' => [
            str_replace($mergedSelection, '$this->selected(key: $key, object: $object, callbacksPerType: $this->callbacks)', $merged), 1,
        ];
        yield 'mixed merge source' => [str_replace('array_merge($results, $callbacks)', 'array_merge($results, $key)', $merged), 1];
        yield 'unknown merged return' => [str_replace('return $results;', 'return $unknown;', $merged), 1];
        yield 'borrowed merged bucket' => [str_replace('as $type => $callbacks', 'as $type => &$callbacks', $merged), 1];
        yield 'merged property replacement' => [str_replace($bucketRegistration, '$this->callbacks[$key] = [$registered];', $merged), 1];
        yield 'local array_merge replacement' => ['function array_merge($left, $right) { return [$right]; } ' . $merged, 1];
        yield 'conditional array_merge replacement' => [
            str_replace(
                'public function run($key, $object) {',
                'public function run($key, $object) { if ($key) { function array_merge($left, $right) { return [$right]; } }',
                $merged,
            ), 1,
        ];
        yield 'imported array_merge replacement' => ['use function Other\array_merge; ' . $merged, 1];
    }
}
