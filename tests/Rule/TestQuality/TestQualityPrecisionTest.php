<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\TestQuality;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\TestQuality\LoopAssertionWithoutMessageRule;
use GruffPhp\Rules\TestQuality\NoAssertionsRule;
use GruffPhp\Rules\TestQuality\SutNotCalledRule;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Proves test-role, fixture-control and SUT-prefix boundaries through the real parsed rules. */
final class TestQualityPrecisionTest extends TestCase
{
    /**
     * Accepts method prefixes without promoting a low-confidence mismatch to an error.
     *
     * @param string         $name     - PHPUnit test name from the supported camelCase heuristic.
     * @param string         $body     - Visible SUT calls and assertion source.
     * @param list<Severity> $expected - Finding severities; empty means the heuristic stays quiet.
     * @return void
     */
    #[DataProvider('sutPrefixCases')]
    public function testSutPrefixAndSeverity(string $name, string $body, array $expected): void
    {
        $unit     = $this->unit('class ExampleTest extends \PHPUnit\Framework\TestCase { public function ' . $name . '(): void { ' . $body . ' } }');
        $findings = (new SutNotCalledRule())->analyse($unit, $this->context());

        self::assertSame($expected, array_map(static fn(Finding $finding): Severity => $finding->severity, $findings));
    }

    /**
     * Reproduces field-report prefixes and retains a named-but-uncalled subject control.
     *
     * @return iterable<string, array{string, string, list<Severity>}> - Test names, calls and expected finding severities.
     */
    public static function sutPrefixCases(): iterable
    {
        yield 'call prefixes outcome phrase' => ['testFindByCallSidRecoversSessionWhenIndexIsEmpty', '$value = $store->findByCallSid("id"); self::assertNotNull($value);', []];
        yield 'shared verb object prefix' => ['testProcessRefundConnectedAccountCallsSplitReversal', '$value = $processor->processRefundForAirwallex("id"); self::assertTrue($value);', []];
        yield 'one-word prose is insufficient' => ['testFindsWhenTheFilledWindowClears', '$value = $limiter->getRateLimitedUntil("id"); self::assertNull($value);', []];
        yield 'inflected verb resolves' => ['testCalculatesTotalReturnsExpected', '$value = $calculator->calculateTotal(); self::assertSame(6, $value);', []];
        yield 'missing subject remains advisory' => ['testCalculateTotalReturnsExpected', '$value = $rates->lookupRates(); self::assertSame(6, $value);', [Severity::Advisory]];
    }

    /**
     * Recognizes framework expectations while retaining unresolved or unexecuted lookalikes.
     *
     * @param string $body     - Inert test statements containing the candidate expectation.
     * @param int    $expected - Number of assertion-free findings.
     * @return void
     */
    #[DataProvider('expectationCases')]
    public function testObservableFrameworkExpectations(string $body, int $expected): void
    {
        $unit = $this->unit('class ExampleTest extends \PHPUnit\Framework\TestCase { public function testEntry(): void { ' . $body . ' } }');
        self::assertCount($expected, (new NoAssertionsRule())->analyse($unit, $this->context()));
    }

