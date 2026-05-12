<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Complexity;

use GruffPhp\Config\AnalysisConfig;
use GruffPhp\Config\RuleSettings;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Rule\Complexity\CognitiveComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Source\SourceFile;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CognitiveComplexityRuleTest extends TestCase
{
    private CognitiveComplexityRule $rule;
    private PhpFileParser $parser;

    protected function setUp(): void
    {
        $this->rule = new CognitiveComplexityRule();
        $this->parser = new PhpFileParser();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function methodCcProvider(): array
    {
        return [
            'flat method' => ['flat', 0],
            'single if' => ['oneIf', 1],
            'nested if/else' => ['nestedIf', 4],
            'boolean chain switching' => ['booleanChain', 3],
            'same operator chain' => ['sameOperatorChain', 2],
            'switch only' => ['switchOnly', 1],
            'deeply nested' => ['deeplyNested', 11],
        ];
    }

    #[DataProvider('methodCcProvider')]
    public function testCognitiveCountMatchesExpected(string $methodName, int $expectedCc): void
    {
        $unit = $this->parseFixture('cognitive.php');
        $finder = new NodeFinder();
        $methods = $finder->findInstanceOf($unit->statements, ClassMethod::class);

        $method = null;

        foreach ($methods as $m) {
            if ($m->name->toString() === $methodName) {
                $method = $m;

                break;
            }
        }

        self::assertNotNull($method, sprintf('Method %s not found in fixture.', $methodName));
        self::assertSame($expectedCc, CognitiveComplexityRule::compute($method));
    }

    public function testNoFindingsForSimpleMethods(): void
    {
        $findings = $this->analyse('simple.php', ['warning' => 15, 'error' => 30]);

        self::assertSame([], $findings);
    }

    public function testWarningForHighCognitiveComplexity(): void
    {
        $findings = $this->analyse('cognitive.php', ['warning' => 2, 'error' => 30]);

        self::assertNotSame([], $findings);

        $symbols = array_map(static fn ($f) => $f->symbol, $findings);
        self::assertContains('CognitiveFixture::deeplyNested()', $symbols);
    }

    public function testBooleanChainCollapsing(): void
    {
        $unit = $this->parseFixture('cognitive.php');
        $finder = new NodeFinder();
        $methods = $finder->findInstanceOf($unit->statements, ClassMethod::class);

        $sameChain = null;
        $mixedChain = null;

        foreach ($methods as $m) {
            if ($m->name->toString() === 'sameOperatorChain') {
                $sameChain = $m;
            }

            if ($m->name->toString() === 'booleanChain') {
                $mixedChain = $m;
            }
        }

        self::assertNotNull($sameChain);
        self::assertNotNull($mixedChain);
        self::assertSame(2, CognitiveComplexityRule::compute($sameChain));
        self::assertSame(3, CognitiveComplexityRule::compute($mixedChain));
    }

    /**
     * @param array<string, int> $thresholds
     * @return list<\GruffPhp\Finding\Finding>
     */
    private function analyse(string $fixture, array $thresholds): array
    {
        $unit = $this->parseFixture($fixture);
        $registry = RuleRegistry::defaults();
        $config = AnalysisConfig::fromRegistry($registry)->withRuleSettings(
            CognitiveComplexityRule::ID,
            new RuleSettings(true, $thresholds),
        );

        return $this->rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));
    }

    private function parseFixture(string $filename): \GruffPhp\Parser\AnalysisUnit
    {
        $path = __DIR__ . '/../../Fixtures/Complexity/' . $filename;

        return $this->parser->parse(new SourceFile($path, 'tests/Fixtures/Complexity/' . $filename));
    }
}
