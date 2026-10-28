<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\DangerousFunctionCallRule;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protects warnings when developers register callbacks for later boot or teardown work.
 *
 * These authored declarations pair typed registration with nearby unsafe collection changes.
 * Gruff parses each example without executing its callbacks or request-shaped controls.
 */
final class CallableCollectionPrecisionTest extends TestCase
{
    /**
     * Checks the warning count for one declaration-owned collection and its invocation.
     *
     * @param string $declaration      - Authored class or trait source; no supplied code executes.
     * @param int    $expectedWarnings - Zero means every observed store and the selected loop value have evidence.
     * @return void - A mismatched warning count fails the registration safety regression.
     */
    #[DataProvider('registrationCases')]
    public function testRegisteredCallbackEvidence(string $declaration, int $expectedWarnings): void
    {
        $source     = '<?php namespace RegistrationFixture; ' . $declaration;
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser  = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor());
        $statements = array_values($parser->parse($source) ?? []);
        // These visitors attach resolved names and parents to the existing parsed statement objects.
        $traverser->traverse($statements);
        $unit    = new AnalysisUnit(new SourceFile(__FILE__, 'src/RegisteredCallbacks.php'), $source, $statements, [], []);
        $context = new RuleContext(__DIR__, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
        self::assertCount($expectedWarnings, (new DangerousFunctionCallRule())->analyse($unit, $context));
    }

    /**
     * Pairs boot and teardown registrations with writes that must keep a developer's warning.
     *
     * @return iterable<string, array{string, int}> - Named authored declarations and their expected warning counts.
     */
    public static function registrationCases(): iterable
    {
        $instance = 'trait Lifecycle {
            protected $callbacks = [];
            protected function register(callable $registered) { $this->callbacks[] = $registered; }
            protected function reset() { $this->callbacks = []; }
            protected function invoke($unknown) { foreach ($this->callbacks as $callback) { $callback(); } }
        }';
        $static = 'class Model {
            protected static $callbacks = [];
            protected static function register(\Closure $registered) {
                static::$callbacks[static::class] ??= [];
                static::$callbacks[static::class][] = $registered;
            }
            protected static function reset() { static::$callbacks = []; }
            protected function invoke($unknown) {
                static::$callbacks[static::class] ??= [];
                foreach (static::$callbacks[static::class] as $callback) { $callback(); }
            }
        }';
        yield 'typed teardown registration' => [$instance, 0];
        yield 'typed boot bucket registration' => [$static, 0];
        yield 'unknown registration' => [str_replace('callable $registered', '$registered', $instance), 1];
        yield 'request registration' => [str_replace('= $registered;', '= $_GET["callback"];', $instance), 1];
        yield 'reassigned parameter' => [str_replace('$this->callbacks[]', '$registered = $_GET["callback"]; $this->callbacks[]', $instance), 1];
        yield 'borrowed parameter' => [str_replace('$this->callbacks[]', 'change($registered); $this->callbacks[]', $instance), 1];
        yield 'by-reference parameter' => [str_replace('callable $registered', 'callable &$registered', $instance), 1];
        yield 'unknown default' => [str_replace('protected $callbacks = []', 'protected $callbacks = ["unknown"]', $instance), 1];
        yield 'unknown reset' => [str_replace('$this->callbacks = []', '$this->callbacks = $_GET["callbacks"]', $instance), 1];
        yield 'public collection' => [str_replace('protected $callbacks', 'public $callbacks', $instance), 1];
        yield 'foreign receiver' => [str_replace('$this->callbacks[]', '$other->callbacks[]', $instance), 1];
        yield 'dynamic property store' => [str_replace('$this->callbacks[]', '$this->{$name}[]', $instance), 1];
        yield 'loop value reassignment' => [str_replace('$callback();', '$callback = $unknown; $callback();', $instance), 1];
        yield 'later loop value write' => [str_replace('$callback();', '$callback(); $callback = $unknown;', $instance), 1];
        yield 'by-reference loop' => [str_replace('as $callback', 'as &$callback', $instance), 1];
        yield 'loop escape' => [str_replace('$callback();', 'change($callback); $callback();', $instance), 1];
        yield 'collection escape' => [str_replace('$this->callbacks = [];', 'change($this->callbacks);', $instance), 1];
        yield 'collection reference' => [str_replace('$this->callbacks = [];', '$borrowed =& $this->callbacks;', $instance), 1];
        yield 'referenced array member' => [str_replace('$this->callbacks = [];', '$borrowed = [&$this->callbacks];', $instance), 1];
        yield 'different static bucket' => [str_replace('static::$callbacks[static::class][]', 'static::$callbacks[self::class][]', $static), 1];
        yield 'unknown static bucket' => [str_replace('static::$callbacks[static::class][]', 'static::$callbacks[$unknown][]', $static), 1];
        yield 'unknown static receiver' => [str_replace('static::$callbacks[static::class][]', 'Other::$callbacks[static::class][]', $static), 1];
        yield 'dynamic static property' => [str_replace('static::$callbacks[static::class][]', 'static::$$unknown[static::class][]', $static), 1];
        yield 'nested callback shadow' => [str_replace('$callback();', '$nested = function ($callback) { $callback(); };', $instance), 1];
        yield 'invocation after loop' => [str_replace('{ $callback(); }', '{} $callback();', $instance), 1];
        yield 'request value inside loop' => [str_replace('$callback();', '$callback = $_GET["callback"]; $callback();', $instance), 1];
        yield 'case-sensitive properties' => [str_replace('$this->callbacks[]', '$this->Callbacks[]', $instance), 1];
        yield 'property hook' => [str_replace('protected $callbacks = [];', 'protected array $callbacks = [] { get => $_GET["callbacks"]; }', $instance), 1];
        yield 'unset collection' => [str_replace('$this->callbacks = [];', 'unset($this->callbacks);', $instance), 1];
        yield 'evaluated collection write' => [str_replace('$this->callbacks = [];', 'eval($source);', $instance), 2];
        yield 'uninitialized collection' => [str_replace('protected $callbacks = []', 'protected $callbacks', $instance), 1];
    }
}