    /**
     * Supplies observed deprecation, Prophecy and fluent JSON shapes with binding controls.
     *
     * @return iterable<string, array{string, int}> - Complete expectation bodies and warning counts.
     */
    public static function expectationCases(): iterable
    {
        yield 'Symfony deprecation message' => ['$this->expectUserDeprecationMessage("deprecated"); run();', 0];
        yield 'Prophecy once expectation' => ['$prophecy->send()->shouldBeCalledOnce(); run($prophecy->reveal());', 0];
        yield 'Prophecy negative expectation' => ['$prophecy->send()->shouldNotHaveBeenCalled();', 0];
        yield 'stored Prophecy callable is not invoked' => ['$check = $prophecy->send()->shouldBeCalledOnce(...);', 1];
        yield 'fluent JSON value' => ['$json = \Illuminate\Testing\Fluent\AssertableJson::fromArray(["value" => 1]); $json->where("value", 1);', 0];
        yield 'fluent JSON containment' => ['$json = \Illuminate\Testing\Fluent\AssertableJson::fromArray(["value" => [1]]); $json->whereContains("value", 1);', 0];
        yield 'foreign fluent builder' => ['$json = \Other\AssertableJson::fromArray(["value" => 1]); $json->where("value", 1);', 1];
        yield 'rebound fluent receiver' => ['$json = \Illuminate\Testing\Fluent\AssertableJson::fromArray(["value" => 1]); $json = $foreign; $json->where("value", 1);', 1];
        yield 'uncalled fluent callback' => ['$json = \Illuminate\Testing\Fluent\AssertableJson::fromArray(["value" => 1]); $callback = function () use ($json) { $json->where("value", 1); };', 1];
        yield 'stored fluent callable is not invoked' => ['$json = \Illuminate\Testing\Fluent\AssertableJson::fromArray(["value" => 1]); $check = $json->where(...);', 1];
        yield 'builder without a check' => ['$json = \Illuminate\Testing\Fluent\AssertableJson::fromArray(["value" => 1]);', 1];
    }

    /**
     * Keeps repeated anonymous failures distinct from already identifiable or single-execution checks.
     *
     * @param string $body     - Inert loop source containing the candidate assertion.
     * @param int    $expected - Number of loop assertions that still need case context.
     * @return void
     */
    #[DataProvider('loopContextCases')]
    public function testLoopFailureContext(string $body, int $expected): void
    {
        $unit = $this->unit('class ExampleTest extends \PHPUnit\Framework\TestCase { public function testEntry(): void { ' . $body . ' } }');
        self::assertCount($expected, (new LoopAssertionWithoutMessageRule())->analyse($unit, $this->context()));
    }

    /**
     * Supplies bound-value diagnostics, singleton and exit cases beside repeated-failure controls.
     *
     * @return iterable<string, array{string, int}> - Loop bodies and expected context-warning counts.
     */
    public static function loopContextCases(): iterable
    {
        yield 'expected row value identifies failure' => ['foreach ($rows as $expected) { self::assertSame($expected, calculate()); }', 0];
        yield 'asserted row key identifies failure' => ['foreach ($rows as $key => $value) { self::assertArrayHasKey($key, $actual); }', 0];
        yield 'value alone omits keyed identity' => ['foreach ($rows as $key => $value) { self::assertSame($value, lookup($key)); }', 1];
        yield 'singleton has one execution' => ['foreach (["pending"] as $status) { self::assertSame("paid", $status); }', 0];
        yield 'assertion exits retry loop' => ['while ($retry) { self::assertTrue($ready); break; }', 0];
        yield 'repeated Boolean failure lacks context' => ['foreach ($rows as $row) { self::assertTrue($row); }', 1];
        yield 'conditional exit does not limit assertion count' => ['foreach ($rows as $row) { self::assertTrue($row); if ($stop) { break; } }', 1];
        yield 'inner exit cannot clear outer loop' => ['foreach ($rows as $row) { foreach ($values as $value) { self::assertTrue($ready); break; } }', 1];
    }

    /**
     * Parses inert PHP with the same name and parent links the production rules consume.
     *
     * @param string $source - Fixture declarations without the PHP opening tag.
     * @param string $path   - Display path controlling test-role evidence.
     * @return AnalysisUnit - Parsed source whose methods are never executed.
     */
    private function unit(string $source, string $path = 'tests/ExampleTest.php'): AnalysisUnit
    {
        $source     = '<?php ' . $source;
        $parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser  = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor());
        $statements = array_values($parser->parse($source) ?? []);
        // These visitors attach name and parent evidence to the existing statements without replacing them.
        $traverser->traverse($statements);

        return new AnalysisUnit(new SourceFile(__FILE__, $path), $source, $statements, [], []);
    }

    /**
     * Uses registry defaults so severity and eligibility reflect a normal scan.
     *
     * @return RuleContext - Default rule settings rooted at this test directory.
     */
    private function context(): RuleContext
    {
        return new RuleContext(__DIR__, AnalysisConfig::fromRegistry(RuleRegistry::defaults()));
    }
}
